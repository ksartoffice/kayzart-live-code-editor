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
 *
 * Every question is optional: one answered question is enough to submit.
 * Nothing leaves the site until an administrator presses the submit button.
 */
class Feedback {
	public const SURVEY_VERSION    = 'v1';
	public const ENDPOINT          = 'https://feedback.kayzart.com/v1/responses';
	public const SURVEY_END        = '2027-08-31T23:59:59+00:00';
	public const STATE_META_KEY    = 'kayzart_feedback_v1_state';
	public const DRAFT_META_KEY    = 'kayzart_feedback_v1_draft';
	public const RESPONSE_META_KEY = 'kayzart_feedback_v1_response';
	public const PENDING_META_KEY  = 'kayzart_feedback_v1_pending';
	public const CLOSED_OPTION     = 'kayzart_feedback_v1_closed';
	public const CONTENT_TRANSIENT = 'kayzart_feedback_has_content';

	private const SUBMIT_ACTION = 'kayzart_feedback_submit';
	private const INVITE_ACTION = 'kayzart_feedback_invite';
	private const CLEAR_ACTION  = 'kayzart_feedback_clear';
	private const SUBMIT_NONCE  = 'kayzart_feedback_submit_v1';
	private const INVITE_NONCE  = 'kayzart_feedback_invite_v1';
	private const CLEAR_NONCE   = 'kayzart_feedback_clear_v1';
	private const REMIND_AFTER  = 7 * DAY_IN_SECONDS;
	private const COMMENT_LIMIT = 1000;
	private const OTHER_LIMIT   = 100;

