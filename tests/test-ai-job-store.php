<?php
/**
 * AI job store tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Setup;

/** Exercises idempotency, locking, transitions, and retention. */
class Test_Kayzart_Ai_Job_Store extends WP_UnitTestCase {
	/** Job store under test.
	 *
	 * @var Ai_Job_Store
	 */
	private $store;

	/** Prepare an empty v2 job table. */
	protected function setUp(): void {
		parent::setUp();
		Ai_Setup::activate();
		$this->store = new Ai_Job_Store();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Identical retries reuse a job while conflicting input does not. */
	public function test_create_is_idempotent_and_post_lock_is_unique(): void {
		$payload = $this->payload();
		$first   = $this->store->create( 10, 20, 'request-one', $payload );
		$again   = $this->store->create( 10, 20, 'request-one', $payload );

		$this->assertTrue( $first['is_new'] );
		$this->assertFalse( $again['is_new'] );
		$this->assertSame( $first['job']['job_uuid'], $again['job']['job_uuid'] );
		$this->assertSame( 'pending', $first['job']['status'] );

		$conflict = $this->store->create( 10, 21, 'request-one', $payload );
		$this->assertWPError( $conflict );
		$this->assertSame( 409, $conflict->get_error_data()['status'] );

		$locked = $this->store->create( 11, 20, 'request-two', $payload );
		$this->assertWPError( $locked );
		$this->assertSame( 'kayzart_ai_post_locked', $locked->get_error_code() );
	}

	/** Terminal transitions cannot be overwritten and release the post lock. */
	public function test_conditional_transitions_release_lock_and_preserve_terminal_state(): void {
		$created = $this->store->create( 10, 20, 'request-transition', $this->payload() );
		$uuid    = $created['job']['job_uuid'];
		$this->assertTrue( $this->store->claim( $uuid ) );
		$this->assertFalse( $this->store->claim( $uuid ) );
		$this->assertTrue( $this->store->mark_canceled( $uuid ) );
		$this->assertFalse( $this->store->complete( $uuid, array( 'html' => 'changed' ), 'done', array() ) );

		$job = $this->store->get( $uuid );
		$this->assertSame( 'canceled', $job['status'] );
		$this->assertNull( $job['lock_key'] );
		$this->assertNotNull( $job['finished_at'] );
	}

	/** Events retain only the newest 300 entries and carry request IDs. */
	public function test_events_are_correlated_and_capped_at_three_hundred(): void {
		$created = $this->store->create( 10, 20, 'request-events', $this->payload() );
		$uuid    = $created['job']['job_uuid'];
		for ( $index = 0; $index < 305; $index++ ) {
			$this->assertTrue(
				$this->store->append_event(
					$uuid,
					array(
						'event' => 'progress',
						'index' => $index,
					)
				)
			);
		}
		$response = $this->store->to_response( $this->store->get( $uuid ) );
		$this->assertCount( 300, $response['events'] );
		$this->assertSame( 5, $response['events'][0]['index'] );
		$this->assertSame( 'request-events', $response['events'][0]['requestId'] );
	}

	/** Deadlines and retention affect only eligible records. */
	public function test_timeout_and_cleanup_only_affect_eligible_jobs(): void {
		global $wpdb;
		$created = $this->store->create( 10, 20, 'request-expired', $this->payload() );
		$uuid    = $created['job']['job_uuid'];
		$wpdb->update( Ai_Setup::get_jobs_table_name(), array( 'deadline_at' => '2000-01-01 00:00:00' ), array( 'job_uuid' => $uuid ) );
		$this->assertTrue( $this->store->expire_overdue( $uuid ) );
		$this->assertSame( 'timed_out', $this->store->get( $uuid )['status'] );

		$wpdb->update( Ai_Setup::get_jobs_table_name(), array( 'finished_at' => '2000-01-01 00:00:00' ), array( 'job_uuid' => $uuid ) );
		$this->assertSame( 1, $this->store->cleanup_terminal() );
		$this->assertNull( $this->store->get( $uuid ) );
	}

	/** Build a canonical test payload. */
	private function payload(): array {
		return array(
			'editorMode'       => 'normal',
			'prompt'           => 'Change the heading.',
			'html'             => '<h1>Hello</h1>',
			'customHead'       => '',
			'css'              => '',
			'js'               => '',
			'jsMode'           => 'classic',
			'baseHash'         => '',
			'selectedContexts' => array(),
		);
	}
}
