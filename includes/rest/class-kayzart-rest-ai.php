<?php
/**
 * REST endpoints for asynchronous AI edit jobs.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Creates, reads, and cancels AI jobs. */
class Rest_Ai {
	const MAX_PROMPT_BYTES = 8192;
	const MAX_CODE_BYTES   = 262144;
	const MAX_CONTEXTS     = 20;

	/** Register all AI job endpoints. */
	public static function register_routes(): void {
		register_rest_route(
			'kayzart/v1',
			'/ai/jobs',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create' ),
				'permission_callback' => array( __CLASS__, 'create_permission' ),
			)
		);
		register_rest_route(
			'kayzart/v1',
			'/ai/jobs/(?P<job_id>[0-9a-fA-F-]{36})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'show' ),
				'permission_callback' => array( __CLASS__, 'job_permission' ),
			)
		);
		register_rest_route(
			'kayzart/v1',
			'/ai/jobs/(?P<job_id>[0-9a-fA-F-]{36})/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cancel' ),
				'permission_callback' => array( __CLASS__, 'job_permission' ),
			)
		);
	}

	/** Create or idempotently retrieve a job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function create( \WP_REST_Request $request ) {
		if ( ! Ai_Availability::is_available() ) {
			return new \WP_Error(
				'kayzart_ai_unavailable',
				__( 'AI editing is not currently available.', 'kayzart-live-code-editor' ),
				array(
					'status'       => 503,
					'availability' => Ai_Availability::get_status(),
				)
			);
		}
		$payload = self::normalize_payload( $request );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$store                                        = new Ai_Job_Store();
		$payload['agentPayload']['recentEditContext'] = ( new Ai_Timeline_Store() )->recent_context( $payload['postId'] );
		$payload['agentPayload']['modelPreference']   = self::default_model_preference();
		$result                                       = $store->create( get_current_user_id(), $payload['postId'], $payload['requestId'], $payload['agentPayload'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$job            = $result['job'];
		$timeline       = new Ai_Timeline_Store();
		$stored_payload = json_decode( (string) $job['payload_json'], true );
		$activity       = $timeline->create_ai_edit( $job, is_array( $stored_payload ) ? $stored_payload : $payload['agentPayload'] );
		if ( ! $activity ) {
			$store->mark_error( $job['job_uuid'], __( 'The AI edit history could not be created.', 'kayzart-live-code-editor' ), true );
			return new \WP_Error( 'kayzart_ai_timeline_create_failed', __( 'The AI edit history could not be created.', 'kayzart-live-code-editor' ), array( 'status' => 503 ) );
		}
		if ( $result['is_new'] && ! Ai_Worker::enqueue( $job['job_uuid'] ) ) {
			$store->mark_enqueue_failed( $job['job_uuid'] );
			$job = $store->get( $job['job_uuid'] );
			return new \WP_Error(
				'kayzart_ai_enqueue_failed',
				__( 'The AI edit job could not be scheduled.', 'kayzart-live-code-editor' ),
				array(
					'status' => 503,
					'job'    => self::creation_response( $store, $job, $activity ),
				)
			);
		}

		$response = new \WP_REST_Response( self::creation_response( $store, $job, $activity ), $result['is_new'] ? 202 : 200 );
		return $response;
	}

	/** Return current job state, correcting a missed timeout first.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function show( \WP_REST_Request $request ) {
		$store = new Ai_Job_Store();
		$uuid  = (string) $request['job_id'];
		$store->expire_overdue( $uuid );
		$job = $store->get( $uuid );
		return rest_ensure_response( $store->to_response( $job ) );
	}

	/** Idempotently request cancellation.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function cancel( \WP_REST_Request $request ) {
		$store = new Ai_Job_Store();
		$uuid  = (string) $request['job_id'];
		$job   = $store->request_cancel( $uuid );
		if ( $job && 'canceled' === $job['status'] ) {
			Ai_Worker::unschedule_job( $uuid );
		}
		return rest_ensure_response( $store->to_response( $job ) );
	}

	/** Authenticate job creation and authorize the target post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function create_permission( \WP_REST_Request $request ) {
		$auth = self::authenticate_request( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		if ( ! current_user_can( Ai_Setup::CAPABILITY ) ) {
			return false;
		}
		$json    = $request->get_json_params();
		$post_id = is_array( $json ) && isset( $json['post_id'] ) ? absint( $json['post_id'] ) : absint( $request->get_param( 'post_id' ) );
		if ( 0 >= $post_id ) {
			return true;
		}
		if ( ! Post_Type::is_editor_enabled_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return true;
	}

	/** Authorize owner/admin access while hiding all inaccessible job IDs.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function job_permission( \WP_REST_Request $request ) {
		$auth = self::authenticate_request( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		$job     = ( new Ai_Job_Store() )->get( (string) $request['job_id'] );
		$allowed = $job
			&& current_user_can( Ai_Setup::CAPABILITY )
			&& current_user_can( 'edit_post', (int) $job['post_id'] )
			&& ( get_current_user_id() === (int) $job['user_id'] || current_user_can( 'manage_options' ) );
		if ( ! $allowed ) {
			return new \WP_Error( 'kayzart_ai_job_not_found', __( 'AI edit job not found.', 'kayzart-live-code-editor' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/** Verify login and the REST nonce.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function authenticate_request( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $request->get_header( 'X-WP-Nonce' ) ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'kayzart_invalid_nonce', __( 'Permission denied.', 'kayzart-live-code-editor' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Validate and canonicalize the public request into an agent payload.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function normalize_payload( \WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return self::invalid( __( 'A JSON request body is required.', 'kayzart-live-code-editor' ) );
		}
		$request_id = isset( $input['requestId'] ) ? (string) $input['requestId'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $request_id ) ) {
			return self::invalid( __( 'requestId is invalid.', 'kayzart-live-code-editor' ) );
		}
		$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
		if ( 0 >= $post_id ) {
			return self::invalid( __( 'post_id is invalid.', 'kayzart-live-code-editor' ) );
		}
		$mode = isset( $input['editorMode'] ) ? (string) $input['editorMode'] : '';
		if ( ! in_array( $mode, array( 'normal', 'tailwind' ), true ) ) {
			return self::invalid( __( 'editorMode is invalid.', 'kayzart-live-code-editor' ) );
		}
		$prompt = isset( $input['prompt'] ) ? trim( (string) $input['prompt'] ) : '';
		if ( '' === $prompt || strlen( $prompt ) > self::MAX_PROMPT_BYTES ) {
			return self::invalid( __( 'prompt is empty or too large.', 'kayzart-live-code-editor' ) );
		}

		$agent = array(
			'editorMode' => $mode,
			'prompt'     => $prompt,
		);
		foreach ( array( 'html', 'customHead', 'css', 'js' ) as $key ) {
			$value = isset( $input[ $key ] ) ? $input[ $key ] : '';
			if ( ! is_string( $value ) || strlen( $value ) > self::MAX_CODE_BYTES ) {
				return self::invalid( __( 'A code field is invalid or too large.', 'kayzart-live-code-editor' ) );
			}
			$agent[ $key ] = $value;
		}
		$agent['jsMode']   = isset( $input['jsMode'] ) && 'module' === $input['jsMode'] ? 'module' : 'classic';
		$agent['baseHash'] = isset( $input['baseHash'] ) && is_string( $input['baseHash'] ) ? substr( $input['baseHash'], 0, 128 ) : '';

		$contexts = array();
		if ( isset( $input['selectedContexts'] ) ) {
			if ( ! is_array( $input['selectedContexts'] ) || count( $input['selectedContexts'] ) > self::MAX_CONTEXTS ) {
				return self::invalid( __( 'selectedContexts is invalid or too large.', 'kayzart-live-code-editor' ) );
			}
			foreach ( $input['selectedContexts'] as $context ) {
				if ( ! is_array( $context ) || strlen( (string) wp_json_encode( $context ) ) > self::MAX_CODE_BYTES ) {
					return self::invalid( __( 'A selected context is invalid or too large.', 'kayzart-live-code-editor' ) );
				}
				$contexts[] = self::sanitize_context( $context );
			}
		} elseif ( isset( $input['selectedContext'] ) ) {
			if ( ! is_array( $input['selectedContext'] ) ) {
				return self::invalid( __( 'selectedContext is invalid.', 'kayzart-live-code-editor' ) );
			}
			$contexts[] = self::sanitize_context( $input['selectedContext'] );
		}
		$agent['selectedContexts'] = $contexts;

		return array(
			'requestId'    => $request_id,
			'postId'       => $post_id,
			'agentPayload' => $agent,
		);
	}

	/** Resolve the configured default model as an ordered preference list.
	 *
	 * Baked into the job payload at creation so a later settings change does not
	 * alter an in-flight job. Empty means "auto" (let the AI Client pick).
	 *
	 * @return array<int,string>
	 */
	private static function default_model_preference(): array {
		$model = trim( (string) get_option( Admin::OPTION_AI_DEFAULT_MODEL, '' ) );
		return '' !== $model ? array( $model ) : array();
	}

	/** Recursively normalize selection context to JSON-safe scalar data.
	 *
	 * @param array $context Selection context.
	 */
	private static function sanitize_context( array $context ): array {
		$result = array();
		foreach ( $context as $key => $value ) {
			if ( is_int( $key ) ) {
				$safe_key = $key;
			} else {
				// Preserve camelCase keys (lcId, outerHTML, sourceRange, ...) that
				// the model prompt references; only drop characters outside a safe
				// identifier set. sanitize_key() is avoided because it lowercases.
				$safe_key = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $key );
				if ( '' === $safe_key ) {
					continue;
				}
			}
			if ( is_array( $value ) ) {
				$result[ $safe_key ] = self::sanitize_context( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$result[ $safe_key ] = is_string( $value ) ? wp_check_invalid_utf8( $value ) : $value;
			}
		}
		return $result;
	}

	/** Add creation-only URLs to the common job representation.
	 *
	 * @param Ai_Job_Store $store Job store.
	 * @param array        $job      Database row.
	 * @param array|null   $activity Timeline row.
	 */
	private static function creation_response( Ai_Job_Store $store, array $job, ?array $activity = null ): array {
		$data                 = $store->to_response( $job );
		$data['statusUrl']    = rest_url( 'kayzart/v1/ai/jobs/' . $job['job_uuid'] );
		$data['cancelUrl']    = rest_url( 'kayzart/v1/ai/jobs/' . $job['job_uuid'] . '/cancel' );
		$data['timelineItem'] = $activity ? ( new Ai_Timeline_Store() )->to_response( $activity ) : null;
		return $data;
	}

	/** Build a consistent validation error.
	 *
	 * @param string $message Error message.
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'kayzart_ai_invalid_request', $message, array( 'status' => 400 ) );
	}
}
