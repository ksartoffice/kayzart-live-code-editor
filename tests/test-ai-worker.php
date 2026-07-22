<?php
/**
 * AI Action Scheduler worker tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Message;
use KayzArt\Ai_Setup;
use KayzArt\Ai_Worker;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/** Verifies worker success and safe terminal failures with a fake client. */
class Test_Kayzart_Ai_Worker extends WP_UnitTestCase {
	/** Job store under test.
	 *
	 * @var Ai_Job_Store
	 */
	private $store;

	/** Prepare an empty job table. */
	protected function setUp(): void {
		parent::setUp();
		Ai_Setup::activate();
		$this->store = new Ai_Job_Store();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Ai_Worker::EXECUTION_LOCK_OPTION );
		add_filter( 'kayzart_ai_immediate_dispatch_enabled', '__return_false' );
	}

	/** Remove client substitutions. */
	protected function tearDown(): void {
		remove_all_filters( 'kayzart_ai_client' );
		remove_all_filters( 'kayzart_ai_execution_mode' );
		remove_all_filters( 'kayzart_ai_immediate_dispatch_enabled' );
		Ai_Worker::deactivate();
		parent::tearDown();
	}

	/** Successful execution persists output, usage, and final event. */
	public function test_worker_saves_snapshot_summary_events_and_usage(): void {
		$fake = new Ai_Client_Fake(
			array(
				array(
					'toolCalls' => array(
						Ai_Message::tool_call(
							'call-1',
							'replace_string',
							array(
								'target' => 'html',
								'from'   => 'Hello',
								'to'     => 'World',
							)
						),
					),
					'usage'     => array(
						'inputTokens'  => 10,
						'outputTokens' => 2,
					),
				),
				array(
					'text'  => 'Done',
					'usage' => array( 'inputTokens' => 5 ),
				),
				array(
					'text'  => '{"summary":"Changed the heading."}',
					'usage' => array( 'outputTokens' => 3 ),
				),
			)
		);
		add_filter(
			'kayzart_ai_client',
			function () use ( $fake ) {
				return $fake;
			}
		);
		$uuid = $this->create_job( 'worker-success' );

		Ai_Worker::run( $uuid );

		$response = $this->store->to_response( $this->store->get( $uuid ) );
		$this->assertSame( 'completed', $response['status'] );
		$this->assertSame( '<h1>World</h1>', $response['snapshot']['html'] );
		$this->assertSame( 15, $response['usage']['inputTokens'] );
		$this->assertSame( 5, $response['usage']['outputTokens'] );
		$this->assertSame( 'final', end( $response['events'] )['event'] );
		$this->assertSame( 'Changed the heading.', end( $response['events'] )['summary'] );
	}

	/** Client errors become safe terminal job errors. */
	public function test_client_failure_releases_lock_and_is_retryable_false(): void {
		$fake = new Ai_Client_Fake();
		add_filter(
			'kayzart_ai_client',
			function () use ( $fake ) {
				return $fake;
			}
		);
		$uuid = $this->create_job( 'worker-failure' );

		Ai_Worker::run( $uuid );

		$response = $this->store->to_response( $this->store->get( $uuid ) );
		$this->assertSame( 'error', $response['status'] );
		$this->assertFalse( $response['error']['retryable'] );
		$this->assertNull( $this->store->get( $uuid )['lock_key'] );
	}

	/** Canceled pending jobs cannot subsequently run. */
	public function test_canceled_pending_job_cannot_be_claimed_or_completed(): void {
		$uuid = $this->create_job( 'worker-cancel' );
		$this->store->request_cancel( $uuid );

		Ai_Worker::run( $uuid );

		$this->assertSame( 'canceled', $this->store->get( $uuid )['status'] );
	}

	/** Action Scheduler failures become errors without leaving the post locked. */
	public function test_action_scheduler_failure_marks_job_error(): void {
		$uuid      = $this->create_job( 'worker-scheduler-failure' );
		$action_id = as_enqueue_async_action( Ai_Worker::RUN_HOOK, array( $uuid ), Ai_Worker::GROUP, true );

		Ai_Worker::handle_failed_action( (int) $action_id );

		$job = $this->store->get( $uuid );
		$this->assertSame( 'error', $job['status'] );
		$this->assertNull( $job['lock_key'] );
		$this->assertTrue( $this->store->to_response( $job )['error']['retryable'] );
	}

	/** New jobs capture the filtered mode and its matching public timeout. */
	public function test_execution_mode_is_captured_at_creation(): void {
		$stepwise = $this->create_job( 'worker-stepwise-mode', 20 );
		$step_job = $this->store->get( $stepwise );
		$this->assertSame( 'stepwise', $step_job['execution_mode'] );
		$this->assertSame( 1800000, $this->store->to_response( $step_job )['timeoutMs'] );
		$step_action = Ai_Worker::enqueue( $stepwise );
		$this->assertSame( Ai_Worker::STEP_HOOK, ActionScheduler::store()->fetch_action( $step_action['run_action_id'] )->get_hook() );
		$this->store->request_cancel( $stepwise );
		Ai_Worker::unschedule_job( $stepwise );

		add_filter(
			'kayzart_ai_execution_mode',
			static function () {
				return 'legacy';
			}
		);
		$legacy     = $this->create_job( 'worker-legacy-mode', 21 );
		$legacy_job = $this->store->get( $legacy );
		$this->assertSame( 'legacy', $legacy_job['execution_mode'] );
		$this->assertSame( 600000, $this->store->to_response( $legacy_job )['timeoutMs'] );
		$legacy_action = Ai_Worker::enqueue( $legacy );
		$this->assertSame( Ai_Worker::RUN_HOOK, ActionScheduler::store()->fetch_action( $legacy_action['run_action_id'] )->get_hook() );
	}

	/** Step actions persist checkpoints and stale versions cannot call the model twice. */
	public function test_step_worker_completes_across_actions_and_rejects_stale_version(): void {
		$fake = new Ai_Client_Fake(
			array(
				array(
					'toolCalls' => array(
						Ai_Message::tool_call(
							'r1',
							'replace_string',
							array(
								'target' => 'html',
								'from'   => 'Hello',
								'to'     => 'World',
							)
						),
					),
				),
				array( 'text' => 'Done' ),
				array( 'text' => '{"summary":"Changed the heading."}' ),
			)
		);
		add_filter(
			'kayzart_ai_client',
			static function () use ( $fake ) {
				return $fake;
			}
		);
		$uuid = $this->create_job( 'worker-step-actions', 22 );

		Ai_Worker::run_step( $uuid, 0, 'first' );
		$this->assertSame( 1, (int) $this->store->get( $uuid )['state_version'] );
		$this->assertCount( 1, $fake->calls() );
		Ai_Worker::run_step( $uuid, 0, 'stale' );
		$this->assertCount( 1, $fake->calls() );

		Ai_Worker::run_step( $uuid, 1, 'second' );
		$this->assertSame( 2, (int) $this->store->get( $uuid )['state_version'] );
		$this->assertCount( 2, $fake->calls() );
		Ai_Worker::run_step( $uuid, 2, 'third' );

		$job = $this->store->get( $uuid );
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( '<h1>World</h1>', $this->store->to_response( $job )['snapshot']['html'] );
		$this->assertCount( 3, $fake->calls() );
		$this->assertNull( $job['step_lease_token'] );
	}

	/** Retryable provider failures retain the checkpoint and succeed on attempt three. */
	public function test_step_worker_retries_retryable_provider_failure_twice(): void {
		$client = new class() implements \KayzArt\Ai_Client_Interface {
			/** Number of provider calls.
			 *
			 * @var int
			 */
			public $calls = 0;

			/** Report test-client availability. */
			public function is_available(): bool {
				return true;
			}

			/** Return one test provider result.
			 *
			 * @param array $messages Normalized messages.
			 * @param array $tools    Tool definitions.
			 * @param array $options  Generation options.
			 * @return array
			 * @throws \KayzArt\Ai_Client_Exception During the first two calls.
			 */
			public function generate( array $messages, array $tools, array $options = array() ): array {
				unset( $messages, $tools, $options );
				++$this->calls;
				if ( $this->calls < 3 ) {
					throw new \KayzArt\Ai_Client_Exception( 'Temporary provider failure.', true );
				}
				return array(
					'toolCalls' => array(
						Ai_Message::tool_call(
							'r1',
							'replace_string',
							array(
								'target' => 'html',
								'from'   => 'Hello',
								'to'     => 'World',
							)
						),
					),
					'text'      => '',
					'usage'     => array(),
					'model'     => 'fake',
				);
			}
		};
		add_filter(
			'kayzart_ai_client',
			static function () use ( $client ) {
				return $client;
			}
		);
		$uuid = $this->create_job( 'worker-step-retry', 23 );

		Ai_Worker::run_step( $uuid, 0, 'attempt-one' );
		$this->assertSame( 1, (int) $this->store->get( $uuid )['step_attempt'] );
		Ai_Worker::run_step( $uuid, 0, 'attempt-two' );
		$this->assertSame( 2, (int) $this->store->get( $uuid )['step_attempt'] );
		Ai_Worker::run_step( $uuid, 0, 'attempt-three' );

		$this->assertSame( 3, $client->calls );
		$this->assertSame( 1, (int) $this->store->get( $uuid )['state_version'] );
		$this->assertSame( 0, (int) $this->store->get( $uuid )['step_attempt'] );
	}

	/** Recovery enqueues the persisted version after a worker lease expires. */
	public function test_recovery_enqueues_expired_step_lease(): void {
		$uuid = $this->create_job( 'worker-step-recovery', 24 );
		$this->assertTrue( $this->store->claim( $uuid ) );
		global $wpdb;
		$wpdb->update(
			Ai_Setup::get_jobs_table_name(),
			array(
				'agent_state_json'      => '{}',
				'state_version'         => 4,
				'step_attempt'          => 1,
				'step_lease_token'      => 'abandoned',
				'step_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ),
			),
			array( 'job_uuid' => $uuid )
		);

		Ai_Worker::recover_steps();

		$id     = ActionScheduler::store()->find_action( Ai_Worker::STEP_HOOK, array( 'group' => Ai_Worker::GROUP ) );
		$action = ActionScheduler::store()->fetch_action( $id );
		$this->assertSame( $uuid, $action->get_args()[0] );
		$this->assertSame( 4, (int) $action->get_args()[1] );
	}

	/** Create a pending test job.
	 *
	 * @param string $request_id Request ID.
	 * @param int    $post_id    Post ID.
	 */
	private function create_job( string $request_id, int $post_id = 20 ): string {
		$result = $this->store->create(
			1,
			$post_id,
			$request_id,
			array(
				'editorMode'       => 'normal',
				'prompt'           => 'Change Hello to World.',
				'html'             => '<h1>Hello</h1>',
				'customHead'       => '',
				'css'              => '',
				'js'               => '',
				'jsMode'           => 'classic',
				'baseHash'         => '',
				'selectedContexts' => array(),
			)
		);
		return $result['job']['job_uuid'];
	}
}
