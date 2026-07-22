<?php
/**
 * Immediate loopback dispatcher for queued AI actions.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Starts one Action Scheduler AI action without waiting for the default queue runner. */
class Ai_Immediate_Dispatcher {
	const ROUTE            = '/ai/internal/run';
	const SIGNATURE_TTL    = 60;
	const LOCK_GRACE       = 60;
	const LOCK_OPTION      = 'kayzart_ai_immediate_runner_lock';
	const HEADER_ACTION_ID = 'X-Kayzart-AI-Action';
	const HEADER_JOB_UUID  = 'X-Kayzart-AI-Job';
	const HEADER_EXPIRES   = 'X-Kayzart-AI-Expires';
	const HEADER_NONCE     = 'X-Kayzart-AI-Nonce';
	const HEADER_SIGNATURE = 'X-Kayzart-AI-Signature';

	/**
	 * Whether a shutdown dispatch is already registered.
	 *
	 * @var bool
	 */
	private static $shutdown_dispatch_scheduled = false;

	/** Register the signed internal REST endpoint. */
	public static function register_route(): void {
		register_rest_route(
			'kayzart/v1',
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/** Whether immediate dispatch is enabled for this site. */
	public static function is_enabled(): bool {
		/**
		 * Filter whether Kayzart should immediately dispatch newly queued AI jobs.
		 *
		 * @param bool $enabled Whether immediate loopback dispatch is enabled.
		 */
		return (bool) apply_filters( 'kayzart_ai_immediate_dispatch_enabled', true );
	}

	/** Send a signed, non-blocking loopback for one scheduled AI action.
	 *
	 * @param int    $action_id Action Scheduler action ID.
	 * @param string $job_uuid  Kayzart AI job UUID.
	 */
	public static function dispatch( int $action_id, string $job_uuid ): bool {
		if ( ! self::is_enabled() || $action_id <= 0 || ! self::valid_uuid( $job_uuid ) ) {
			return false;
		}

		$expires = time() + self::SIGNATURE_TTL;
		$nonce   = wp_generate_password( 32, false, false );
		$result  = wp_remote_post(
			rest_url( 'kayzart/v1' . self::ROUTE ),
			array(
				'blocking'    => false,
				'timeout'     => 1,
				'redirection' => 0,
				'headers'     => array(
					self::HEADER_ACTION_ID => (string) $action_id,
					self::HEADER_JOB_UUID  => $job_uuid,
					self::HEADER_EXPIRES   => (string) $expires,
					self::HEADER_NONCE     => $nonce,
					self::HEADER_SIGNATURE => self::sign( $action_id, $job_uuid, $expires, $nonce ),
				),
			)
		);

		if ( is_wp_error( $result ) ) {
			error_log( 'Kayzart AI immediate dispatch failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		return true;
	}

	/** Authenticate a signed internal loopback request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function permission( \WP_REST_Request $request ) {
		$identity = self::request_identity( $request );
		if ( false === $identity ) {
			return self::forbidden();
		}

		$expected = self::sign( $identity['action_id'], $identity['job_uuid'], $identity['expires'], $identity['nonce'] );
		if ( ! hash_equals( $expected, $identity['signature'] ) || ! self::action_matches( $identity['action_id'], $identity['job_uuid'] ) ) {
			return self::forbidden();
		}
		return true;
	}

	/** Claim and process one Kayzart AI action through Action Scheduler.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function run( \WP_REST_Request $request ) {
		if ( ! self::is_enabled() ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'started' => false,
					'reason'  => 'disabled',
				)
			);
		}

		$identity = self::request_identity( $request );
		if ( false === $identity || ! class_exists( '\\ActionScheduler' ) ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'started' => false,
					'reason'  => 'unavailable',
				)
			);
		}

		$store      = \ActionScheduler::store();
		$job_store  = new Ai_Job_Store();
		$job        = $job_store->get( $identity['job_uuid'] );
		$as_status  = $store->get_status( $identity['action_id'] );
		$job_active = $job && in_array( $job['status'], Ai_Job_Store::ACTIVE_STATUSES, true );
		if ( \ActionScheduler_Store::STATUS_PENDING !== $as_status || ! $job_active ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'started' => false,
					'reason'  => 'settled',
				)
			);
		}

		$lock = self::acquire_lock();
		if ( false === $lock ) {
			return rest_ensure_response(
				array(
					'ok'      => true,
					'started' => false,
					'reason'  => 'busy',
				)
			);
		}

		$claim      = null;
		$started    = false;
		$claimed_id = 0;
		try {
			if ( $job_store->has_running_job() || self::has_claimed_ai_action( $store ) ) {
				return rest_ensure_response(
					array(
						'ok'      => true,
						'started' => false,
						'reason'  => 'busy',
					)
				);
			}

			// The run hook is Kayzart-specific. Omitting the redundant group here
			// also supports Action Scheduler's migration-time HybridStore.
			$claim   = $store->stake_claim( 1, null, array( Ai_Worker::RUN_HOOK ) );
			$actions = $claim->get_actions();
			if ( empty( $actions ) ) {
				return rest_ensure_response(
					array(
						'ok'      => true,
						'started' => false,
						'reason'  => 'empty',
					)
				);
			}

			$claimed_id = (int) $actions[0];
			$started    = true;
			\ActionScheduler::runner()->process_action( $claimed_id, 'Kayzart Immediate Loopback' );
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart AI immediate execution failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new \WP_Error( 'kayzart_ai_immediate_failed', __( 'The AI job could not be started immediately.', 'kayzart-live-code-editor' ), array( 'status' => 500 ) );
		} finally {
			if ( $claim instanceof \ActionScheduler_ActionClaim ) {
				$store->release_claim( $claim );
			}
			self::release_lock( $lock );
		}

		self::dispatch_oldest_pending();
		return rest_ensure_response(
			array(
				'ok'       => true,
				'started'  => $started,
				'actionId' => $claimed_id,
			)
		);
	}

	/** Schedule a best-effort next-job kick after the current request releases claims. */
	public static function schedule_pending_dispatch(): void {
		if ( self::$shutdown_dispatch_scheduled || ! self::is_enabled() ) {
			return;
		}
		self::$shutdown_dispatch_scheduled = true;
		add_action( 'shutdown', array( __CLASS__, 'dispatch_oldest_pending' ), 999 );
	}

	/** Dispatch the oldest unclaimed pending Kayzart AI action, if any. */
	public static function dispatch_oldest_pending(): bool {
		self::$shutdown_dispatch_scheduled = false;
		remove_action( 'shutdown', array( __CLASS__, 'dispatch_oldest_pending' ), 999 );
		if ( ! self::is_enabled() || ! class_exists( '\\ActionScheduler' ) ) {
			return false;
		}
		$store     = \ActionScheduler::store();
		$action_id = $store->find_action(
			Ai_Worker::RUN_HOOK,
			array(
				'group'   => Ai_Worker::GROUP,
				'status'  => \ActionScheduler_Store::STATUS_PENDING,
				'claimed' => false,
			)
		);
		if ( empty( $action_id ) ) {
			return false;
		}
		try {
			$action = $store->fetch_action( $action_id );
			$args   = $action->get_args();
			$uuid   = isset( $args[0] ) ? (string) $args[0] : '';
			return self::dispatch( (int) $action_id, $uuid );
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart AI pending dispatch lookup failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
	}

	/** Clear request-local scheduling and the immediate-runner lock on deactivation. */
	public static function deactivate(): void {
		self::$shutdown_dispatch_scheduled = false;
		remove_action( 'shutdown', array( __CLASS__, 'dispatch_oldest_pending' ), 999 );
		delete_option( self::LOCK_OPTION );
	}

	/** Return normalized signed request fields, or false when malformed/expired.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	private static function request_identity( \WP_REST_Request $request ) {
		$action_id = absint( $request->get_header( self::HEADER_ACTION_ID ) );
		$job_uuid  = sanitize_text_field( wp_unslash( (string) $request->get_header( self::HEADER_JOB_UUID ) ) );
		$expires   = absint( $request->get_header( self::HEADER_EXPIRES ) );
		$nonce     = sanitize_text_field( wp_unslash( (string) $request->get_header( self::HEADER_NONCE ) ) );
		$signature = strtolower( sanitize_text_field( wp_unslash( (string) $request->get_header( self::HEADER_SIGNATURE ) ) ) );
		$now       = time();
		if ( $action_id <= 0 || ! self::valid_uuid( $job_uuid ) || $expires < $now || $expires > $now + self::SIGNATURE_TTL ) {
			return false;
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{20,64}$/', $nonce ) || ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
			return false;
		}
		return compact( 'action_id', 'job_uuid', 'expires', 'nonce', 'signature' );
	}

	/** Verify the signed action belongs to the expected Kayzart job.
	 *
	 * @param int    $action_id Action Scheduler action ID.
	 * @param string $job_uuid  Kayzart AI job UUID.
	 */
	private static function action_matches( int $action_id, string $job_uuid ): bool {
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return false;
		}
		try {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			$args   = $action->get_args();
			$job    = ( new Ai_Job_Store() )->get( $job_uuid );
			return $job
				&& Ai_Worker::RUN_HOOK === $action->get_hook()
				&& Ai_Worker::GROUP === $action->get_group()
				&& isset( $args[0] )
				&& hash_equals( $job_uuid, (string) $args[0] );
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** Whether another Action Scheduler runner already owns a Kayzart AI action.
	 *
	 * @param \ActionScheduler_Store $store Action Scheduler store.
	 */
	private static function has_claimed_ai_action( \ActionScheduler_Store $store ): bool {
		$claimed = $store->query_actions(
			array(
				'hook'     => Ai_Worker::RUN_HOOK,
				'group'    => Ai_Worker::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'claimed'  => true,
				'per_page' => 1,
			)
		);
		return ! empty( $claimed );
	}

	/** Acquire the crash-recoverable site-wide immediate-runner lock. */
	private static function acquire_lock() {
		global $wpdb;
		$value = wp_json_encode(
			array(
				'token'   => wp_generate_password( 32, false, false ),
				'expires' => time() + Ai_Job_Store::TIMEOUT_SECONDS + self::LOCK_GRACE,
			)
		);
		if ( add_option( self::LOCK_OPTION, $value, '', 'no' ) ) {
			return $value;
		}
		$current = (string) get_option( self::LOCK_OPTION, '' );
		$decoded = json_decode( $current, true );
		if ( ! is_array( $decoded ) || empty( $decoded['expires'] ) || (int) $decoded['expires'] >= time() ) {
			return false;
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		return 1 === $deleted && add_option( self::LOCK_OPTION, $value, '', 'no' ) ? $value : false;
	}

	/** Release only the lock token acquired by this request.
	 *
	 * @param string $value Exact stored lock value.
	 */
	private static function release_lock( string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::LOCK_OPTION, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	/** Build the canonical HMAC signature.
	 *
	 * @param int    $action_id Action Scheduler action ID.
	 * @param string $job_uuid  Kayzart AI job UUID.
	 * @param int    $expires   Signature expiry timestamp.
	 * @param string $nonce     One-request random nonce.
	 */
	private static function sign( int $action_id, string $job_uuid, int $expires, string $nonce ): string {
		return hash_hmac( 'sha256', $action_id . '|' . $job_uuid . '|' . $expires . '|' . $nonce, wp_salt( 'auth' ) );
	}

	/** Validate the canonical WordPress UUID representation.
	 *
	 * @param string $uuid Candidate UUID.
	 */
	private static function valid_uuid( string $uuid ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid );
	}

	/** Return a deliberately generic authentication failure. */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'kayzart_ai_internal_forbidden', __( 'Permission denied.', 'kayzart-live-code-editor' ), array( 'status' => 403 ) );
	}
}
