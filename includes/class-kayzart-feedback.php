<?php
/**
 * Optional product feedback survey for Kayzart.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the survey and sends explicitly submitted answers to Kayzart.
 */
class Feedback {
	public const SURVEY_VERSION = 'v1';
	public const ENDPOINT       = 'https://feedback.kayzart.com/v1/responses';
	public const STATE_META_KEY = 'kayzart_feedback_v1_state';
	public const DRAFT_META_KEY = 'kayzart_feedback_v1_draft';

	private const SUBMIT_ACTION = 'kayzart_feedback_submit';
	private const INVITE_ACTION = 'kayzart_feedback_invite';
	private const SUBMIT_NONCE  = 'kayzart_feedback_submit_v1';
	private const INVITE_NONCE  = 'kayzart_feedback_invite_v1';
	private const REMIND_AFTER  = 7 * DAY_IN_SECONDS;
	private const COMMENT_LIMIT = 1000;

	/** Register survey hooks. */
	public static function init(): void {
		add_filter( 'kayzart_settings_tabs', array( __CLASS__, 'add_settings_tab' ), 5 );
		add_action( 'kayzart_render_settings_tab_feedback', array( __CLASS__, 'render_settings_tab' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::INVITE_ACTION, array( __CLASS__, 'handle_invite_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
	}

	/**
	 * Add the core feedback tab after Basic settings.
	 *
	 * @param array<string,string> $tabs Registered settings tabs.
	 * @return array<string,string>
	 */
	public static function add_settings_tab( array $tabs ): array {
		$ordered = array();
		foreach ( $tabs as $id => $label ) {
			$ordered[ $id ] = $label;
			if ( 'basic' === $id ) {
				$ordered['feedback'] = __( 'Feedback', 'kayzart-live-code-editor' );
			}
		}
		if ( ! isset( $ordered['feedback'] ) ) {
			$ordered['feedback'] = __( 'Feedback', 'kayzart-live-code-editor' );
		}
		return $ordered;
	}

	/** Load the small progressive-enhancement script only on the survey tab. */
	public static function enqueue_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		if ( Admin::SETTINGS_SLUG !== $page || 'feedback' !== $tab ) {
			return;
		}

		wp_enqueue_script(
			'kayzart-feedback',
			KAYZART_URL . 'assets/admin/feedback.js',
			array(),
			self::asset_version( KAYZART_PATH . 'assets/admin/feedback.js' ),
			true
		);
		wp_enqueue_style(
			'kayzart-feedback',
			KAYZART_URL . 'assets/admin/feedback.css',
			array(),
			self::asset_version( KAYZART_PATH . 'assets/admin/feedback.css' )
		);
	}

	/**
	 * Render the invitation below the Add new form when the user is eligible.
	 *
	 * @param string $post_type Post type selected on the Add new screen.
	 */
	public static function render_invite_card( string $post_type = Post_Type::PAGE_TYPE ): void {
		if ( ! self::should_show_invite() ) {
			return;
		}

		$state       = self::get_state();
		$is_reminder = 'postponed' === ( $state['status'] ?? '' );
		$survey_url  = Admin::get_settings_url( 'feedback' );

		echo '<aside class="kayzart-feedbackInvite" aria-labelledby="kayzart-feedback-invite-title">';
		echo '<div><h2 id="kayzart-feedback-invite-title">' . esc_html__( 'Help shape the future of Kayzart', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Share how you use Kayzart and what you would like us to build next. The optional survey takes about two minutes.', 'kayzart-live-code-editor' ) . '</p></div>';
		echo '<div class="kayzart-feedbackInvite__actions">';
		echo '<a class="button button-primary" href="' . esc_url( $survey_url ) . '">' . esc_html__( 'Take the survey', 'kayzart-live-code-editor' ) . '</a>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::INVITE_ACTION ) . '" />';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( $post_type ) . '" />';
		wp_nonce_field( self::INVITE_NONCE );
		if ( ! $is_reminder ) {
			echo '<button class="button" type="submit" name="decision" value="postpone">' . esc_html__( 'Remind me in 7 days', 'kayzart-live-code-editor' ) . '</button>';
		}
		echo '<button class="button-link" type="submit" name="decision" value="dismiss">' . esc_html__( 'Do not show again', 'kayzart-live-code-editor' ) . '</button>';
		echo '</form></div></aside>';
	}

	/** Determine whether the current administrator should see the invite. */
	public static function should_show_invite(): bool {
		if ( ! current_user_can( 'manage_options' ) || ! self::has_kayzart_content() ) {
			return false;
		}

		$state  = self::get_state();
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( in_array( $status, array( 'dismissed', 'submitted' ), true ) ) {
			return false;
		}
		if ( 'postponed' === $status ) {
			$remind_after = isset( $state['remind_after'] ) ? absint( $state['remind_after'] ) : 0;
			return 0 < $remind_after && time() >= $remind_after;
		}
		return true;
	}

	/** Check for at least one current or legacy Kayzart-managed post. */
	public static function has_kayzart_content(): bool {
		if ( Post_Type::has_legacy_posts() ) {
			return true;
		}

		$post_ids = get_posts(
			array(
				'post_type'      => array_keys( Post_Type::get_selectable_post_types() ),
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => Post_Type::ENABLED_META,
				'meta_value'     => '1',
			)
		);
		return ! empty( $post_ids );
	}

	/** Handle postpone and dismiss actions without contacting the external service. */
	public static function handle_invite_action(): void {
		self::require_admin();
		check_admin_referer( self::INVITE_NONCE );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$state    = self::get_state();
		if ( 'postpone' === $decision && 'postponed' !== ( $state['status'] ?? '' ) ) {
			self::set_state(
				array(
					'status'       => 'postponed',
					'remind_after' => time() + self::REMIND_AFTER,
					'updated_at'   => time(),
				)
			);
		} else {
			self::set_state(
				array(
					'status'     => 'dismissed',
					'updated_at' => time(),
				)
			);
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_type'] ) ) : Post_Type::PAGE_TYPE;
		wp_safe_redirect( Admin::get_new_screen_url( $post_type ) );
		exit;
	}

	/** Render the complete feedback form or its submitted state. */
	public static function render_settings_tab(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$state = self::get_state();
		echo '<div class="kayzart-feedbackPage">';
		if ( 'submitted' === ( $state['status'] ?? '' ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Thank you. Your feedback has been sent to Kayzart.', 'kayzart-live-code-editor' ) . '</p></div>';
			self::render_privacy_links();
			echo '</div>';
			return;
		}

		self::render_result_notice();
		$draft = self::take_draft();
		if ( empty( $draft['submission_id'] ) ) {
			$draft['submission_id'] = wp_generate_uuid4();
		}

		echo '<h2>' . esc_html__( 'Help shape the future of Kayzart', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'This optional survey takes about two minutes. Your answers will help us decide what to build for Kayzart and Kayzart Pro.', 'kayzart-live-code-editor' ) . '</p>';
		echo '<form class="kayzart-feedbackForm" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SUBMIT_ACTION ) . '" />';
		echo '<input type="hidden" name="submission_id" value="' . esc_attr( (string) $draft['submission_id'] ) . '" />';
		wp_nonce_field( self::SUBMIT_NONCE );

		self::render_radio_group( 'role', __( 'Which best describes you?', 'kayzart-live-code-editor' ), self::roles(), (string) ( $draft['role'] ?? '' ), true );
		self::render_checkbox_group( 'usage_targets', __( 'Where do you use Kayzart?', 'kayzart-live-code-editor' ), self::usage_targets(), self::draft_array( $draft, 'usage_targets' ), 3, true );
		self::render_checkbox_group( 'use_cases', __( 'What do you mainly want to create?', 'kayzart-live-code-editor' ), self::use_cases(), self::draft_array( $draft, 'use_cases' ), 3, true );
		self::render_radio_group( 'primary_problem', __( 'What is your biggest challenge today?', 'kayzart-live-code-editor' ), self::problems(), (string) ( $draft['primary_problem'] ?? '' ), true );
		self::render_checkbox_group( 'pro_priorities', __( 'Which Pro features would be most valuable?', 'kayzart-live-code-editor' ), self::pro_priorities(), self::draft_array( $draft, 'pro_priorities' ), 3, true );
		self::render_radio_group( 'pricing_preference', __( 'Which pricing model would you prefer?', 'kayzart-live-code-editor' ), self::pricing(), (string) ( $draft['pricing_preference'] ?? '' ), false );

		$comment = isset( $draft['comment'] ) ? (string) $draft['comment'] : '';
		echo '<div class="kayzart-feedbackQuestion"><label for="kayzart-feedback-comment"><strong>' . esc_html__( 'Anything else you would like us to know?', 'kayzart-live-code-editor' ) . '</strong> <span class="description">' . esc_html__( 'Optional', 'kayzart-live-code-editor' ) . '</span></label>';
		echo '<textarea id="kayzart-feedback-comment" name="comment" rows="6" maxlength="' . esc_attr( (string) self::COMMENT_LIMIT ) . '" data-kayzart-character-limit="' . esc_attr( (string) self::COMMENT_LIMIT ) . '">' . esc_textarea( $comment ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Do not include names, email addresses, login details, or other personal information.', 'kayzart-live-code-editor' ) . ' <span data-kayzart-character-count aria-live="polite"></span></p></div>';

		echo '<div class="kayzart-feedbackDisclosure"><p>' . esc_html__( 'Your answers are sent to Kayzart only when you press the button below. Kayzart does not automatically send your site URL, email address, page content, or plugin usage.', 'kayzart-live-code-editor' ) . '</p>';
		self::render_privacy_links();
		echo '</div>';
		submit_button( __( 'Send feedback to Kayzart', 'kayzart-live-code-editor' ) );
		echo '</form></div>';
	}

	/** Validate and submit the survey. */
	public static function handle_submit(): void {
		self::require_admin();
		check_admin_referer( self::SUBMIT_NONCE );
		$raw    = is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$draft  = self::sanitize_draft( $raw );
		$result = self::prepare_submission( $raw );
		if ( is_wp_error( $result ) ) {
			update_user_meta( get_current_user_id(), self::DRAFT_META_KEY, $draft );
			self::redirect_to_feedback( 'invalid' );
		}

		$sent = self::send_submission( $result );
		if ( is_wp_error( $sent ) ) {
			update_user_meta( get_current_user_id(), self::DRAFT_META_KEY, $draft );
			self::redirect_to_feedback( 'send_failed' );
		}

		delete_user_meta( get_current_user_id(), self::DRAFT_META_KEY );
		self::set_state(
			array(
				'status'        => 'submitted',
				'submission_id' => $result['submission_id'],
				'updated_at'    => time(),
			)
		);
		self::redirect_to_feedback( 'success' );
	}

	/**
	 * Validate submitted fields and build the external payload.
	 *
	 * @param array $raw Raw form values.
	 * @return array|\WP_Error
	 */
	public static function prepare_submission( array $raw ) {
		$submission_id = isset( $raw['submission_id'] ) && is_string( $raw['submission_id'] ) ? sanitize_text_field( $raw['submission_id'] ) : '';
		if ( ! wp_is_uuid( $submission_id, 4 ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_submission_id' );
		}

		$role            = self::allowed_scalar( $raw, 'role', self::roles(), true );
		$usage_targets   = self::allowed_list( $raw, 'usage_targets', self::usage_targets(), 3 );
		$use_cases       = self::allowed_list( $raw, 'use_cases', self::use_cases(), 3 );
		$primary_problem = self::allowed_scalar( $raw, 'primary_problem', self::problems(), true );
		$pro_priorities  = self::allowed_list( $raw, 'pro_priorities', self::pro_priorities(), 3 );
		$pricing         = self::allowed_scalar( $raw, 'pricing_preference', self::pricing(), false );
		foreach ( array( $role, $usage_targets, $use_cases, $primary_problem, $pro_priorities, $pricing ) as $value ) {
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}
		if ( in_array( 'not_used_yet', $usage_targets, true ) && 1 !== count( $usage_targets ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_usage_targets' );
		}

		if ( isset( $raw['comment'] ) && ! is_string( $raw['comment'] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_comment' );
		}
		$comment = isset( $raw['comment'] ) ? sanitize_textarea_field( $raw['comment'] ) : '';
		if ( self::text_length( $comment ) > self::COMMENT_LIMIT ) {
			return new \WP_Error( 'kayzart_feedback_comment_too_long' );
		}

		return array(
			'submission_id'      => $submission_id,
			'survey_version'     => self::SURVEY_VERSION,
			'plugin_version'     => KAYZART_VERSION,
			'locale'             => sanitize_text_field( get_user_locale() ),
			'role'               => $role,
			'usage_targets'      => $usage_targets,
			'use_cases'          => $use_cases,
			'primary_problem'    => $primary_problem,
			'pro_priorities'     => $pro_priorities,
			'pricing_preference' => '' === $pricing ? null : $pricing,
			'comment'            => $comment,
		);
	}

	/**
	 * Send one validated payload to the documented feedback service.
	 *
	 * @param array $payload Validated payload.
	 * @return true|\WP_Error
	 */
	public static function send_submission( array $payload ) {
		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return new \WP_Error( 'kayzart_feedback_encode_failed' );
		}

		global $wp_version;
		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'headers'             => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => sprintf( 'Kayzart/%s; WordPress/%s', KAYZART_VERSION, (string) $wp_version ),
				),
				'body'                => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'kayzart_feedback_request_failed' );
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! in_array( $status, array( 200, 201 ), true ) || ! is_array( $data ) || true !== ( $data['ok'] ?? false ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_response' );
		}
		if ( ! isset( $data['submissionId'] ) || $payload['submission_id'] !== (string) $data['submissionId'] ) {
			return new \WP_Error( 'kayzart_feedback_response_mismatch' );
		}
		return true;
	}

	/** Add suggested site privacy policy text. */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'Kayzart offers administrators an optional product feedback survey. Answers are sent to feedback.kayzart.com only when an administrator submits the form. Kayzart does not automatically include the site URL, administrator email address, page content, or plugin usage. The receiving web server may process IP addresses in security logs, and submitted answers are retained for two years.', 'kayzart-live-code-editor' ) . '</p>';
		$content .= '<p><a href="https://kayzart.com/privacy-policy/">' . esc_html__( 'Kayzart Privacy Policy', 'kayzart-live-code-editor' ) . '</a> | <a href="https://kayzart.com/terms/">' . esc_html__( 'Kayzart Terms', 'kayzart-live-code-editor' ) . '</a></p>';
		wp_add_privacy_policy_content( 'Kayzart', wp_kses_post( $content ) );
	}

	/**
	 * Read the current user's survey state.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_state(): array {
		$state = get_user_meta( get_current_user_id(), self::STATE_META_KEY, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Store the current user's survey state.
	 *
	 * @param array<string,mixed> $state Survey state.
	 */
	private static function set_state( array $state ): void {
		update_user_meta( get_current_user_id(), self::STATE_META_KEY, $state );
	}

	/** Require a logged-in administrator for state changes and submissions. */
	private static function require_admin(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the feedback tab with an allowlisted result code.
	 *
	 * @param string $result Result code.
	 */
	private static function redirect_to_feedback( string $result ): void {
		wp_safe_redirect( add_query_arg( 'kayzart_feedback_result', sanitize_key( $result ), Admin::get_settings_url( 'feedback' ) ) );
		exit;
	}

	/** Render a generic validation or delivery error notice. */
	private static function render_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays an allowlisted status only.
		$result = isset( $_GET['kayzart_feedback_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['kayzart_feedback_result'] ) ) : '';
		if ( 'invalid' === $result ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Please review the survey requirements and try again.', 'kayzart-live-code-editor' ) . '</p></div>';
		} elseif ( 'send_failed' === $result ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Your feedback could not be sent. Your answers have been restored so you can try again.', 'kayzart-live-code-editor' ) . '</p></div>';
		}
	}

	/**
	 * Consume the one-time form draft for the current user.
	 *
	 * @return array<string,mixed>
	 */
	private static function take_draft(): array {
		$user_id = get_current_user_id();
		$draft   = get_user_meta( $user_id, self::DRAFT_META_KEY, true );
		delete_user_meta( $user_id, self::DRAFT_META_KEY );
		return is_array( $draft ) ? $draft : array();
	}

	/**
	 * Sanitize a failed submission for one-time form restoration.
	 *
	 * @param array<string,mixed> $raw Raw form values.
	 * @return array<string,mixed>
	 */
	private static function sanitize_draft( array $raw ): array {
		$draft = array(
			'submission_id'      => isset( $raw['submission_id'] ) && is_string( $raw['submission_id'] ) ? sanitize_text_field( $raw['submission_id'] ) : wp_generate_uuid4(),
			'role'               => isset( $raw['role'] ) && is_string( $raw['role'] ) ? sanitize_key( $raw['role'] ) : '',
			'usage_targets'      => self::sanitize_key_list( $raw['usage_targets'] ?? array() ),
			'use_cases'          => self::sanitize_key_list( $raw['use_cases'] ?? array() ),
			'primary_problem'    => isset( $raw['primary_problem'] ) && is_string( $raw['primary_problem'] ) ? sanitize_key( $raw['primary_problem'] ) : '',
			'pro_priorities'     => self::sanitize_key_list( $raw['pro_priorities'] ?? array() ),
			'pricing_preference' => isset( $raw['pricing_preference'] ) && is_string( $raw['pricing_preference'] ) ? sanitize_key( $raw['pricing_preference'] ) : '',
			'comment'            => isset( $raw['comment'] ) && is_string( $raw['comment'] ) ? sanitize_textarea_field( $raw['comment'] ) : '',
		);
		if ( ! wp_is_uuid( $draft['submission_id'], 4 ) ) {
			$draft['submission_id'] = wp_generate_uuid4();
		}
		return $draft;
	}

	/**
	 * Normalize a submitted list to unique sanitized keys.
	 *
	 * @param mixed $value Submitted list.
	 * @return array<int,string>
	 */
	private static function sanitize_key_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$sanitized = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$sanitized[] = sanitize_key( $item );
		}
		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Validate one scalar choice.
	 *
	 * @param array<string,mixed>  $raw      Raw form values.
	 * @param string               $key      Field name.
	 * @param array<string,string> $allowed  Allowed values and labels.
	 * @param bool                 $required Whether an empty value is invalid.
	 * @return string|\WP_Error
	 */
	private static function allowed_scalar( array $raw, string $key, array $allowed, bool $required ) {
		if ( isset( $raw[ $key ] ) && ! is_string( $raw[ $key ] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		$value = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
		if ( '' === $value && ! $required ) {
			return '';
		}
		if ( '' === $value || ! array_key_exists( $value, $allowed ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		return $value;
	}

	/**
	 * Validate a required multiple-choice field.
	 *
	 * @param array<string,mixed>  $raw     Raw form values.
	 * @param string               $key     Field name.
	 * @param array<string,string> $allowed Allowed values and labels.
	 * @param int                  $maximum Maximum number of choices.
	 * @return array<int,string>|\WP_Error
	 */
	private static function allowed_list( array $raw, string $key, array $allowed, int $maximum ) {
		if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		foreach ( $raw[ $key ] as $raw_value ) {
			if ( ! is_string( $raw_value ) ) {
				return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
			}
		}
		$values = self::sanitize_key_list( $raw[ $key ] ?? array() );
		if ( empty( $values ) || count( $values ) > $maximum ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		foreach ( $values as $value ) {
			if ( ! array_key_exists( $value, $allowed ) ) {
				return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
			}
		}
		return $values;
	}

	/**
	 * Count characters with a PHP 7.4-safe fallback when mbstring is unavailable.
	 *
	 * @param string $value Text to count.
	 */
	private static function text_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $value ) : strlen( $value );
	}

	/**
	 * Read an array field from a restored draft.
	 *
	 * @param array<string,mixed> $draft Restored draft.
	 * @param string              $key   Field name.
	 * @return array<int,string>
	 */
	private static function draft_array( array $draft, string $key ): array {
		return isset( $draft[ $key ] ) && is_array( $draft[ $key ] ) ? array_map( 'strval', $draft[ $key ] ) : array();
	}

	/**
	 * Render one accessible radio group.
	 *
	 * @param string               $name     Field name.
	 * @param string               $legend   Question text.
	 * @param array<string,string> $options  Values and labels.
	 * @param string               $selected Selected value.
	 * @param bool                 $required Whether a choice is required.
	 */
	private static function render_radio_group( string $name, string $legend, array $options, string $selected, bool $required ): void {
		echo '<fieldset class="kayzart-feedbackQuestion"><legend><strong>' . esc_html( $legend ) . '</strong>';
		if ( ! $required ) {
			echo ' <span class="description">' . esc_html__( 'Optional', 'kayzart-live-code-editor' ) . '</span>';
		}
		echo '</legend><div class="kayzart-feedbackChoices">';
		foreach ( $options as $value => $label ) {
			$id = 'kayzart-feedback-' . $name . '-' . $value;
			echo '<label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $selected, $value, false ) . ( $required ? ' required' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div></fieldset>';
	}

	/**
	 * Render one accessible checkbox group.
	 *
	 * @param string               $name     Field name.
	 * @param string               $legend   Question text.
	 * @param array<string,string> $options  Values and labels.
	 * @param array<int,string>    $selected Selected values.
	 * @param int                  $maximum  Maximum choices.
	 * @param bool                 $required Whether at least one choice is required.
	 */
	private static function render_checkbox_group( string $name, string $legend, array $options, array $selected, int $maximum, bool $required ): void {
		echo '<fieldset class="kayzart-feedbackQuestion" data-kayzart-max-choices="' . esc_attr( (string) $maximum ) . '"><legend><strong>' . esc_html( $legend ) . '</strong> <span class="description">';
		/* translators: %d: maximum number of choices. */
		echo esc_html( sprintf( __( 'Choose up to %d.', 'kayzart-live-code-editor' ), $maximum ) ) . '</span></legend><div class="kayzart-feedbackChoices">';
		foreach ( $options as $value => $label ) {
			$id = 'kayzart-feedback-' . $name . '-' . $value;
			echo '<label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $value ) . '"' . checked( in_array( $value, $selected, true ), true, false ) . ( $required ? ' data-kayzart-required-group="true"' : '' ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div><p class="description"><span data-kayzart-choice-count aria-live="polite"></span></p></fieldset>';
	}

	/** Render the public privacy and terms links. */
	private static function render_privacy_links(): void {
		echo '<p class="description"><a href="https://kayzart.com/privacy-policy/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'kayzart-live-code-editor' ) . '</a> · <a href="https://kayzart.com/terms/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms', 'kayzart-live-code-editor' ) . '</a></p>';
	}

	/**
	 * Resolve an asset version with a plugin-version fallback.
	 *
	 * @param string $path Absolute asset path.
	 */
	private static function asset_version( string $path ): string {
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		return false === $mtime ? KAYZART_VERSION : (string) $mtime;
	}

	/**
	 * Return translated respondent roles.
	 *
	 * @return array<string,string>
	 */
	private static function roles(): array {
		return array(
			'own_site_owner' => __( 'I run my own website', 'kayzart-live-code-editor' ),
			'in_house'       => __( 'In-house web or marketing team', 'kayzart-live-code-editor' ),
			'freelancer'     => __( 'Freelancer', 'kayzart-live-code-editor' ),
			'agency'         => __( 'Web agency', 'kayzart-live-code-editor' ),
			'developer'      => __( 'Developer', 'kayzart-live-code-editor' ),
			'learning'       => __( 'Learning or evaluation', 'kayzart-live-code-editor' ),
			'other'          => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated usage targets.
	 *
	 * @return array<string,string>
	 */
	private static function usage_targets(): array {
		return array(
			'own_or_company'   => __( 'My own or my company\'s website', 'kayzart-live-code-editor' ),
			'client'           => __( 'Client websites', 'kayzart-live-code-editor' ),
			'test_development' => __( 'Test or development environments', 'kayzart-live-code-editor' ),
			'not_used_yet'     => __( 'I have not used it on a real project yet', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated use cases.
	 *
	 * @return array<string,string>
	 */
	private static function use_cases(): array {
		return array(
			'landing_page'       => __( 'Product or service landing pages', 'kayzart-live-code-editor' ),
			'campaign'           => __( 'Campaign pages', 'kayzart-live-code-editor' ),
			'corporate'          => __( 'Corporate website pages', 'kayzart-live-code-editor' ),
			'recruiting_event'   => __( 'Recruiting or event pages', 'kayzart-live-code-editor' ),
			'ai_html_wordpress'  => __( 'Bringing AI-generated HTML into WordPress', 'kayzart-live-code-editor' ),
			'template_migration' => __( 'Migrating existing HTML templates', 'kayzart-live-code-editor' ),
			'other'              => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated current problems.
	 *
	 * @return array<string,string>
	 */
	private static function problems(): array {
		return array(
			'design'                => __( 'Creating the design', 'kayzart-live-code-editor' ),
			'ai_prompting'          => __( 'Writing effective AI instructions', 'kayzart-live-code-editor' ),
			'html_css'              => __( 'Editing HTML or CSS', 'kayzart-live-code-editor' ),
			'responsive'            => __( 'Responsive design', 'kayzart-live-code-editor' ),
			'wordpress_integration' => __( 'Integrating pages with WordPress', 'kayzart-live-code-editor' ),
			'reuse'                 => __( 'Reusing pages or sections', 'kayzart-live-code-editor' ),
			'analysis'              => __( 'Improving pages after publication', 'kayzart-live-code-editor' ),
			'client_collaboration'  => __( 'Working with clients', 'kayzart-live-code-editor' ),
			'other'                 => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated Pro priorities.
	 *
	 * @return array<string,string>
	 */
	private static function pro_priorities(): array {
		return array(
			'advanced_ai_generation' => __( 'Advanced AI page generation', 'kayzart-live-code-editor' ),
			'ai_chat_editing'        => __( 'Ongoing AI chat editing', 'kayzart-live-code-editor' ),
			'template_library'       => __( 'Template library', 'kayzart-live-code-editor' ),
			'reusable_components'    => __( 'Reusable sections and components', 'kayzart-live-code-editor' ),
			'version_history'        => __( 'Version history', 'kayzart-live-code-editor' ),
			'multi_site'             => __( 'Use on multiple sites', 'kayzart-live-code-editor' ),
			'team_client_sharing'    => __( 'Team and client sharing', 'kayzart-live-code-editor' ),
			'forms'                  => __( 'Form features', 'kayzart-live-code-editor' ),
			'ab_testing_analytics'   => __( 'A/B testing and analytics', 'kayzart-live-code-editor' ),
			'import_export'          => __( 'Import and export', 'kayzart-live-code-editor' ),
			'other'                  => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated pricing preferences.
	 *
	 * @return array<string,string>
	 */
	private static function pricing(): array {
		return array(
			'single_site_annual' => __( 'Annual plan for one site', 'kayzart-live-code-editor' ),
			'multi_site_annual'  => __( 'Annual plan for multiple sites', 'kayzart-live-code-editor' ),
			'monthly'            => __( 'Monthly plan', 'kayzart-live-code-editor' ),
			'lifetime'           => __( 'One-time purchase', 'kayzart-live-code-editor' ),
			'usage_based'        => __( 'Usage-based pricing', 'kayzart-live-code-editor' ),
			'unsure'             => __( 'Not sure yet', 'kayzart-live-code-editor' ),
		);
	}
}
