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
	}

	/** Remove client substitutions. */
	protected function tearDown(): void {
		remove_all_filters( 'kayzart_ai_client' );
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

	/** Create a pending test job.
	 *
	 * @param string $request_id Request ID.
	 */
	private function create_job( string $request_id ): string {
		$result = $this->store->create(
			1,
			20,
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
