<?php
/**
 * REST endpoint for improving a page-creation instruction.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles synchronous, pre-creation AI prompt improvement.
 */
class Rest_Ai_Prompt {

	const ROUTE            = '/ai/prompts/improve';
	const MAX_TITLE_BYTES  = 1024;
	const RATE_LIMIT       = 5;
	const RATE_WINDOW      = 60;
	const RATE_KEY_PREFIX  = 'kayzart_ai_prompt_improve_';
	const RATE_LOCK_PREFIX = 'kayzart_prompt_rate_lock_';
	const RATE_LOCK_WAIT   = 1;

	/**
	 * Register the endpoint.
	 */
	public static function register_route(): void {
		register_rest_route(
			'kayzart/v1',
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'improve' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Authenticate the request and require AI editing access.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public static function permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( Ai_Setup::CAPABILITY ) ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $request->get_header( 'X-WP-Nonce' ) ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'kayzart_invalid_nonce',
				__( 'Permission denied.', 'kayzart-live-code-editor' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Improve the supplied instruction.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function improve( \WP_REST_Request $request ) {
		if ( ! Ai_Availability::is_available() ) {
			return new \WP_Error(
				'kayzart_ai_unavailable',
				__( 'AI editing is not currently available.', 'kayzart-live-code-editor' ),
				array( 'status' => 503 )
			);
		}

		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return self::invalid( __( 'A JSON request body is required.', 'kayzart-live-code-editor' ) );
		}

		$prompt = isset( $input['prompt'] ) && is_string( $input['prompt'] )
			? trim( wp_check_invalid_utf8( $input['prompt'], true ) )
			: '';
		$title  = isset( $input['title'] ) && is_string( $input['title'] )
			? trim( sanitize_text_field( $input['title'] ) )
			: '';
		if ( '' === $prompt || mb_strlen( $prompt, 'UTF-8' ) > Admin::get_ai_max_prompt_chars() ) {
			return self::invalid( __( 'The instruction is empty or too large.', 'kayzart-live-code-editor' ) );
		}
		if ( strlen( $title ) > self::MAX_TITLE_BYTES ) {
			return self::invalid( __( 'The page title is too large.', 'kayzart-live-code-editor' ) );
		}

		$rate_limit = self::consume_rate_limit( get_current_user_id() );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$client = apply_filters( 'kayzart_ai_prompt_improver_client', Ai_Client_Factory::for_job() );
		if ( ! $client instanceof Ai_Client_Interface ) {
			return self::failed();
		}

		try {
			$improved = ( new Ai_Prompt_Improver( $client ) )->improve( $prompt, $title );
		} catch ( \Throwable $error ) {
			return self::failed();
		}

		return rest_ensure_response( array( 'improvedPrompt' => $improved ) );
	}

	/**
	 * Consume one attempt in the current per-user rate window.
	 *
	 * @param int $user_id Current user ID.
	 * @return true|\WP_Error
	 */
	private static function consume_rate_limit( int $user_id ) {
		global $wpdb;

		$lock_name = self::RATE_LOCK_PREFIX . md5( $wpdb->prefix . ':' . $user_id );
		$acquired  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks provide cross-request atomicity.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, self::RATE_LOCK_WAIT )
		);
		if ( '1' !== (string) $acquired ) {
			return new \WP_Error(
				'kayzart_ai_prompt_rate_lock_busy',
				__( 'Too many improvement requests. Please wait a moment and try again.', 'kayzart-live-code-editor' ),
				array(
					'status'     => 429,
					'retryAfter' => 1,
				)
			);
		}

		try {
			return self::consume_rate_limit_window( $user_id );
		} finally {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the matching advisory lock on this connection.
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name )
			);
		}
	}

	/**
	 * Consume one attempt while holding the current user's rate-limit lock.
	 *
	 * @param int $user_id Current user ID.
	 * @return true|\WP_Error
	 */
	private static function consume_rate_limit_window( int $user_id ) {
		$now    = time();
		$key    = self::RATE_KEY_PREFIX . $user_id;
		$stored = get_transient( $key );
		$window = is_array( $stored ) ? $stored : array();
		$start  = isset( $window['startedAt'] ) ? (int) $window['startedAt'] : $now;
		$count  = isset( $window['count'] ) ? max( 0, (int) $window['count'] ) : 0;

		if ( $start <= $now - self::RATE_WINDOW ) {
			$start = $now;
			$count = 0;
		}
		$retry_after = max( 1, $start + self::RATE_WINDOW - $now );
		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error(
				'kayzart_ai_prompt_rate_limited',
				__( 'Too many improvement requests. Please wait a moment and try again.', 'kayzart-live-code-editor' ),
				array(
					'status'     => 429,
					'retryAfter' => $retry_after,
				)
			);
		}

		set_transient(
			$key,
			array(
				'startedAt' => $start,
				'count'     => $count + 1,
			),
			$retry_after
		);
		return true;
	}

	/**
	 * Return a safe validation error.
	 *
	 * @param string $message Public error message.
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'kayzart_ai_prompt_invalid', $message, array( 'status' => 400 ) );
	}

	/**
	 * Return a provider-independent failure.
	 */
	private static function failed(): \WP_Error {
		return new \WP_Error(
			'kayzart_ai_prompt_improve_failed',
			__( 'The instruction could not be improved. Please try again.', 'kayzart-live-code-editor' ),
			array( 'status' => 502 )
		);
	}
}