	/** Register survey hooks. */
	public static function init(): void {
		add_filter( 'kayzart_settings_tabs', array( __CLASS__, 'add_settings_tab' ), 5 );
		add_action( 'kayzart_render_settings_tab_feedback', array( __CLASS__, 'render_settings_tab' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_' . self::INVITE_ACTION, array( __CLASS__, 'handle_invite_action' ) );
		add_action( 'admin_post_' . self::CLEAR_ACTION, array( __CLASS__, 'handle_clear_action' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_closed_notice' ) );
		add_action( 'save_post', array( __CLASS__, 'flush_content_cache' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_content_cache' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'flush_content_cache_for_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'flush_content_cache_for_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'flush_content_cache_for_meta' ), 10, 3 );
	}

	/**
	 * Whether the survey campaign still accepts answers.
	 *
	 * The end date is baked into the plugin so an installation that is never
	 * updated stops offering the survey on its own, with no network call.
	 */
	public static function is_open(): bool {
		if ( '' !== (string) get_option( self::CLOSED_OPTION, '' ) ) {
			return false;
		}

		$end = strtotime( self::SURVEY_END );
		return false !== $end && time() <= $end;
	}

	/**
	 * Add the core feedback tab after Basic settings while the survey runs.
	 *
	 * @param array<string,string> $tabs Registered settings tabs.
	 * @return array<string,string>
	 */
	public static function add_settings_tab( array $tabs ): array {
		if ( ! self::is_open() ) {
			return $tabs;
		}

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
		if ( Admin::SETTINGS_SLUG !== $page || 'feedback' !== $tab || ! self::is_open() ) {
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
		echo '<p>' . esc_html__( 'Share how you use Kayzart and what you would like us to build next. The survey takes about three minutes.', 'kayzart-live-code-editor' ) . '</p></div>';
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

	/**
	 * Determine whether the current administrator should see the invite.
	 *
	 * The cheap checks run first so a user who already answered or dismissed
	 * the survey never pays for the managed-content lookup.
	 */
	public static function should_show_invite(): bool {
		if ( ! self::is_open() ) {
			return false;
		}

		$state  = self::get_state();
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( in_array( $status, array( 'dismissed', 'submitted' ), true ) ) {
			return false;
		}
		if ( 'postponed' === $status ) {
			$remind_after = isset( $state['remind_after'] ) ? absint( $state['remind_after'] ) : 0;
			if ( 0 === $remind_after || time() < $remind_after ) {
				return false;
			}
		}

		return current_user_can( 'manage_options' ) && self::has_kayzart_content();
	}

	/** Check for at least one current or legacy Kayzart-managed post. */
	public static function has_kayzart_content(): bool {
		$cached = get_transient( self::CONTENT_TRANSIENT );
		if ( '1' === $cached ) {
			return true;
		}
		if ( '0' === $cached ) {
			return false;
		}

		$has = self::query_kayzart_content();
		set_transient( self::CONTENT_TRANSIENT, $has ? '1' : '0', $has ? DAY_IN_SECONDS : 5 * MINUTE_IN_SECONDS );
		return $has;
	}

	/** Drop the cached managed-content answer. */
	public static function flush_content_cache(): void {
		delete_transient( self::CONTENT_TRANSIENT );
	}

	/**
	 * Drop the cached answer when Kayzart management is toggled on a post.
	 *
	 * @param int|array $meta_id   Meta row ID or IDs.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 */
	public static function flush_content_cache_for_meta( $meta_id, $object_id, $meta_key ): void {
		unset( $meta_id, $object_id );
		if ( Post_Type::ENABLED_META === $meta_key ) {
			self::flush_content_cache();
		}
	}

	/**
	 * Handle postpone and dismiss actions without contacting the external service.
	 *
	 * An invite rendered before the survey was answered or dismissed stays
	 * submittable in another tab, so a stale decision must never revive a
	 * settled survey. Each decision is handled on its own, so repeating one
	 * request — a double click, a resubmitted form — repeats its own outcome
	 * rather than escalating a reminder into a permanent dismissal.
	 */
	public static function handle_invite_action(): void {
		self::require_admin();
		check_admin_referer( self::INVITE_NONCE );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$state    = self::get_state();
		$status   = isset( $state['status'] ) ? (string) $state['status'] : '';
		$settled  = in_array( $status, array( 'dismissed', 'submitted' ), true );

		if ( ! $settled ) {
			if ( 'postpone' === $decision ) {
				if ( 'postponed' !== $status ) {
					self::set_state(
						array(
							'status'       => 'postponed',
							'remind_after' => time() + self::REMIND_AFTER,
							'updated_at'   => time(),
						)
					);
				}
			} else {
				self::set_state(
					array(
						'status'     => 'dismissed',
						'updated_at' => time(),
					)
				);
			}
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['post_type'] ) ) : Post_Type::PAGE_TYPE;
		wp_safe_redirect( Admin::get_new_screen_url( $post_type ) );
		exit;
	}

	/** Discard a restored draft so the form starts from the sent answers again. */
	public static function handle_clear_action(): void {
		self::require_admin();
		check_admin_referer( self::CLEAR_NONCE );

		delete_user_meta( get_current_user_id(), self::DRAFT_META_KEY );
		self::redirect_to_feedback( '' );
	}

	/** Render the survey form, prefilled from a kept draft or a sent answer. */
	public static function render_settings_tab(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_open() ) {
			return;
		}

		$state     = self::get_state();
		$submitted = 'submitted' === ( $state['status'] ?? '' );
		$draft     = self::get_draft();
		$has_draft = ! empty( $draft );
		$answers   = $has_draft ? $draft : self::get_stored_response();

		$submission_id = isset( $answers['submission_id'] ) ? (string) $answers['submission_id'] : '';
		if ( ! wp_is_uuid( $submission_id, 4 ) ) {
			$submission_id = self::pending_submission_id();
		}

		echo '<div class="kayzart-feedbackPage">';
		self::render_result_notice();
		if ( $submitted ) {
			self::render_submitted_notice( $state );
		}

		echo '<h2>' . esc_html__( 'Help shape the future of Kayzart', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Your answers help us decide what to build for Kayzart and Kayzart Pro.', 'kayzart-live-code-editor' ) . '</p>';
		echo '<form class="kayzart-feedbackForm" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SUBMIT_ACTION ) . '" />';
		echo '<input type="hidden" name="submission_id" value="' . esc_attr( $submission_id ) . '" />';
		wp_nonce_field( self::SUBMIT_NONCE );

		self::render_radio_group(
			'role',
			__( 'Which best describes you?', 'kayzart-live-code-editor' ),
			self::roles(),
			self::answer_string( $answers, 'role' ),
			'',
			array(
				'name'  => 'role_other',
				'value' => self::answer_string( $answers, 'role_other' ),
			)
		);

		self::render_checkbox_group(
			'usage_targets',
			__( 'Where do you use Kayzart?', 'kayzart-live-code-editor' ),
			self::usage_targets(),
			self::answer_list( $answers, 'usage_targets' ),
			__( 'Select any that apply.', 'kayzart-live-code-editor' )
		);

		self::render_checkbox_group(
			'use_cases',
			__( 'What do you mainly want to create?', 'kayzart-live-code-editor' ),
			self::use_cases(),
			self::answer_list( $answers, 'use_cases' ),
			__( 'Select any that apply; around three is ideal.', 'kayzart-live-code-editor' ),
			array(
				'name'  => 'use_cases_other',
				'value' => self::answer_string( $answers, 'use_cases_other' ),
			)
		);

		self::render_radio_group(
			'monthly_volume',
			__( 'How many landing pages do you build in a month?', 'kayzart-live-code-editor' ),
			self::monthly_volumes(),
			self::answer_string( $answers, 'monthly_volume' )
		);

		self::render_radio_group(
			'primary_problem',
			__( 'What is your biggest challenge today?', 'kayzart-live-code-editor' ),
			self::problems(),
			self::answer_string( $answers, 'primary_problem' ),
			'',
			array(
				'name'  => 'primary_problem_other',
				'value' => self::answer_string( $answers, 'primary_problem_other' ),
			)
		);

		self::render_radio_group(
			'api_key_attitude',
			__( 'How do you feel about providing your own AI API key?', 'kayzart-live-code-editor' ),
			self::api_key_attitudes(),
			self::answer_string( $answers, 'api_key_attitude' )
		);

		self::render_checkbox_group(
			'pro_priorities',
			__( 'Which Pro features would be most valuable?', 'kayzart-live-code-editor' ),
			self::pro_priorities(),
			self::answer_list( $answers, 'pro_priorities' ),
			__( 'Select any that apply; around three is ideal.', 'kayzart-live-code-editor' ),
			array(
				'name'   => 'pro_priorities_other',
				'value'  => self::answer_string( $answers, 'pro_priorities_other' ),
				'groups' => array( 'pro_priorities', 'pro_decisive' ),
			)
		);

		self::render_radio_group(
			'pro_decisive',
			__( 'Which single feature would actually decide a purchase for you?', 'kayzart-live-code-editor' ),
			self::pro_decisive_options(),
			self::answer_string( $answers, 'pro_decisive' ),
			__( 'Pick the one that matters most, even if several sound useful.', 'kayzart-live-code-editor' )
		);

		self::render_radio_group(
			'pricing_preference',
			__( 'Which pricing model would you prefer?', 'kayzart-live-code-editor' ),
			self::pricing(),
			self::answer_string( $answers, 'pricing_preference' )
		);

		$comment = self::answer_string( $answers, 'comment' );
		echo '<div class="kayzart-feedbackQuestion"><label for="kayzart-feedback-comment"><strong>' . esc_html__( 'Anything else you would like us to know?', 'kayzart-live-code-editor' ) . '</strong></label>';
		echo '<textarea id="kayzart-feedback-comment" name="comment" rows="6" maxlength="' . esc_attr( (string) self::native_maxlength( self::COMMENT_LIMIT ) ) . '" data-kayzart-character-limit="' . esc_attr( (string) self::COMMENT_LIMIT ) . '">' . esc_textarea( $comment ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Do not include names, email addresses, login details, or other personal information.', 'kayzart-live-code-editor' ) . ' <span data-kayzart-character-count aria-live="polite"></span></p></div>';

		echo '<div class="kayzart-feedbackDisclosure"><p>' . esc_html__( 'Your answers are sent to Kayzart only when you press the button below. Kayzart does not automatically send your site URL, email address, page content, or plugin usage.', 'kayzart-live-code-editor' ) . '</p>';
		self::render_privacy_links();
		echo '</div>';

		$label = $submitted
			? __( 'Update and resend my feedback', 'kayzart-live-code-editor' )
			: __( 'Send feedback to Kayzart', 'kayzart-live-code-editor' );
		self::render_unanswered_prompt( $label );
		submit_button( $label );
		echo '</form>';

		if ( $has_draft ) {
			$clear_url = wp_nonce_url(
				add_query_arg( 'action', self::CLEAR_ACTION, admin_url( 'admin-post.php' ) ),
				self::CLEAR_NONCE
			);
			echo '<p><a class="button-link" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Discard these restored answers', 'kayzart-live-code-editor' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** Validate and submit the survey. */
	public static function handle_submit(): void {
		self::require_admin();
		check_admin_referer( self::SUBMIT_NONCE );
		if ( ! self::is_open() ) {
			wp_safe_redirect( add_query_arg( 'kayzart_feedback_result', 'closed', Admin::get_settings_url() ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each field is validated and sanitized below.
		$raw    = is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$draft  = self::sanitize_draft( $raw );
		$result = self::prepare_submission( $raw );
		if ( is_wp_error( $result ) ) {
			update_user_meta( get_current_user_id(), self::DRAFT_META_KEY, $draft );
			self::redirect_to_feedback( 'invalid', $result->get_error_code() );
		}

		$sent = self::send_submission( $result );
		if ( is_wp_error( $sent ) ) {
			if ( 'kayzart_feedback_closed' === $sent->get_error_code() ) {
				update_option( self::CLOSED_OPTION, '1', false );
				delete_user_meta( get_current_user_id(), self::DRAFT_META_KEY );
				wp_safe_redirect( add_query_arg( 'kayzart_feedback_result', 'closed', Admin::get_settings_url() ) );
				exit;
			}
			update_user_meta( get_current_user_id(), self::DRAFT_META_KEY, $draft );
			self::redirect_to_feedback( 'send_failed' );
		}

		delete_user_meta( get_current_user_id(), self::DRAFT_META_KEY );
		delete_user_meta( get_current_user_id(), self::PENDING_META_KEY );
		update_user_meta( get_current_user_id(), self::RESPONSE_META_KEY, $result );
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
	 * Every question is optional; only a completely empty form is rejected.
	 *
	 * @param array $raw Raw form values.
	 * @return array|\WP_Error
	 */
	public static function prepare_submission( array $raw ) {
		$submission_id = isset( $raw['submission_id'] ) && is_string( $raw['submission_id'] ) ? sanitize_text_field( $raw['submission_id'] ) : '';
		if ( ! wp_is_uuid( $submission_id, 4 ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_submission_id' );
		}

		$answers = array(
			'role'                  => self::optional_scalar( $raw, 'role', self::roles() ),
			'usage_targets'         => self::optional_list( $raw, 'usage_targets', self::usage_targets() ),
			'use_cases'             => self::optional_list( $raw, 'use_cases', self::use_cases() ),
			'monthly_volume'        => self::optional_scalar( $raw, 'monthly_volume', self::monthly_volumes() ),
			'primary_problem'       => self::optional_scalar( $raw, 'primary_problem', self::problems() ),
			'api_key_attitude'      => self::optional_scalar( $raw, 'api_key_attitude', self::api_key_attitudes() ),
			'pro_priorities'        => self::optional_list( $raw, 'pro_priorities', self::pro_priorities() ),
			'pro_decisive'          => self::optional_scalar( $raw, 'pro_decisive', self::pro_decisive_options() ),
			'pricing_preference'    => self::optional_scalar( $raw, 'pricing_preference', self::pricing() ),
			'role_other'            => self::optional_text( $raw, 'role_other', self::OTHER_LIMIT ),
			'use_cases_other'       => self::optional_text( $raw, 'use_cases_other', self::OTHER_LIMIT ),
			'primary_problem_other' => self::optional_text( $raw, 'primary_problem_other', self::OTHER_LIMIT ),
			'pro_priorities_other'  => self::optional_text( $raw, 'pro_priorities_other', self::OTHER_LIMIT ),
			'comment'               => self::optional_text( $raw, 'comment', self::COMMENT_LIMIT, true ),
		);
		foreach ( $answers as $value ) {
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		$answers = self::drop_orphaned_other_text( $answers );
		if ( ! self::has_any_answer( $answers ) ) {
			return new \WP_Error( 'kayzart_feedback_empty' );
		}

		$payload = array(
			'submission_id'  => $submission_id,
			'survey_version' => self::SURVEY_VERSION,
			'plugin_version' => KAYZART_VERSION,
			'locale'         => sanitize_text_field( get_user_locale() ),
		);
		foreach ( $answers as $key => $value ) {
			$payload[ $key ] = is_array( $value ) ? $value : ( '' === $value ? null : $value );
		}
		return $payload;
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
					// Replaces the WordPress default user agent, which would disclose the site URL.
					'User-Agent'   => sprintf( 'Kayzart/%s; WordPress/%s', KAYZART_VERSION, (string) $wp_version ),
				),
				'body'                => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'kayzart_feedback_request_failed' );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 410 === $status ) {
			return new \WP_Error( 'kayzart_feedback_closed' );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! in_array( $status, array( 200, 201 ), true ) || ! is_array( $data ) || true !== ( $data['ok'] ?? false ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_response' );
		}
		if ( ! isset( $data['submissionId'] ) || $payload['submission_id'] !== (string) $data['submissionId'] ) {
			return new \WP_Error( 'kayzart_feedback_response_mismatch' );
		}
		return true;
	}

	/** Explain a survey that closed while the plugin stayed installed. */
	public static function render_closed_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays an allowlisted status only.
		$result = isset( $_GET['kayzart_feedback_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['kayzart_feedback_result'] ) ) : '';
		if ( 'closed' !== $result || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>' . esc_html__( 'The Kayzart feedback survey has closed. Thank you for your interest.', 'kayzart-live-code-editor' ) . '</p></div>';
	}

	/** Add suggested site privacy policy text. */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'Kayzart offers administrators an optional product feedback survey. Answers are sent to feedback.kayzart.com only when an administrator submits the form, together with the survey version, the Kayzart and WordPress version numbers, and the administrator interface language. Kayzart does not automatically include the site URL, administrator email address, page content, or plugin usage. The receiving web server may process IP addresses in security logs, and submitted answers are retained for two years.', 'kayzart-live-code-editor' ) . '</p>';
		$content .= '<p><a href="https://kayzart.com/privacy-policy/">' . esc_html__( 'Kayzart Privacy Policy', 'kayzart-live-code-editor' ) . '</a> | <a href="https://kayzart.com/terms/">' . esc_html__( 'Kayzart Terms', 'kayzart-live-code-editor' ) . '</a></p>';
		wp_add_privacy_policy_content( 'Kayzart', wp_kses_post( $content ) );
	}

	/** Run the managed-content lookup behind the cache. */
	private static function query_kayzart_content(): bool {
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
	 * @param string $result     Result code.
	 * @param string $error_code Optional validation error code.
	 */
	private static function redirect_to_feedback( string $result, string $error_code = '' ): void {
		$url = Admin::get_settings_url( 'feedback' );
		if ( '' !== $result ) {
			$url = add_query_arg( 'kayzart_feedback_result', sanitize_key( $result ), $url );
		}
		$field = self::error_code_to_field( $error_code );
		if ( '' !== $field ) {
			$url = add_query_arg( 'kayzart_feedback_field', $field, $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Map a validation error code back to the question it belongs to.
	 *
	 * @param string $error_code WP_Error code.
	 */
	private static function error_code_to_field( string $error_code ): string {
		$prefix = 'kayzart_feedback_invalid_';
		if ( 0 !== strpos( $error_code, $prefix ) ) {
			return '';
		}
		$field = substr( $error_code, strlen( $prefix ) );
		return array_key_exists( $field, self::field_labels() ) ? $field : '';
	}

	/** Render a validation, delivery, or success notice. */
	private static function render_result_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays an allowlisted status only.
		$result = isset( $_GET['kayzart_feedback_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['kayzart_feedback_result'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Displays an allowlisted status only.
		$field  = isset( $_GET['kayzart_feedback_field'] ) ? sanitize_key( wp_unslash( (string) $_GET['kayzart_feedback_field'] ) ) : '';
		$labels = self::field_labels();

		if ( 'invalid' === $result ) {
			if ( isset( $labels[ $field ] ) ) {
				/* translators: %s: survey question title. */
				$message = sprintf( __( 'Please check your answer to "%s" and try again.', 'kayzart-live-code-editor' ), $labels[ $field ] );
			} else {
				$message = __( 'Nothing was answered yet, so there was nothing to send. Answer at least one question and try again.', 'kayzart-live-code-editor' );
			}
			echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
		} elseif ( 'send_failed' === $result ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Your feedback could not be sent. Your answers have been kept, so you can try again.', 'kayzart-live-code-editor' ) . '</p></div>';
		} elseif ( 'success' === $result ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Thank you. Your feedback has been sent to Kayzart.', 'kayzart-live-code-editor' ) . '</p></div>';
		}
	}

	/**
	 * Explain that the form is prefilled with what was already sent.
	 *
	 * @param array<string,mixed> $state Survey state.
	 */
	private static function render_submitted_notice( array $state ): void {
		$updated_at = isset( $state['updated_at'] ) ? absint( $state['updated_at'] ) : 0;
		$sent_on    = 0 < $updated_at ? wp_date( (string) get_option( 'date_format' ), $updated_at ) : '';

		echo '<div class="notice notice-info inline"><p>';
		if ( '' !== $sent_on ) {
			/* translators: %s: date the feedback was sent. */
			echo esc_html( sprintf( __( 'You sent feedback on %s. The form below shows what you sent, and you can change it and send it again.', 'kayzart-live-code-editor' ), $sent_on ) );
		} else {
			echo esc_html__( 'The form below shows the feedback you already sent, and you can change it and send it again.', 'kayzart-live-code-editor' );
		}
		echo '</p></div>';
	}

	/**
	 * Read the kept draft for the current user.
	 *
	 * The draft survives page reloads and is removed only after a successful
	 * submission or an explicit discard, so a failed send never loses answers.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_draft(): array {
		$draft = get_user_meta( get_current_user_id(), self::DRAFT_META_KEY, true );
		return is_array( $draft ) ? $draft : array();
	}

	/**
	 * Resolve the identifier a first submission will be sent under.
	 *
	 * The identifier is kept until a submission succeeds, so opening the survey
	 * in a second tab reuses it: submitting both tabs then updates one server
	 * response instead of creating a second one that cannot be corrected.
	 *
	 * Two first renders can still overlap, so the identifier is claimed with a
	 * unique insertion and the loser adopts the stored one. User meta has no
	 * unique index, so this narrows the race rather than closing it; losing it
	 * costs one extra response row, never a lost or unanswerable submission.
	 */
	private static function pending_submission_id(): string {
		$user_id = get_current_user_id();
		$stored  = self::stored_pending_id( $user_id );
		if ( '' !== $stored ) {
			return $stored;
		}

		$pending = wp_generate_uuid4();
		if ( add_user_meta( $user_id, self::PENDING_META_KEY, $pending, true ) ) {
			return $pending;
		}

		// Another render claimed the identifier first, or an unusable value is
		// stored; adopt the winner, and replace anything that is not a UUID.
		$stored = self::stored_pending_id( $user_id );
		if ( '' !== $stored ) {
			return $stored;
		}

		update_user_meta( $user_id, self::PENDING_META_KEY, $pending );
		return $pending;
	}

	/**
	 * Read the claimed submission identifier, ignoring unusable values.
	 *
	 * @param int $user_id User to read the identifier for.
	 */
	private static function stored_pending_id( int $user_id ): string {
		$pending = get_user_meta( $user_id, self::PENDING_META_KEY, true );
		return is_string( $pending ) && wp_is_uuid( $pending, 4 ) ? $pending : '';
	}

	/**
	 * Read the last payload this user sent, so it can be reviewed and updated.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_stored_response(): array {
		$response = get_user_meta( get_current_user_id(), self::RESPONSE_META_KEY, true );
		return is_array( $response ) ? $response : array();
	}

	/**
	 * Sanitize a failed submission so the form can be restored.
	 *
	 * @param array<string,mixed> $raw Raw form values.
	 * @return array<string,mixed>
	 */
	private static function sanitize_draft( array $raw ): array {
		$draft = array(
			'submission_id' => isset( $raw['submission_id'] ) && is_string( $raw['submission_id'] ) ? sanitize_text_field( $raw['submission_id'] ) : '',
		);
		foreach ( array( 'role', 'monthly_volume', 'primary_problem', 'api_key_attitude', 'pro_decisive', 'pricing_preference' ) as $key ) {
			$draft[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
		}
		foreach ( array( 'usage_targets', 'use_cases', 'pro_priorities' ) as $key ) {
			$draft[ $key ] = self::sanitize_key_list( $raw[ $key ] ?? array() );
		}
		foreach ( array( 'role_other', 'use_cases_other', 'primary_problem_other', 'pro_priorities_other' ) as $key ) {
			$draft[ $key ] = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_text_field( $raw[ $key ] ) : '';
		}
		$draft['comment'] = isset( $raw['comment'] ) && is_string( $raw['comment'] ) ? sanitize_textarea_field( $raw['comment'] ) : '';

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
	 * Validate one optional single choice.
	 *
	 * @param array<string,mixed>  $raw     Raw form values.
	 * @param string               $key     Field name.
	 * @param array<string,string> $allowed Allowed values and labels.
	 * @return string|\WP_Error
	 */
	private static function optional_scalar( array $raw, string $key, array $allowed ) {
		if ( isset( $raw[ $key ] ) && ! is_string( $raw[ $key ] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		$value = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
		if ( '' === $value ) {
			return '';
		}
		if ( ! array_key_exists( $value, $allowed ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		return $value;
	}

	/**
	 * Validate one optional multiple choice. Any number of listed choices is accepted.
	 *
	 * @param array<string,mixed>  $raw     Raw form values.
	 * @param string               $key     Field name.
	 * @param array<string,string> $allowed Allowed values and labels.
	 * @return array<int,string>|\WP_Error
	 */
	private static function optional_list( array $raw, string $key, array $allowed ) {
		if ( ! isset( $raw[ $key ] ) ) {
			return array();
		}
		if ( ! is_array( $raw[ $key ] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		foreach ( $raw[ $key ] as $raw_value ) {
			if ( ! is_string( $raw_value ) ) {
				return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
			}
		}

		$values = self::sanitize_key_list( $raw[ $key ] );
		foreach ( $values as $value ) {
			if ( ! array_key_exists( $value, $allowed ) ) {
				return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
			}
		}
		return $values;
	}

	/**
	 * Validate one optional free-text answer.
	 *
	 * @param array<string,mixed> $raw       Raw form values.
	 * @param string              $key       Field name.
	 * @param int                 $limit     Maximum characters.
	 * @param bool                $multiline Whether line breaks are kept.
	 * @return string|\WP_Error
	 */
	private static function optional_text( array $raw, string $key, int $limit, bool $multiline = false ) {
		if ( ! isset( $raw[ $key ] ) ) {
			return '';
		}
		if ( ! is_string( $raw[ $key ] ) ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}

		$text = $multiline ? sanitize_textarea_field( $raw[ $key ] ) : sanitize_text_field( $raw[ $key ] );
		if ( self::text_length( $text ) > $limit ) {
			return new \WP_Error( 'kayzart_feedback_invalid_' . $key );
		}
		return $text;
	}

	/**
	 * Drop free-text answers whose "Other" choice is not selected.
	 *
	 * @param array<string,mixed> $answers Validated answers.
	 * @return array<string,mixed>
	 */
	private static function drop_orphaned_other_text( array $answers ): array {
		if ( 'other' !== $answers['role'] ) {
			$answers['role_other'] = '';
		}
		if ( ! in_array( 'other', $answers['use_cases'], true ) ) {
			$answers['use_cases_other'] = '';
		}
		if ( 'other' !== $answers['primary_problem'] ) {
			$answers['primary_problem_other'] = '';
		}
		if ( ! in_array( 'other', $answers['pro_priorities'], true ) && 'other' !== $answers['pro_decisive'] ) {
			$answers['pro_priorities_other'] = '';
		}
		return $answers;
	}

	/**
	 * Check that the form is not completely empty.
	 *
	 * @param array<string,mixed> $answers Validated answers.
	 */
	private static function has_any_answer( array $answers ): bool {
		foreach ( $answers as $value ) {
			if ( is_array( $value ) ? ! empty( $value ) : '' !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Cap the browser's own maxlength well above the documented limit.
	 *
	 * The HTML attribute counts UTF-16 code units, while the counter and the
	 * server count code points, so an emoji costs twice as much to the browser
	 * as it does to the limit being enforced. Doubling the attribute keeps it a
	 * safety net against runaway pastes without ever stopping input that the
	 * counter still shows as within the limit.
	 *
	 * @param int $limit Documented character limit.
	 */
	private static function native_maxlength( int $limit ): int {
		return $limit * 2;
	}

	/**
	 * Count characters, not bytes, even when mbstring is unavailable.
	 *
	 * Counting bytes would reject roughly a third of the documented limit for
	 * Japanese or any other multi-byte answer, while the browser counts
	 * characters and shows the field as still within the limit.
	 *
	 * @param string $value Text to count.
	 */
	private static function text_length( string $value ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value );
		}

		$characters = preg_match_all( '/./us', $value );
		return false === $characters ? strlen( $value ) : (int) $characters;
	}

	/**
	 * Read a single-choice or free-text answer from restored values.
	 *
	 * @param array<string,mixed> $answers Restored values.
	 * @param string              $key     Field name.
	 */
	private static function answer_string( array $answers, string $key ): string {
		return isset( $answers[ $key ] ) && is_string( $answers[ $key ] ) ? $answers[ $key ] : '';
	}

	/**
	 * Read a multiple-choice answer from restored values.
	 *
	 * @param array<string,mixed> $answers Restored values.
	 * @param string              $key     Field name.
	 * @return array<int,string>
	 */
	private static function answer_list( array $answers, string $key ): array {
		return isset( $answers[ $key ] ) && is_array( $answers[ $key ] ) ? array_map( 'strval', $answers[ $key ] ) : array();
	}

	/**
	 * Render one accessible radio group. Answering is always optional.
	 *
	 * @param string                   $name        Field name.
	 * @param string                   $legend      Question text.
	 * @param array<string,string>     $options     Values and labels.
	 * @param string                   $selected    Selected value.
	 * @param string                   $description Optional guidance under the legend.
	 * @param array<string,mixed>|null $other       Optional free-text field definition.
	 */
	private static function render_radio_group( string $name, string $legend, array $options, string $selected, string $description = '', ?array $other = null ): void {
		echo '<fieldset class="kayzart-feedbackQuestion"><legend><strong>' . esc_html( $legend ) . '</strong></legend>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '<div class="kayzart-feedbackChoices">';
		foreach ( $options as $value => $label ) {
			$id = 'kayzart-feedback-' . $name . '-' . $value;
			echo '<label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . checked( $selected, $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		self::render_other_text( $name, $other );
		echo '</fieldset>';
	}

	/**
	 * Render one accessible checkbox group. Answering is always optional.
	 *
	 * @param string                   $name        Field name.
	 * @param string                   $legend      Question text.
	 * @param array<string,string>     $options     Values and labels.
	 * @param array<int,string>        $selected    Selected values.
	 * @param string                   $description Guidance under the legend.
	 * @param array<string,mixed>|null $other       Optional free-text field definition.
	 */
	private static function render_checkbox_group( string $name, string $legend, array $options, array $selected, string $description = '', ?array $other = null ): void {
		echo '<fieldset class="kayzart-feedbackQuestion"><legend><strong>' . esc_html( $legend ) . '</strong></legend>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '<div class="kayzart-feedbackChoices">';
		foreach ( $options as $value => $label ) {
			$id = 'kayzart-feedback-' . $name . '-' . $value;
			echo '<label for="' . esc_attr( $id ) . '"><input id="' . esc_attr( $id ) . '" type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $value ) . '"' . checked( in_array( $value, $selected, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '</div>';
		self::render_other_text( $name, $other );
		echo '</fieldset>';
	}

	/**
	 * Render the optional free-text input that belongs to an "Other" choice.
	 *
	 * One input can belong to more than one question: the description of an
	 * "Other" Pro feature is shared by the priorities and the deciding feature,
	 * so it appears as soon as either of them selects "Other". Without
	 * JavaScript the input is simply always visible.
	 *
	 * @param string                   $group_name Field name of the owning group.
	 * @param array<string,mixed>|null $other      Free-text field definition.
	 */
	private static function render_other_text( string $group_name, ?array $other ): void {
		if ( null === $other || empty( $other['name'] ) ) {
			return;
		}

		$groups = isset( $other['groups'] ) && is_array( $other['groups'] ) ? $other['groups'] : array( $group_name );
		$id     = 'kayzart-feedback-' . $other['name'];
		echo '<div class="kayzart-feedbackOther" data-kayzart-other-for="' . esc_attr( implode( ',', $groups ) ) . '" data-kayzart-other-value="other">';
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Other — please describe', 'kayzart-live-code-editor' ) . '</label> ';
		echo '<input id="' . esc_attr( $id ) . '" type="text" name="' . esc_attr( (string) $other['name'] ) . '" value="' . esc_attr( (string) ( $other['value'] ?? '' ) ) . '" maxlength="' . esc_attr( (string) self::native_maxlength( self::OTHER_LIMIT ) ) . '" data-kayzart-character-limit="' . esc_attr( (string) self::OTHER_LIMIT ) . '" class="regular-text" />';
		echo '</div>';
	}

	/**
	 * Render the soft reminder shown when questions are left unanswered.
	 *
	 * The reminder never blocks a submission: it appears once, offers to send
	 * anyway, and is skipped entirely when JavaScript is unavailable. The free
	 * comment box is not counted.
	 *
	 * @param string $submit_label Label of the primary submit button.
	 */
	private static function render_unanswered_prompt( string $submit_label ): void {
		echo '<div class="kayzart-feedbackUnanswered notice notice-warning inline" hidden>';
		echo '<p role="status" data-kayzart-unanswered-message';
		echo ' data-singular="' . esc_attr__( 'One question is still unanswered.', 'kayzart-live-code-editor' ) . '"';
		/* translators: %d: number of unanswered questions. */
		echo ' data-plural="' . esc_attr__( '%d questions are still unanswered.', 'kayzart-live-code-editor' ) . '"></p>';
		echo '<p>';
		echo '<button type="submit" class="button button-primary" data-kayzart-send-anyway>' . esc_html( $submit_label ) . '</button> ';
		echo '<button type="button" class="button" data-kayzart-review-unanswered>' . esc_html__( 'Go back to them', 'kayzart-live-code-editor' ) . '</button>';
		echo '</p></div>';
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
	 * Map field names to the question they belong to, for error messages.
	 *
	 * @return array<string,string>
	 */
	private static function field_labels(): array {
		$role     = __( 'Which best describes you?', 'kayzart-live-code-editor' );
		$targets  = __( 'Where do you use Kayzart?', 'kayzart-live-code-editor' );
		$cases    = __( 'What do you mainly want to create?', 'kayzart-live-code-editor' );
		$volume   = __( 'How many landing pages do you build in a month?', 'kayzart-live-code-editor' );
		$problem  = __( 'What is your biggest challenge today?', 'kayzart-live-code-editor' );
		$api_key  = __( 'How do you feel about providing your own AI API key?', 'kayzart-live-code-editor' );
		$features = __( 'Which Pro features would be most valuable?', 'kayzart-live-code-editor' );

		return array(
			'role'                  => $role,
			'role_other'            => $role,
			'usage_targets'         => $targets,
			'use_cases'             => $cases,
			'use_cases_other'       => $cases,
			'monthly_volume'        => $volume,
			'primary_problem'       => $problem,
			'primary_problem_other' => $problem,
			'api_key_attitude'      => $api_key,
			'pro_priorities'        => $features,
			'pro_priorities_other'  => $features,
			'pro_decisive'          => __( 'Which single feature would actually decide a purchase for you?', 'kayzart-live-code-editor' ),
			'pricing_preference'    => __( 'Which pricing model would you prefer?', 'kayzart-live-code-editor' ),
			'comment'               => __( 'Anything else you would like us to know?', 'kayzart-live-code-editor' ),
		);
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
	 * Return translated monthly landing page volumes.
	 *
	 * @return array<string,string>
	 */
	private static function monthly_volumes(): array {
		return array(
			'none'        => __( 'None yet', 'kayzart-live-code-editor' ),
			'one_two'     => __( '1 to 2', 'kayzart-live-code-editor' ),
			'three_five'  => __( '3 to 5', 'kayzart-live-code-editor' ),
			'six_ten'     => __( '6 to 10', 'kayzart-live-code-editor' ),
			'eleven_plus' => __( '11 or more', 'kayzart-live-code-editor' ),
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
	 * Return translated attitudes towards bringing your own AI API key.
	 *
	 * @return array<string,string>
	 */
	private static function api_key_attitudes(): array {
		return array(
			'comfortable' => __( 'I set up my own API key without trouble', 'kayzart-live-code-editor' ),
			'workable'    => __( 'It works, but it is a hassle', 'kayzart-live-code-editor' ),
			'blocked'     => __( 'The setup is too difficult, so I cannot use AI editing', 'kayzart-live-code-editor' ),
			'not_tried'   => __( 'I have not tried AI editing yet', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return translated Pro priorities.
	 *
	 * @return array<string,string>
	 */
	private static function pro_priorities(): array {
		return array(
			'niche_templates'      => __( 'Templates built for specific industries and purposes', 'kayzart-live-code-editor' ),
			'included_ai'          => __( 'AI included, with no API key to set up', 'kayzart-live-code-editor' ),
			'client_lock'          => __( 'Locking parts of a page so clients and AI cannot change them', 'kayzart-live-code-editor' ),
			'analytics_ai_improve' => __( 'Traffic analytics with AI improvement suggestions', 'kayzart-live-code-editor' ),
			'form_integration'     => __( 'Contact and conversion form integration', 'kayzart-live-code-editor' ),
			'wp_content_context'   => __( 'AI that can pull in your WordPress content, such as posts, categories, and custom fields', 'kayzart-live-code-editor' ),
			'reusable_components'  => __( 'Reusable sections and shared components', 'kayzart-live-code-editor' ),
			'ab_testing'           => __( 'A/B testing', 'kayzart-live-code-editor' ),
			'other'                => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/**
	 * Return the single-choice list for the deciding Pro feature.
	 *
	 * @return array<string,string>
	 */
	private static function pro_decisive_options(): array {
		$options         = self::pro_priorities();
		$options['none'] = __( 'None of these would make me pay', 'kayzart-live-code-editor' );
		return $options;
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
