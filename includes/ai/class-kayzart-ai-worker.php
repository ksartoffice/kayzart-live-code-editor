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
	const GROUP                 = 'kayzart-ai';
	const RUN_HOOK              = 'kayzart_run_ai_job';
	const STEP_HOOK             = 'kayzart_run_ai_job_step';
	const TIMEOUT_HOOK          = 'kayzart_timeout_ai_job';
	const CLEANUP_HOOK          = 'kayzart_cleanup_ai_jobs';
	const RECOVERY_HOOK         = 'kayzart_recover_ai_steps';
	const EXECUTION_LOCK_OPTION = 'kayzart_ai_execution_lock';

	/** Action Scheduler action currently invoking the worker.
	 *
	 * @var int
	 */
	private static $current_action_id = 0;

	/** Register worker and scheduler hooks. */
	public static function init(): void {
		add_action( self::RUN_HOOK, array( __CLASS__, 'run' ), 10, 1 );
		add_action( self::STEP_HOOK, array( __CLASS__, 'run_step' ), 10, 3 );
		add_action( self::TIMEOUT_HOOK, array( __CLASS__, 'timeout' ), 10, 1 );
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		add_action( self::RECOVERY_HOOK, array( __CLASS__, 'recover_steps' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'ensure_cleanup' ) );
		add_action( 'action_scheduler_failed_execution', array( __CLASS__, 'handle_failed_execution' ), 10, 3 );
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'handle_failed_action' ), 10, 2 );
		add_action( 'action_scheduler_before_execute', array( __CLASS__, 'track_current_action' ), 1, 1 );
		add_action( 'action_scheduler_after_execute', array( __CLASS__, 'clear_current_action' ), 999, 1 );
	}

	/** Enqueue a worker plus its independent deadline action.
	 *
	 * @param string $job_uuid Job UUID.
	 * @return array{run_action_id:int,timeout_action_id:int}|false Action IDs, or false on failure.
	 */
	public static function enqueue( string $job_uuid ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		$job = ( new Ai_Job_Store() )->get( $job_uuid );
		if ( ! $job ) {
			return false;
		}
		$stepwise  = 'stepwise' === Ai_Job_Store::row_execution_mode( $job );
		$args      = $stepwise ? array( $job_uuid, 0, wp_generate_uuid4() ) : array( $job_uuid );
		$hook      = $stepwise ? self::STEP_HOOK : self::RUN_HOOK;
		$action_id = as_enqueue_async_action( $hook, $args, self::GROUP, true, 0 );
		if ( 0 === (int) $action_id ) {
			return false;
		}
		$deadline   = max( time() + 1, strtotime( (string) $job['deadline_at'] . ' UTC' ) );
		$timeout_id = as_schedule_single_action( $deadline, self::TIMEOUT_HOOK, array( $job_uuid ), self::GROUP, true );
		if ( 0 === (int) $timeout_id ) {
			if ( function_exists( 'as_unschedule_action' ) ) {
				as_unschedule_action( $hook, $args, self::GROUP );
			}
			return false;
		}
		return array(
			'run_action_id'     => (int) $action_id,
			'timeout_action_id' => (int) $timeout_id,
		);
	}

	/** Claim and execute one AI agent job.
	 *
	 * @param string $job_uuid Job UUID.
	 * @throws Ai_Client_Exception When a filtered client is invalid. The method catches it internally.
	 */
	public static function run( string $job_uuid ): void {
		$action_started = microtime( true );
		$store          = new Ai_Job_Store();
		$lock           = self::acquire_execution_lock();
		if ( false === $lock ) {
			self::schedule_legacy( $job_uuid, 2 );
			return;
		}
		if ( ! $store->claim( $job_uuid ) ) {
			$store->expire_overdue( $job_uuid );
			self::release_execution_lock( $lock );
			self::schedule_pending_dispatch();
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$job = $store->get( $job_uuid );
		self::performance_log(
			$job,
			'action_started',
			array(
				'stateVersion' => 0,
				'attempt'      => 1,
				'queueWaitMs'  => $job ? self::elapsed_since( $job['created_at'] ) : 0,
			)
		);
		$payload = $job ? json_decode( (string) $job['payload_json'], true ) : null;
		if ( ! is_array( $payload ) ) {
			$store->mark_error( $job_uuid, __( 'The AI job payload is invalid.', 'kayzart-live-code-editor' ), false );
			self::unschedule_timeout( $job_uuid );
			self::release_execution_lock( $lock );
			self::schedule_pending_dispatch();
			return;
		}

		try {
			$client = apply_filters( 'kayzart_ai_client', new Ai_Client_Wp(), $job );
			if ( ! $client instanceof Ai_Client_Interface ) {
				throw new Ai_Client_Exception( 'The kayzart_ai_client filter must return an Ai_Client_Interface implementation.', false );
			}
			$agent  = self::create_agent( $client, $store, $job_uuid, $job );
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
		$current = $store->get( $job_uuid );
		self::performance_log(
			$job,
			'action_finished',
			array(
				'stateVersion' => 0,
				'attempt'      => 1,
				'actionMs'     => (int) round( ( microtime( true ) - $action_started ) * 1000 ),
				'jobTotalMs'   => $current ? self::elapsed_since( $current['created_at'] ) : 0,
				'outcome'      => $current ? $current['status'] : 'missing',
			)
		);
		self::release_execution_lock( $lock );
		self::schedule_pending_dispatch();
	}

	/** Execute one durable agent checkpoint.
	 *
	 * @param string $job_uuid        Job UUID.
	 * @param int    $expected_version Expected checkpoint version.
	 * @param string $dispatch_token  Action identity used only for deduplication.
	 * @throws Ai_Client_Exception When a filtered client is invalid. The method catches it internally.
	 * @throws Ai_Agent_Error When persisted state is invalid. The method catches it internally.
	 */
	public static function run_step( string $job_uuid, int $expected_version = 0, string $dispatch_token = '' ): void {
		unset( $dispatch_token );
		$started = microtime( true );
		$store   = new Ai_Job_Store();
		$job     = $store->get( $job_uuid );
		if ( ! $job || 'stepwise' !== Ai_Job_Store::row_execution_mode( $job ) || ! in_array( $job['status'], Ai_Job_Store::ACTIVE_STATUSES, true ) ) {
			return;
		}
		if ( (int) $job['state_version'] !== $expected_version ) {
			return;
		}
		$queued_at = $job['updated_at'];

		$lock = self::acquire_execution_lock();
		if ( false === $lock ) {
			$retry_action_id = self::schedule_step( $job_uuid, $expected_version, 2 );
			self::performance_log(
				$job,
				'execution_lock_busy',
				array(
					'stateVersion'  => $expected_version,
					'retryActionId' => $retry_action_id,
					'delaySeconds'  => 2,
				)
			);
			return;
		}

		$lease_token = '';
		try {
			if ( 'pending' === $job['status'] ) {
				if ( ! $store->claim( $job_uuid ) ) {
					$store->expire_overdue( $job_uuid );
					return;
				}
				$job = $store->get( $job_uuid );
			}
			if ( ! $job || (int) $job['state_version'] !== $expected_version ) {
				return;
			}

			$payload = json_decode( (string) $job['payload_json'], true );
			if ( ! is_array( $payload ) ) {
				$store->mark_error( $job_uuid, __( 'The AI job payload is invalid.', 'kayzart-live-code-editor' ), false );
				self::finish_job_actions( $job_uuid );
				return;
			}
			$client = apply_filters( 'kayzart_ai_client', new Ai_Client_Wp(), $job );
			if ( ! $client instanceof Ai_Client_Interface ) {
				throw new Ai_Client_Exception( 'The kayzart_ai_client filter must return an Ai_Client_Interface implementation.', false );
			}
			$agent = self::create_agent( $client, $store, $job_uuid, $job );
			$state = $store->get_step_state( $job );
			if ( null === $state ) {
				$initial = $agent->create_state( $payload );
				$store->initialize_step_state( $job_uuid, $initial );
				$job   = $store->get( $job_uuid );
				$state = $job ? $store->get_step_state( $job ) : null;
			}
			if ( ! is_array( $state ) ) {
				throw new Ai_Agent_Error( 'The persisted AI agent state is invalid.', false );
			}

			$lease_token = wp_generate_uuid4();
			$leased      = $store->acquire_step_lease( $job_uuid, $expected_version, $lease_token );
			if ( ! $leased ) {
				return;
			}
			$job     = $leased;
			$attempt = (int) $job['step_attempt'];
			if ( $attempt > 3 ) {
				$store->mark_error( $job_uuid, __( 'The AI provider request failed after retries.', 'kayzart-live-code-editor' ), true );
				self::finish_job_actions( $job_uuid );
				return;
			}

			self::performance_log(
				$job,
				'action_started',
				array(
					'stateVersion' => $expected_version,
					'attempt'      => $attempt,
					'queueWaitMs'  => self::elapsed_since( $queued_at ),
				)
			);
			$step = $agent->advance( $payload, $state );
			if ( ! $store->save_step_state( $job_uuid, $expected_version, $lease_token, $step['state'] ) ) {
				$current = $store->get( $job_uuid );
				if ( $current && 'running' === $current['status'] && ! empty( $current['cancel_requested'] ) ) {
					$store->mark_canceled( $job_uuid );
					self::finish_job_actions( $job_uuid );
				}
				return;
			}
			$lease_token = '';
			if ( 'completed' === $step['status'] ) {
				$result    = $step['result'];
				$completed = $store->complete( $job_uuid, $result['snapshot'], $result['summary'], $result['usage'] );
				if ( ! $completed ) {
					$current = $store->get( $job_uuid );
					if ( $current && 'running' === $current['status'] && ! empty( $current['cancel_requested'] ) ) {
						$store->mark_canceled( $job_uuid );
					}
				}
				self::finish_job_actions( $job_uuid );
				$current = $store->get( $job_uuid );
				self::performance_log(
					$job,
					'job_finished',
					array(
						'outcome'    => $current ? $current['status'] : 'missing',
						'jobTotalMs' => self::elapsed_since( $job['created_at'] ),
					)
				);
			} else {
				$next_version   = $expected_version + 1;
				$next_action_id = self::schedule_step( $job_uuid, $next_version, 0 );
				self::performance_log(
					$job,
					'continuation_scheduled',
					array(
						'actionId'     => $next_action_id,
						'stateVersion' => $next_version,
						'scheduledAt'  => gmdate( 'c' ),
						'delaySeconds' => 0,
					)
				);
			}
			self::performance_log(
				$job,
				'action_finished',
				array_merge(
					isset( $step['metrics'] ) ? $step['metrics'] : array(),
					array(
						'stateVersion' => $expected_version,
						'attempt'      => $attempt,
						'actionMs'     => (int) round( ( microtime( true ) - $started ) * 1000 ),
						'outcome'      => $step['status'],
					)
				)
			);
		} catch ( Ai_Agent_Canceled $error ) {
			$store->mark_canceled( $job_uuid );
			self::finish_job_actions( $job_uuid );
			self::performance_log(
				$job,
				'job_finished',
				array(
					'outcome'    => 'canceled',
					'jobTotalMs' => self::elapsed_since( $job['created_at'] ),
				)
			);
		} catch ( Ai_Client_Exception $error ) {
			$current = $store->get( $job_uuid );
			$attempt = $current ? (int) $current['step_attempt'] : 3;
			if ( $error->is_retryable() && $attempt < 3 && '' !== $lease_token && $store->release_step_lease( $job_uuid, $expected_version, $lease_token ) ) {
				$lease_token     = '';
				$delay           = 1 === $attempt ? 5 : 20;
				$retry_action_id = self::schedule_step( $job_uuid, $expected_version, $delay );
				self::performance_log(
					$job,
					'continuation_scheduled',
					array(
						'actionId'     => $retry_action_id,
						'stateVersion' => $expected_version,
						'scheduledAt'  => gmdate( 'c', time() + $delay ),
						'delaySeconds' => $delay,
					)
				);
				self::performance_log(
					$job,
					'retry_scheduled',
					array(
						'stateVersion' => $expected_version,
						'attempt'      => $attempt,
						'delaySeconds' => $delay,
					)
				);
			} else {
				error_log( 'Kayzart AI client error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$store->mark_error( $job_uuid, __( 'The AI provider request failed.', 'kayzart-live-code-editor' ), $error->is_retryable() );
				self::finish_job_actions( $job_uuid );
				self::performance_log(
					$job,
					'job_finished',
					array(
						'outcome'    => 'error',
						'jobTotalMs' => self::elapsed_since( $job['created_at'] ),
					)
				);
			}
		} catch ( Ai_Agent_Error $error ) {
			$store->mark_error( $job_uuid, $error->getMessage(), $error->is_retryable() );
			self::finish_job_actions( $job_uuid );
			self::performance_log(
				$job,
				'job_finished',
				array(
					'outcome'    => 'error',
					'jobTotalMs' => self::elapsed_since( $job['created_at'] ),
				)
			);
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart AI step worker error: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$store->mark_error( $job_uuid, __( 'The AI edit job failed unexpectedly.', 'kayzart-live-code-editor' ), true );
			self::finish_job_actions( $job_uuid );
			self::performance_log(
				$job,
				'job_finished',
				array(
					'outcome'    => 'error',
					'jobTotalMs' => self::elapsed_since( $job['created_at'] ),
				)
			);
		} finally {
			if ( '' !== $lease_token ) {
				$store->release_step_lease( $job_uuid, $expected_version, $lease_token );
			}
			self::release_execution_lock( $lock );
			self::schedule_pending_dispatch();
		}
	}

	/** Enforce a persisted job deadline.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public static function timeout( string $job_uuid ): void {
		( new Ai_Job_Store() )->mark_timed_out( $job_uuid );
		self::finish_job_actions( $job_uuid );
		self::schedule_pending_dispatch();
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
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) && ! as_has_scheduled_action( self::RECOVERY_HOOK, null, self::GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, self::RECOVERY_HOOK, array(), self::GROUP, true );
		}
	}

	/** Requeue stepwise jobs abandoned after a fatal or scheduling gap. */
	public static function recover_steps(): void {
		$store  = new Ai_Job_Store();
		$queued = false;
		foreach ( $store->get_recoverable_steps() as $job ) {
			$uuid    = (string) $job['job_uuid'];
			$attempt = (int) $job['step_attempt'];
			if ( $attempt >= 3 ) {
				$store->mark_error( $uuid, __( 'The AI step failed repeatedly before it could save progress.', 'kayzart-live-code-editor' ), true );
				self::finish_job_actions( $uuid );
				continue;
			}
			if ( self::has_pending_step_action( $uuid, (int) $job['state_version'] ) ) {
				continue;
			}
			$version   = (int) $job['state_version'];
			$action_id = self::schedule_step( $uuid, $version, 0 );
			$queued    = $queued || $action_id > 0;
			self::performance_log(
				$job,
				'continuation_scheduled',
				array(
					'actionId'     => $action_id,
					'stateVersion' => $version,
					'scheduledAt'  => gmdate( 'c' ),
					'delaySeconds' => 0,
					'source'       => 'recovery',
				)
			);
		}
		if ( $queued ) {
			self::schedule_pending_dispatch();
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

	/** Remember the current Kayzart action ID for structured logging.
	 *
	 * @param int $action_id Action Scheduler action ID.
	 */
	public static function track_current_action( int $action_id ): void {
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return;
		}
		try {
			$hook = \ActionScheduler::store()->fetch_action( $action_id )->get_hook();
			if ( in_array( $hook, array( self::RUN_HOOK, self::STEP_HOOK ), true ) ) {
				self::$current_action_id = $action_id;
			}
		} catch ( \Throwable $error ) {
			self::$current_action_id = 0;
		}
	}

	/** Forget the completed Action Scheduler action ID.
	 *
	 * @param int $action_id Completed action ID.
	 */
	public static function clear_current_action( int $action_id ): void {
		if ( self::$current_action_id === $action_id ) {
			self::$current_action_id = 0;
		}
	}

	/** Cancel unfinished rows and remove pending plugin actions on deactivation. */
	public static function deactivate(): void {
		( new Ai_Job_Store() )->cancel_all_active();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		if ( class_exists( __NAMESPACE__ . '\\Ai_Immediate_Dispatcher' ) ) {
			Ai_Immediate_Dispatcher::deactivate();
		}
		delete_option( self::EXECUTION_LOCK_OPTION );
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
		self::cancel_step_actions( $job_uuid );
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
			if ( ! $action || ! in_array( $action->get_hook(), array( self::RUN_HOOK, self::STEP_HOOK ), true ) ) {
				return;
			}
			$args = $action->get_args();
			if ( ! empty( $args[0] ) ) {
				if ( self::STEP_HOOK === $action->get_hook() ) {
					self::performance_log( ( new Ai_Job_Store() )->get( (string) $args[0] ), 'action_failed', array( 'stateVersion' => isset( $args[1] ) ? (int) $args[1] : 0 ) );
					return;
				}
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

	/** Create an agent wired to the durable job event stream.
	 *
	 * @param Ai_Client_Interface $client   Provider client.
	 * @param Ai_Job_Store        $store    Persistent job store.
	 * @param string              $job_uuid Job UUID.
	 * @param array               $job      Job database row.
	 */
	private static function create_agent( Ai_Client_Interface $client, Ai_Job_Store $store, string $job_uuid, array $job ): Ai_Agent {
		return new Ai_Agent(
			$client,
			array(
				'emit'        => function ( array $event ) use ( $store, $job_uuid ) {
					$store->append_event( $job_uuid, $event );
				},
				'isCanceled'  => function () use ( $store, $job_uuid ): bool {
					return $store->is_cancel_requested( $job_uuid );
				},
				'debugId'     => $job_uuid,
				'observeStep' => function ( array $metrics ) use ( $job ) {
					self::performance_log( $job, 'model_step', $metrics );
				},
			)
		);
	}

	/** Schedule one checkpoint action and optionally kick its loopback.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param int    $version  Expected state version.
	 * @param int    $delay    Delay in seconds.
	 */
	private static function schedule_step( string $job_uuid, int $version, int $delay ): int {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return 0;
		}
		$args      = array( $job_uuid, $version, wp_generate_uuid4() );
		$action_id = as_schedule_single_action( time() + max( 0, $delay ), self::STEP_HOOK, $args, self::GROUP, false, 0 );
		return (int) $action_id;
	}

	/** Emit a performance event from another AI subsystem.
	 *
	 * @param mixed  $job   Job database row.
	 * @param string $event Event name.
	 * @param array  $data  Content-free metric fields.
	 */
	public static function log_performance_event( $job, string $event, array $data = array() ): void {
		self::performance_log( $job, $event, $data );
	}

	/** Reschedule a legacy action when the site execution lock is occupied.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param int    $delay    Delay in seconds.
	 */
	private static function schedule_legacy( string $job_uuid, int $delay ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + max( 1, $delay ), self::RUN_HOOK, array( $job_uuid ), self::GROUP, false, 0 );
		}
	}

	/** Unschedule terminal job actions, including variable step arguments.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	private static function finish_job_actions( string $job_uuid ): void {
		self::unschedule_timeout( $job_uuid );
		self::cancel_step_actions( $job_uuid );
	}

	/** Cancel pending step actions for one job by inspecting their first arg.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	private static function cancel_step_actions( string $job_uuid ): void {
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return;
		}
		try {
			$store = \ActionScheduler::store();
			$ids   = $store->query_actions(
				array(
					'hook'     => self::STEP_HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'claimed'  => false,
					'per_page' => -1,
				)
			);
			foreach ( $ids as $id ) {
				$action = $store->fetch_action( $id );
				$args   = $action->get_args();
				if ( isset( $args[0] ) && hash_equals( $job_uuid, (string) $args[0] ) ) {
					$store->cancel_action( $id );
				}
			}
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart could not cancel pending AI step actions.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Check whether Action Scheduler already owns the current checkpoint.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param int    $version  Expected state version.
	 */
	private static function has_pending_step_action( string $job_uuid, int $version ): bool {
		if ( ! class_exists( '\\ActionScheduler' ) ) {
			return false;
		}
		try {
			$store = \ActionScheduler::store();
			$ids   = $store->query_actions(
				array(
					'hook'     => self::STEP_HOOK,
					'group'    => self::GROUP,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				)
			);
			foreach ( $ids as $id ) {
				$args = $store->fetch_action( $id )->get_args();
				if ( isset( $args[0], $args[1] ) && hash_equals( $job_uuid, (string) $args[0] ) && $version === (int) $args[1] ) {
					return true;
				}
			}
		} catch ( \Throwable $error ) {
			error_log( 'Kayzart could not inspect pending AI step actions.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return false;
	}

	/** Acquire the site-wide provider execution lock. */
	private static function acquire_execution_lock() {
		global $wpdb;
		$value = wp_json_encode(
			array(
				'token'   => wp_generate_password( 32, false, false ),
				'expires' => time() + Ai_Job_Store::STEP_LEASE_SECONDS,
			)
		);
		if ( add_option( self::EXECUTION_LOCK_OPTION, $value, '', 'no' ) ) {
			return $value;
		}
		$current = (string) get_option( self::EXECUTION_LOCK_OPTION, '' );
		$decoded = json_decode( $current, true );
		if ( ! is_array( $decoded ) || empty( $decoded['expires'] ) || (int) $decoded['expires'] >= time() ) {
			return false;
		}
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::EXECUTION_LOCK_OPTION, $current ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::EXECUTION_LOCK_OPTION, 'options' );
		return 1 === $deleted && add_option( self::EXECUTION_LOCK_OPTION, $value, '', 'no' ) ? $value : false;
	}

	/** Release only the exact execution lock acquired by this request.
	 *
	 * @param string $value Exact option value acquired by this request.
	 */
	private static function release_execution_lock( string $value ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::EXECUTION_LOCK_OPTION, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_delete( self::EXECUTION_LOCK_OPTION, 'options' );
	}

	/** Emit a content-free structured performance event when explicitly enabled.
	 *
	 * @param mixed  $job   Job database row.
	 * @param string $event Event name.
	 * @param array  $data  Content-free metric fields.
	 */
	private static function performance_log( $job, string $event, array $data = array() ): void {
		if ( ! is_array( $job ) || ! apply_filters( 'kayzart_ai_performance_logging_enabled', false, $job ) ) {
			return;
		}
		$base    = array(
			'event'         => $event,
			'jobId'         => (string) $job['job_uuid'],
			'requestId'     => (string) $job['request_id'],
			'executionMode' => Ai_Job_Store::row_execution_mode( $job ),
			'runner'        => defined( 'REST_REQUEST' ) && REST_REQUEST ? 'Kayzart Immediate Loopback' : 'Action Scheduler',
			'actionId'      => self::$current_action_id,
		);
		$encoded = wp_json_encode( array_merge( $base, $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $encoded ) ) {
			error_log( '[Kayzart AI performance] ' . $encoded ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Milliseconds elapsed since a UTC database timestamp.
	 *
	 * @param mixed $timestamp UTC database timestamp.
	 */
	private static function elapsed_since( $timestamp ): int {
		$started = strtotime( (string) $timestamp . ' UTC' );
		return false === $started ? 0 : max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}

	/** Dispatch the next pending AI action after the current request releases its claim. */
	private static function schedule_pending_dispatch(): void {
		if ( class_exists( __NAMESPACE__ . '\\Ai_Immediate_Dispatcher' ) ) {
			Ai_Immediate_Dispatcher::schedule_pending_dispatch();
		}
	}
}
