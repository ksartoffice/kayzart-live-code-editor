<?php
/**
 * Durable AI timeline tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Setup;
use KayzArt\Ai_Timeline_Store;

/** Exercises durable activities, paging, context, and snapshot expiry. */
class Test_Kayzart_Ai_Timeline_Store extends WP_UnitTestCase {
	/** @var Ai_Timeline_Store */
	private $store;

	/** Prepare empty AI tables. */
	protected function setUp(): void {
		parent::setUp();
		Ai_Setup::activate();
		$this->store = new Ai_Timeline_Store();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_timeline_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Job-backed activity creation is idempotent and completion stays lightweight. */
	public function test_ai_edit_is_idempotent_and_builds_recent_context(): void {
		$job     = $this->job( 'request-context' );
		$payload = $this->payload( 'Make the heading stronger.' );
		$first   = $this->store->create_ai_edit( $job, $payload );
		$again   = $this->store->create_ai_edit( $job, $payload );
		$this->assertSame( $first['id'], $again['id'] );

		$after         = $payload;
		$after['html'] = '<h1>Strong heading</h1>';
		$this->assertTrue( $this->store->complete( $job['job_uuid'], $payload, $after, str_repeat( 'a', 800 ) ) );
		$item = $this->store->to_response( $this->store->get( (int) $first['id'] ) );
		$this->assertSame( array( 'html' ), $item['changedTargets'] );
		$this->assertArrayNotHasKey( 'summary', $item );
		$context = $this->store->recent_context( 42 );
		$this->assertCount( 1, $context );
		$this->assertLessThanOrEqual( 512, strlen( $context[0]['summary'] ) );
	}

	/** Completing an edit persists the model and input/output token counts. */
	public function test_complete_persists_model_and_token_usage(): void {
		$job     = $this->job( 'request-usage' );
		$payload = $this->payload( 'Tighten the copy.' );
		$first   = $this->store->create_ai_edit( $job, $payload );

		$after         = $payload;
		$after['html'] = '<h1>Tighter</h1>';
		$usage         = array(
			'inputTokens'  => 1234,
			'outputTokens' => 567,
			'model'        => 'gpt-4o',
		);
		$this->assertTrue( $this->store->complete( $job['job_uuid'], $payload, $after, 'Done.', $usage ) );

		$item = $this->store->to_response( $this->store->get( (int) $first['id'] ) );
		$this->assertSame( 'gpt-4o', $item['model'] );
		$this->assertSame( 1234, $item['inputTokens'] );
		$this->assertSame( 567, $item['outputTokens'] );
	}

	/** Completing without usage leaves model null and tokens zero. */
	public function test_complete_without_usage_defaults_model_null(): void {
		$job     = $this->job( 'request-no-usage' );
		$payload = $this->payload( 'No usage provided.' );
		$first   = $this->store->create_ai_edit( $job, $payload );

		$after         = $payload;
		$after['html'] = '<h1>Changed</h1>';
		$this->assertTrue( $this->store->complete( $job['job_uuid'], $payload, $after, 'Done.' ) );

		$item = $this->store->to_response( $this->store->get( (int) $first['id'] ) );
		$this->assertNull( $item['model'] );
		$this->assertSame( 0, $item['inputTokens'] );
		$this->assertSame( 0, $item['outputTokens'] );
	}

	/** Cursor pages contain 50 stable chronological rows without overlap. */
	public function test_cursor_paging_returns_fifty_rows_at_a_time(): void {
		for ( $index = 1; $index <= 55; $index++ ) {
			$this->store->create_ai_edit( $this->job( 'request-' . $index ), $this->payload( 'Prompt ' . $index ) );
		}
		$newest = $this->store->list_for_post( 42 );
		$this->assertCount( 50, $newest['items'] );
		$this->assertTrue( $newest['hasMore'] );
		$this->assertSame( 'Prompt 6', $newest['items'][0]['prompt'] );
		$older = $this->store->list_for_post( 42, (int) $newest['nextCursor'] );
		$this->assertCount( 5, $older['items'] );
		$this->assertFalse( $older['hasMore'] );
		$this->assertSame( 'Prompt 1', $older['items'][0]['prompt'] );
	}

	/** Save and restore rows are durable while job cleanup expires snapshots only. */
	public function test_job_cleanup_keeps_timeline_and_expires_snapshot(): void {
		$jobs    = new Ai_Job_Store();
		$payload = $this->payload( 'Edit retained briefly.' );
		$created = $jobs->create( 1, 42, 'request-retention', $payload );
		$job     = $created['job'];
		$activity = $this->store->create_ai_edit( $job, $payload );
		$this->assertNotNull( $this->store->get_snapshot( $activity, 'before' ) );

		global $wpdb;
		$wpdb->update( Ai_Setup::get_jobs_table_name(), array( 'status' => 'error', 'finished_at' => '2000-01-01 00:00:00', 'lock_key' => null ), array( 'job_uuid' => $job['job_uuid'] ) );
		$this->assertSame( 1, $jobs->cleanup_terminal() );
		$this->assertNotNull( $this->store->get( (int) $activity['id'] ) );
		$this->assertNull( $this->store->get_snapshot( $activity, 'before' ) );
	}

	/** Save and restore activities deduplicate revisions and disappear only with the post. */
	public function test_save_restore_and_permanent_post_deletion(): void {
		$source = $this->store->create_ai_edit( $this->job( 'request-restore' ), $this->payload( 'Edit me.' ) );
		$save   = $this->store->record_save( 42, 1, 9876 );
		$this->assertSame( $save['id'], $this->store->record_save( 42, 1, 9876 )['id'] );
		$restore = $this->store->record_restore( $source, 1, 'before' );
		$this->assertSame( (string) $source['id'], (string) $restore['source_activity_id'] );
		$timeline = $this->store->list_for_post( 42 );
		$this->assertCount( 3, $timeline['items'] );
		$this->assertFalse( $timeline['items'][1]['revisionAvailable'] );
		$this->assertSame( 3, $this->store->delete_for_post( 42 ) );
		$this->assertNull( $this->store->get( (int) $source['id'] ) );
	}

	/** Build a unique job-shaped row. */
	private function job( string $request_id ): array {
		return array( 'job_uuid' => wp_generate_uuid4(), 'post_id' => 42, 'user_id' => 1, 'request_id' => $request_id, 'status' => 'pending' );
	}

	/** Build an agent payload. */
	private function payload( string $prompt ): array {
		return array( 'prompt' => $prompt, 'html' => '<h1>Hello</h1>', 'customHead' => '', 'css' => '', 'js' => '', 'jsMode' => 'classic', 'selectedContexts' => array() );
	}
}
