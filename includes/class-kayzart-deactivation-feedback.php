<?php
/**
 * Optional feedback before an individual plugin deactivation.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Collect a reason only when an authorized user explicitly sends it. */
class Deactivation_Feedback {
	const ENDPOINT      = 'https://feedback.kayzart.com/v1/deactivation-responses';
	const ACTION        = 'kayzart_deactivation_feedback';
	const COMMENT_LIMIT = 1000;

	/** Register plugin-list assets and the authenticated delivery handler. */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_footer-plugins.php', array( __CLASS__, 'render_dialog' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'handle_submission' ) );
		add_action( 'admin_init', array( __CLASS__, 'add_privacy_content' ) );
	}

	/** Return the immutable reason keys and translated labels. */
	public static function reasons(): array {
		return array(
			'api_setup'      => __( 'Getting an API key or setting up the connection was difficult', 'kayzart-live-code-editor' ),
			'cost'           => __( 'I am concerned about AI usage costs', 'kayzart-live-code-editor' ),
			'usability'      => __( 'I could not figure out how to use it', 'kayzart-live-code-editor' ),
			'output_quality' => __( 'I could not create the page I expected', 'kayzart-live-code-editor' ),
			'error'          => __( 'I encountered an error or something did not work', 'kayzart-live-code-editor' ),
			'alternative'    => __( 'I decided to use another tool', 'kayzart-live-code-editor' ),
			'temporary'      => __( 'Temporary deactivation or troubleshooting', 'kayzart-live-code-editor' ),
			'other'          => __( 'Other', 'kayzart-live-code-editor' ),
		);
	}

	/** Whether this user can deactivate Kayzart in the regular plugin list. */
	public static function can_show(): bool {
		return ! is_network_admin() && current_user_can( 'deactivate_plugin', plugin_basename( KAYZART_PATH . 'kayzart-live-code-editor.php' ) );
	}

	/**
	 * Load only on the regular plugin list, with no external request.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'plugins.php' !== $hook || ! self::can_show() ) {
			return;
		}
		wp_enqueue_style( self::ACTION, KAYZART_URL . 'assets/admin/deactivation-feedback.css', array(), KAYZART_VERSION );
		wp_enqueue_script( self::ACTION, KAYZART_URL . 'assets/admin/deactivation-feedback.js', array(), KAYZART_VERSION, true );
		wp_localize_script(
			self::ACTION,
			'KAYZART_DEACTIVATION',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'action'       => self::ACTION,
				'nonce'        => wp_create_nonce( self::ACTION ),
				'submissionId' => wp_generate_uuid4(),
				'pluginFile'   => plugin_basename( KAYZART_PATH . 'kayzart-live-code-editor.php' ),
				'emptyError'   => __( 'Choose a reason or enter a comment, or deactivate without sending.', 'kayzart-live-code-editor' ),
				'lengthError'  => __( 'Please keep your comment within 1,000 characters.', 'kayzart-live-code-editor' ),
			)
		);
	}

	/** Render an initially hidden native modal with optional answers. */
	public static function render_dialog(): void {
		if ( ! self::can_show() ) {
			return;
		}
		echo '<dialog id="kayzart-deactivation-dialog" aria-labelledby="kayzart-deactivation-title" aria-describedby="kayzart-deactivation-description">';
		echo '<form id="kayzart-deactivation-form" novalidate>';
		echo '<h2 id="kayzart-deactivation-title" tabindex="-1">' . esc_html__( 'If you do not mind, why are you deactivating Kayzart?', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p id="kayzart-deactivation-description">' . esc_html__( 'Your answer is optional and helps us improve Kayzart.', 'kayzart-live-code-editor' ) . '</p>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Main reason for deactivation', 'kayzart-live-code-editor' ) . '</legend>';
		foreach ( self::reasons() as $key => $label ) {
			echo '<label class="kayzart-deactivation-reason"><input type="radio" name="reason" value="' . esc_attr( $key ) . '"> <span>' . esc_html( $label ) . '</span></label>';
		}
		echo '</fieldset>';
		echo '<label for="kayzart-deactivation-comment">' . esc_html__( 'Anything else you would like us to know? (Optional)', 'kayzart-live-code-editor' ) . '</label>';
		// Native maxlength counts UTF-16 code units; JS and PHP enforce 1,000 characters.
		echo '<textarea id="kayzart-deactivation-comment" name="comment" rows="3" maxlength="2000" aria-describedby="kayzart-deactivation-error"></textarea>';
		echo '<p id="kayzart-deactivation-error" role="alert"></p>';
		echo '<p class="kayzart-deactivation-disclosure">' . esc_html( self::disclosure() ) . '</p>';
		echo '<div class="kayzart-deactivation-actions">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Send answer and deactivate', 'kayzart-live-code-editor' ) . '</button>';
		echo '<button class="button" type="button" data-kayzart-skip>' . esc_html__( 'Deactivate without sending', 'kayzart-live-code-editor' ) . '</button>';
		echo '<button class="button" type="button" data-kayzart-cancel>' . esc_html__( 'Cancel', 'kayzart-live-code-editor' ) . '</button>';
		echo '</div></form></dialog>';
	}

	/** Describe exactly what an explicit submission sends and retains. */
	public static function disclosure(): string {
		return __( 'Only when you choose “Send answer and deactivate”, your answer, a random answer ID, survey version, Kayzart and WordPress versions, and admin language are sent to feedback.kayzart.com and retained for two years. No site URL, email, API key, page content, or usage history is included. Please do not include private information in your comment. The receiving server may retain IP addresses in security logs. Delivery failure will not prevent deactivation.', 'kayzart-live-code-editor' );
	}

	/** Add disclosure alongside the existing product survey policy. */
	public static function add_privacy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'Kayzart deactivation feedback', '<p>' . esc_html( self::disclosure() ) . '</p>' );
		}
	}

	/**
	 * Prepare only the minimal answer payload, never site or user identifiers.
	 *
	 * @param array $input Unslashed form input.
	 * @return array|\WP_Error
	 */
	public static function prepare_submission( array $input ) {
		$id      = $input['submission_id'] ?? '';
		$reason  = $input['reason'] ?? '';
		$comment = $input['comment'] ?? '';
		if ( ! is_string( $id ) || ! wp_is_uuid( $id, 4 ) || ! is_string( $reason ) || ! is_string( $comment ) ) {
			return new \WP_Error( 'invalid_field' );
		}
		if ( '' !== $reason && ! array_key_exists( $reason, self::reasons() ) ) {
			return new \WP_Error( 'invalid_field' );
		}
		if ( 1 !== preg_match( '//u', $comment ) ) {
			return new \WP_Error( 'invalid_encoding' );
		}
		$comment = trim( sanitize_textarea_field( $comment ) );
		$length  = preg_match_all( '/./us', $comment );
		if ( false === $length || $length > self::COMMENT_LIMIT ) {
			return new \WP_Error( 'too_long' );
		}
		if ( '' === $reason && '' === $comment ) {
			return new \WP_Error( 'empty_submission' );
		}
		return array(
			'submission_id'  => strtolower( $id ),
			'survey_version' => 'v1',
			'plugin_version' => KAYZART_VERSION,
			'locale'         => get_user_locale(),
			'reason'         => '' === $reason ? null : $reason,
			'comment'        => '' === $comment ? null : $comment,
		);
	}

	/** Validate authorization and consent before forwarding an answer. */
	public static function handle_submission(): void {
		if ( ! self::can_show() ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_send_json_error( array( 'code' => 'method_not_allowed' ), 405 );
		}
		$payload = self::prepare_submission( wp_unslash( $_POST ) );
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error( array( 'code' => $payload->get_error_code() ), 400 );
		}
		$result = self::send_submission( $payload );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'code' => $result->get_error_code() ), 502 );
		}
		wp_send_json_success();
	}

	/**
	 * Attempt delivery once within the deactivation time budget.
	 *
	 * @param array $payload Validated answer.
	 * @return true|\WP_Error
	 */
	public static function send_submission( array $payload ) {
		global $wp_version;
		$response = wp_safe_remote_post(
			self::ENDPOINT,
			array(
				'timeout'             => 4,
				'redirection'         => 0,
				'limit_response_size' => 4096,
				'headers'             => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => sprintf( 'Kayzart/%s; WordPress/%s', KAYZART_VERSION, (string) $wp_version ),
				),
				'body'                => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'delivery_failed' );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! in_array( wp_remote_retrieve_response_code( $response ), array( 200, 201 ), true ) || ! is_array( $data ) || true !== ( $data['ok'] ?? false ) || ! isset( $data['submissionId'] ) || ! is_string( $data['submissionId'] ) || ! hash_equals( $payload['submission_id'], $data['submissionId'] ) ) {
			return new \WP_Error( 'delivery_failed' );
		}
		return true;
	}
}
