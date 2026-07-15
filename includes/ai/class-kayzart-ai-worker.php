<?php
/**
 * Action Scheduler integration for AI edit jobs.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Runs and maintains asynchronous AI jobs. */
class Ai_Worker {
	const GROUP        = 'kayzart-ai';
	const RUN_HOOK     = 'kayzart_run_ai_job';
	const TIMEOUT_HOOK = 'kayzart_timeout_ai_job';
	const CLEANUP_HOOK = 'kayzart_cleanup_ai_jobs';

	/** Register worker and scheduler hooks. */
	public static function init(): void {
		add_action( self::RUN_HOOK, array( __CLASS__, 'run' ), 10, 1 );
		add_action( self::TIMEOUT_HOOK, array( __CLASS__, 'timeout' ), 10, 1 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_cleanup' ) );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'handle_failed_execution' ), 10, 3 );
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'handle_failed_action' ), 10, 2 );
	}

	/** Enqueue a worker plus its independent deadline action.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public static function enqueue( string $job_uuid ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$action_id = as_enqueue_async_action( self::RUN_HOOK, array( $job_uuid ), self::GROUP, true );
		if ( 0 === (int) $action_id ) {
			return false;
		}
		$timeout_id = as_schedule_single_action( time() + Ai_Job_Store::TIMEOUT_SECONDS, self::TIMEOUT_HOOK, array( $job_uuid ), self::GROUP, true );
		if ( 0 === (int) $timeout_id ) {
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( self::RUN_HOOK, array( $job_uuid ), self::GROUP );
			}
			return false;
		}
		return true;
	}

	/** Claim and execute one AI agent job.
	 *
	 * @param string $job_uuid Job UUID.
	 * @throws Ai_Client_Exception When a filtered client is invalid. The method catches it internally.
	 */
	public static function run( string $job_uuid ): void {
		$store = new Ai_Job_Store();
		if ( ! $store->claim( $job_uuid ) ) {
			$store->expire_overdue( $job_uuid );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$job     = $store->get( $job_uuid );
		$payload = $job ? json_decode( (string) $job['payload_json'], true ) : null;
		if ( ! is_array( $payload ) ) {
			$store->mark_error( $job_uuid, __( 'The AI job payload is invalid.', 'kayzart-live-code-editor' ), false );
			self::unschedule_timeout( $job_uuid );
			return;
		}

		try {
			$client = apply_filters( 'kayzart_ai_client', new Ai_Client_Wp(), $job );
			if ( ! $client instanceof Ai_Client_Interface ) {
				throw new Ai_Client_Exception( 'The kayzart_ai_client filter must return an Ai_Client_Interface implementation.', false );
			}
			$agent  = new Ai_Agent(
				$client,
				array(
					'emit'       => function ( array $event ) use ( $store, $job_uuid ) {
						$store->append_event( $job_uuid, $event );
					},
					'isCanceled' => function () use ( $store, $job_uuid ): bool {
						return $store->is_cancel_requested( $job_uuid );
					},
				)
			);
			$result = $agent->run( $payload );
			if ( ! $store->complete( $job_uuid, $result['snapshot'], $result['summary'], $result['usage'] ) ) {
				// A cancellation raced with completion (the deadline case is already
				// settled by complete()'s expire_overdue). Settle it now so the post
				// lock is released instead of waiting for the timeout action.
				$current = $store->get( $job_uuid );
				if ( $current && 'running' === $current['status'] && ! empty( $current['cancel_requested'] ) ) {
					$store->mark_canceled( $job_uuid );
				}
			}
		} catch ( Ai_Agent_Canceled $error ) {
			$store->mark_canceled( $job_uuid );
		} catch ( Ai_Client_Exception $error ) {
			error_log( 'Kayzart AI client error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$store->mark_error( $job_uuid, __( 'The AI provider request failed.', 'kayzart-live-code-editor' ), $error->is_retryable() );
		} catch ( Ai_Agent_Error $error ) {
			$store->mark_error( $job_uuid, $error->getMessage(), $error->is_retryable() );
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart AI worker error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$store->mark_error( $job_uuid, __( 'The AI edit job failed unexpectedly.', 'kayzart-live-code-editor' ), true );
		}

		self::unschedule_timeout( $job_uuid );
	}

	/** Enforce a persisted job deadline.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public static function timeout( string $job_uuid ): void {
		( new Ai_Job_Store() )->mark_timed_out( $job_uuid );
	}

	/** Delete expired terminal records. */
	public static function cleanup(): void {
		( new Ai_Job_Store() )->cleanup_terminal();
	}

	/** Ensure the daily retention action exists. */
	public static function ensure_cleanup(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::CLEANUP_HOOK, null, self::GROUP ) ) {
			as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::CLEANUP_HOOK, array(), self::GROUP, true );
		}
	}

	/** Convert an Action Scheduler exception to a job error.
	 *
	 * @param int        $action_id Action ID.
	 * @param \Exception $exception Scheduler exception.
	 * @param mixed      $context   Failure context.
	 */
	public static function handle_failed_execution( int $action_id, \Exception $exception, $context = null ): void {
		unset( $exception, $context );
		self::fail_action_job( $action_id );
	}

	/** Convert an Action Scheduler timeout/fatal to a job error.
	 *
	 * @param int   $action_id Action ID.
	 * @param mixed $timeout   Timeout information.
	 */
	public static function handle_failed_action( int $action_id, $timeout = null ): void {
		unset( $timeout );
		self::fail_action_job( $action_id );
	}

	/** Cancel unfinished rows and remove pending plugin actions on deactivation. */
	public static function deactivate(): void {
		( new Ai_Job_Store() )->cancel_all_active();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
	}

	/** Remove pending worker and timeout actions for one job.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public static function unschedule_job( string $job_uuid ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::RUN_HOOK, array( $job_uuid ), self::GROUP );
			as_unschedule_action( self::TIMEOUT_HOOK, array( $job_uuid ), self::GROUP );
		}
	}

	/** Resolve the failed action's job UUID without trusting failure callback args.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 */
	private static function fail_action_job( int $action_id ): void {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}
		try {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			if ( ! $action || self::RUN_HOOK !== $action->get_hook() ) {
				return;
			}
			$args = $action->get_args();
			if ( ! empty( $args[0] ) ) {
				( new Ai_Job_Store() )->mark_error( (string) $args[0], __( 'The scheduled AI worker failed.', 'kayzart-live-code-editor' ), true );
				self::unschedule_timeout( (string) $args[0] );
			}
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart could not inspect a failed scheduled action: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Remove the timeout action after any normal worker termination.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	private static function unschedule_timeout( string $job_uuid ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::TIMEOUT_HOOK, array( $job_uuid ), self::GROUP );
		}
	}
}
