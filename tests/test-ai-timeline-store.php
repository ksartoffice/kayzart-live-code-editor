<?php
/**
 * Durable AI timeline tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Setup;
use KayzArt\Ai_Timeline_Store;
use KayzArt\Ai_Tools;

/** Exercises durable activities, paging, context, and snapshot expiry. */
class Test_Kayzart_Ai_Timeline_Store extends WP_UnitTestCase {
	/** Timeline store under test.
	 *
	 * @var Ai_Timeline_Store
	 */
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
		$this->assertSame( $first['activity_uuid'], $context[0]['versionId'] );
		$this->assertFalse( $context[0]['detailsAvailable'] );
	}

	/** Recent context can be reduced to the single latest successful edit. */
	public function test_recent_context_accepts_a_limit(): void {
		$first_after         = $this->payload( 'First edit.' );
		$first_after['html'] = '<h1>First</h1>';
		$this->complete_retained_edit( 'request-context-limit-1', $this->payload( 'First edit.' ), $first_after );
		$second               = $first_after;
		$second['prompt']     = 'Second edit.';
		$second_after         = $second;
		$second_after['html'] = '<h1>Second</h1>';
		$this->complete_retained_edit( 'request-context-limit-2', $second, $second_after );

		$context = $this->store->recent_context( 42, $second_after, 1 );
		$this->assertCount( 1, $context );
		$this->assertSame( 'Second edit.', $context[0]['prompt'] );
		$this->assertTrue( $context[0]['detailsAvailable'] );
		$this->assertSame( array(), $this->store->recent_context( 42, $second_after, 0 ) );
	}

	/** History tools stay post-scoped and page retained source with opaque cursors. */
	public function test_history_tools_list_and_page_retained_source(): void {
		$before         = $this->payload( 'Expand the heading.' );
		$before['html'] = '<h1>Before history source</h1>';
		$after          = $before;
		$after['html']  = '<h1>After history source</h1>';
		$job            = $this->complete_retained_edit( 'request-history-tool', $before, $after );

		$list = $this->store->list_ai_edits_for_tool( 42, array( 'limit' => 1 ) );
		$this->assertTrue( $list['ok'] );
		$this->assertCount( 1, $list['items'] );
		$this->assertTrue( $list['items'][0]['detailsAvailable'] );
		$version_id = $list['items'][0]['versionId'];

		$metadata = $this->store->get_ai_edit_for_tool( 42, array( 'versionId' => $version_id ) );
		$this->assertTrue( $metadata['ok'] );
		$this->assertSame( 'Expand the heading.', $metadata['prompt'] );
		$this->assertFalse( $this->store->get_ai_edit_for_tool( 99, array( 'versionId' => $version_id ) )['ok'] );

		$first = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $version_id,
				'snapshot'  => 'after',
				'target'    => 'html',
				'maxChars'  => 8,
			)
		);
		$this->assertTrue( $first['truncated'] );
		$this->assertSame( '<h1>Afte', $first['content'] );
		$second = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $version_id,
				'snapshot'  => 'after',
				'target'    => 'html',
				'cursor'    => $first['nextCursor'],
				'maxChars'  => 100,
			)
		);
		$this->assertStringStartsWith( 'r history', $second['content'] );
		$this->assertFalse( $second['truncated'] );

		$changed_after         = $after;
		$changed_after['html'] = '<h1>Changed after cursor creation</h1>';
		global $wpdb;
		$wpdb->update(
			Ai_Setup::get_jobs_table_name(),
			array( 'snapshot_json' => wp_json_encode( $changed_after ) ),
			array( 'job_uuid' => $job['job_uuid'] )
		);
		$stale_cursor = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $version_id,
				'snapshot'  => 'after',
				'target'    => 'html',
				'cursor'    => $first['nextCursor'],
			)
		);
		$this->assertFalse( $stale_cursor['ok'] );
		$this->assertSame( 'History source cursor is invalid or stale.', $stale_cursor['error'] );

		$invalid_cursor = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $version_id,
				'snapshot'  => 'after',
				'target'    => 'html',
				'cursor'    => 'invalid-cursor',
			)
		);
		$this->assertFalse( $invalid_cursor['ok'] );
		$this->assertSame( 'History source cursor is invalid or stale.', $invalid_cursor['error'] );

		$missing_target = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $version_id,
				'snapshot'  => 'after',
			)
		);
		$this->assertFalse( $missing_target['ok'] );
		$this->assertSame( 'snapshot and target are required to read retained source.', $missing_target['error'] );
	}

	/** AI history metadata uses bounded, post-scoped cursor pages. */
	public function test_ai_history_tool_pages_ten_compact_items(): void {
		for ( $index = 1; $index <= 12; $index++ ) {
			$prompt  = 'Prompt ' . $index . ' ' . str_repeat( 'p', 300 );
			$payload = $this->payload( $prompt );
			$this->complete_retained_edit( 'request-ai-history-page-' . $index, $payload, $payload, str_repeat( 's', 300 ) );
		}

		$newest = $this->store->list_ai_edits_for_tool( 42, array() );
		$this->assertTrue( $newest['ok'] );
		$this->assertCount( 10, $newest['items'] );
		$this->assertTrue( $newest['hasMore'] );
		$this->assertNotEmpty( $newest['nextCursor'] );
		$this->assertStringStartsWith( 'Prompt 12 ', $newest['items'][0]['prompt'] );
		$this->assertStringStartsWith( 'Prompt 3 ', $newest['items'][9]['prompt'] );
		$this->assertLessThanOrEqual( 256, strlen( $newest['items'][0]['prompt'] ) );
		$this->assertLessThanOrEqual( 256, strlen( $newest['items'][0]['summary'] ) );
		$this->assertCount( 10, $this->store->list_ai_edits_for_tool( 42, array( 'limit' => 50 ) )['items'] );

		$older = $this->store->list_ai_edits_for_tool( 42, array( 'cursor' => $newest['nextCursor'] ) );
		$this->assertTrue( $older['ok'] );
		$this->assertCount( 2, $older['items'] );
		$this->assertFalse( $older['hasMore'] );
		$this->assertNull( $older['nextCursor'] );
		$this->assertStringStartsWith( 'Prompt 2 ', $older['items'][0]['prompt'] );
		$this->assertStringStartsWith( 'Prompt 1 ', $older['items'][1]['prompt'] );

		$invalid = $this->store->list_ai_edits_for_tool( 42, array( 'cursor' => 'invalid-cursor' ) );
		$this->assertFalse( $invalid['ok'] );
		$cross_post = $this->store->list_ai_edits_for_tool( 99, array( 'cursor' => $newest['nextCursor'] ) );
		$this->assertFalse( $cross_post['ok'] );
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

	/** Retained snapshots expose JavaScript modes for browser identity checks. */
	public function test_retained_job_exposes_snapshot_js_modes_only_while_available(): void {
		$before          = $this->payload( 'Change the module mode.' );
		$after           = $before;
		$after['jsMode'] = 'module';
		$job             = $this->complete_retained_edit( 'request-snapshot-modes', $before, $after );

		$item = $this->store->list_for_post( 42 )['items'][0];
		$this->assertSame( 'classic', $item['beforeJsMode'] );
		$this->assertSame( 'module', $item['afterJsMode'] );

		global $wpdb;
		$wpdb->delete( Ai_Setup::get_jobs_table_name(), array( 'job_uuid' => $job['job_uuid'] ), array( '%s' ) );
		$expired = $this->store->list_for_post( 42 )['items'][0];
		$this->assertNull( $expired['beforeJsMode'] );
		$this->assertNull( $expired['afterJsMode'] );
		$history = $this->store->list_ai_edits_for_tool( 42, array( 'limit' => 1 ) );
		$this->assertFalse( $history['items'][0]['detailsAvailable'] );
		$detail = $this->store->get_ai_edit_for_tool(
			42,
			array(
				'versionId' => $history['items'][0]['versionId'],
				'snapshot'  => 'after',
				'target'    => 'html',
			)
		);
		$this->assertFalse( $detail['ok'] );
		$this->assertFalse( $detail['detailsAvailable'] );
	}

	/** Retained input snapshots recompute their browser identity hash. */
	public function test_retained_before_snapshot_includes_computed_base_hash(): void {
		$before             = $this->payload( 'Keep this input identity.' );
		$before['baseHash'] = 'stale-client-value';
		$job                = $this->complete_retained_edit( 'request-before-hash', $before, $before );
		$activity           = $this->store->get_by_job( $job['job_uuid'] );
		$snapshot           = $this->store->get_snapshot( $activity, 'before' );

		$this->assertSame( Ai_Tools::compute_base_hash( $before['html'], $before['customHead'], $before['css'], $before['js'] ), $snapshot['baseHash'] );
		$this->assertNotSame( 'stale-client-value', $snapshot['baseHash'] );
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

	/** A retained latest edit exposes only its exact local CSS footprint. */
	public function test_recent_context_builds_local_edit_footprint(): void {
		$before        = $this->payload( 'Make the main button green.' );
		$before['css'] = ":root {\n  --blue: #2563eb;\n}\n\n.button-primary {\n  background: var(--blue);\n}\n";
		$after         = $before;
		$after['css']  = ":root {\n  --blue: #2563eb;\n}\n\n.button-primary {\n  background: #16a34a;\n}\n";
		$this->complete_retained_edit( 'request-footprint', $before, $after );

		$context   = $this->store->recent_context( 42, $after );
		$footprint = $context[0]['editFootprint'];
		$this->assertSame( 'snapshot_hash', $footprint['validation'] );
		$this->assertCount( 1, $footprint['changes'] );
		$this->assertSame( 'css', $footprint['changes'][0]['target'] );
		$this->assertStringContainsString( '.button-primary', $footprint['changes'][0]['after'] );
		$this->assertStringContainsString( 'background: #16a34a;', $footprint['changes'][0]['after'] );
		$this->assertStringNotContainsString( '--blue:', $footprint['changes'][0]['after'] );
	}

	/** Manual edits elsewhere retain a uniquely matching current hunk. */
	public function test_recent_context_validates_footprint_after_unrelated_manual_edit(): void {
		$before        = $this->payload( 'Make the main button green.' );
		$before['css'] = ".button-primary {\n  background: var(--blue);\n}\n";
		$after         = $before;
		$after['css']  = ".button-primary {\n  background: #16a34a;\n}\n";
		$this->complete_retained_edit( 'request-manual-elsewhere', $before, $after );

		$current         = $after;
		$current['html'] = '<h1>Manually changed elsewhere</h1>';
		$context         = $this->store->recent_context( 42, $current );
		$this->assertSame( 'unique_after_match', $context[0]['editFootprint']['validation'] );
		$this->assertCount( 1, $context[0]['editFootprint']['changes'] );
	}

	/** A stale or ambiguous local hunk is omitted instead of becoming prompt noise. */
	public function test_recent_context_omits_stale_or_ambiguous_footprint(): void {
		$before        = $this->payload( 'Make the main button green.' );
		$before['css'] = ".button-primary {\n  background: var(--blue);\n}\n";
		$after         = $before;
		$after['css']  = ".button-primary {\n  background: #16a34a;\n}\n";
		$this->complete_retained_edit( 'request-stale-footprint', $before, $after );

		$stale        = $after;
		$stale['css'] = ".button-primary {\n  background: #15803d;\n}\n";
		$this->assertArrayNotHasKey( 'editFootprint', $this->store->recent_context( 42, $stale )[0] );

		$ambiguous        = $after;
		$ambiguous['css'] = $after['css'] . "\n" . $after['css'];
		$this->assertArrayNotHasKey( 'editFootprint', $this->store->recent_context( 42, $ambiguous )[0] );
	}

	/** Only the latest retained history item receives a strictly bounded footprint. */
	public function test_recent_context_limits_footprint_to_latest_item_and_budget(): void {
		$first               = $this->payload( 'First edit.' );
		$first_after         = $first;
		$first_after['html'] = '<h1>First</h1>';
		$this->complete_retained_edit( 'request-first-footprint', $first, $first_after );

		$second              = $first_after;
		$second['prompt']    = 'Change several styles.';
		$second['css']       = ".one { color: red; }\n.two { color: red; }\n.three { color: red; }\n";
		$second_after        = $second;
		$second_after['css'] = ".one { color: green; }\n.two { color: green; }\n.three { color: green; }\n";
		$this->complete_retained_edit( 'request-second-footprint', $second, $second_after );

		$context = $this->store->recent_context( 42, $second_after );
		$this->assertCount( 2, $context );
		$this->assertArrayNotHasKey( 'editFootprint', $context[0] );
		$this->assertArrayHasKey( 'editFootprint', $context[1] );
		$this->assertLessThanOrEqual( 2, count( $context[1]['editFootprint']['changes'] ) );
		$content_chars = 0;
		foreach ( $context[1]['editFootprint']['changes'] as $change ) {
			$content_chars += mb_strlen( $change['before'] ) + mb_strlen( $change['after'] );
		}
		$this->assertLessThanOrEqual( 600, $content_chars );
		$this->assertLessThanOrEqual( 2400, strlen( wp_json_encode( $context[1]['editFootprint'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
	}

	/** Expired retained snapshots leave the durable lightweight context intact. */
	public function test_recent_context_omits_footprint_after_job_cleanup(): void {
		$before        = $this->payload( 'Temporary detail.' );
		$after         = $before;
		$after['html'] = '<h1>After</h1>';
		$job           = $this->complete_retained_edit( 'request-expired-footprint', $before, $after );
		global $wpdb;
		$wpdb->delete( Ai_Setup::get_jobs_table_name(), array( 'job_uuid' => $job['job_uuid'] ), array( '%s' ) );
		$context = $this->store->recent_context( 42, $after );
		$this->assertCount( 1, $context );
		$this->assertArrayNotHasKey( 'editFootprint', $context[0] );
		$this->assertSame( 'Temporary detail.', $context[0]['prompt'] );
	}

	/** Footprints cover all editable targets plus JavaScript mode changes. */
	public function test_recent_context_footprint_covers_supported_change_kinds(): void {
		$cases = array(
			array( 'html', 'html', '<h1>Before</h1>', '<h1>After</h1>', 'replace' ),
			array( 'head', 'customHead', '', '<meta name="theme-color" content="#fff">', 'insert' ),
			array( 'js', 'js', 'window.ready = true;', '', 'delete' ),
		);
		foreach ( $cases as $index => $case ) {
			$before             = $this->payload( 'Change target.' );
			$before[ $case[1] ] = $case[2];
			$after              = $before;
			$after[ $case[1] ]  = $case[3];
			$this->complete_retained_edit( 'request-target-' . $index, $before, $after );
			$context = $this->store->recent_context( 42, $after );
			$change  = $context[ count( $context ) - 1 ]['editFootprint']['changes'][0];
			$this->assertSame( $case[0], $change['target'] );
			$this->assertSame( $case[4], $change['kind'] );
		}

		$before          = $this->payload( 'Use modules.' );
		$after           = $before;
		$after['jsMode'] = 'module';
		$this->complete_retained_edit( 'request-js-mode-footprint', $before, $after );
		$context = $this->store->recent_context( 42, $after );
		$change  = $context[ count( $context ) - 1 ]['editFootprint']['changes'][0];
		$this->assertSame( 'jsMode', $change['target'] );
		$this->assertSame( 'classic', $change['before'] );
		$this->assertSame( 'module', $change['after'] );
	}

	/** Retained jobs expose exact display stats and worker duration without exposing snapshots. */
	public function test_retained_job_exposes_change_stats_and_duration_only_while_available(): void {
		$jobs            = new Ai_Job_Store();
		$payload         = $this->payload( 'Add a feature list.' );
		$payload['html'] = "<main>\n<p>Before</p>\n</main>\n";
		$created         = $jobs->create( 1, 42, 'request-display-stats', $payload );
		$job             = $created['job'];
		$this->store->create_ai_edit( $job, $payload );
		$this->assertTrue( $jobs->claim( (string) $job['job_uuid'] ) );
		$after         = $payload;
		$after['html'] = "<main>\n<section>New</section>\n</main>\n";
		$after['css']  = ".feature {\n color: green;\n}\n";
		$this->assertTrue( $jobs->complete( (string) $job['job_uuid'], $after, 'Hidden summary.', array() ) );

		$timeline = $this->store->list_for_post( 42 );
		$item     = $timeline['items'][0];
		$this->assertSame(
			array(
				'added'   => 1,
				'removed' => 1,
			),
			$item['changeStats']['html']
		);
		$this->assertSame(
			array(
				'added'   => 3,
				'removed' => 0,
			),
			$item['changeStats']['css']
		);
		$this->assertIsInt( $item['durationSeconds'] );
		$this->assertSame( Ai_Job_Store::STEPWISE_TIMEOUT_SECONDS * 1000, $item['timeoutMs'] );
		$this->assertStringEndsWith( '+00:00', $item['createdAt'] );
		$this->assertArrayNotHasKey( 'snapshot', $item );
		$this->assertArrayNotHasKey( 'summary', $item );

		global $wpdb;
		$wpdb->delete( Ai_Setup::get_jobs_table_name(), array( 'job_uuid' => $job['job_uuid'] ), array( '%s' ) );
		$expired = $this->store->list_for_post( 42 )['items'][0];
		$this->assertNull( $expired['changeStats'] );
		$this->assertNull( $expired['durationSeconds'] );
		$this->assertNull( $expired['timeoutMs'] );
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
		$jobs     = new Ai_Job_Store();
		$payload  = $this->payload( 'Edit retained briefly.' );
		$created  = $jobs->create( 1, 42, 'request-retention', $payload );
		$job      = $created['job'];
		$activity = $this->store->create_ai_edit( $job, $payload );
		$this->assertNotNull( $this->store->get_snapshot( $activity, 'before' ) );

		global $wpdb;
		$wpdb->update(
			Ai_Setup::get_jobs_table_name(),
			array(
				'status'      => 'error',
				'finished_at' => '2000-01-01 00:00:00',
				'lock_key'    => null,
			),
			array( 'job_uuid' => $job['job_uuid'] )
		);
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

	/**
	 * Build a unique job-shaped row.
	 *
	 * @param string $request_id Request correlation id.
	 */
	private function job( string $request_id ): array {
		return array(
			'job_uuid'   => wp_generate_uuid4(),
			'post_id'    => 42,
			'user_id'    => 1,
			'request_id' => $request_id,
			'status'     => 'pending',
		);
	}

	/**
	 * Build an agent payload.
	 *
	 * @param string $prompt User instruction.
	 */
	private function payload( string $prompt ): array {
		return array(
			'prompt'           => $prompt,
			'html'             => '<h1>Hello</h1>',
			'customHead'       => '',
			'css'              => '',
			'js'               => '',
			'jsMode'           => 'classic',
			'selectedContexts' => array(),
		);
	}

	/** Create, run, and complete one retained job-backed edit.
	 *
	 * @param string $request_id Unique request ID.
	 * @param array  $before     Input snapshot.
	 * @param array  $after      Completed snapshot.
	 * @param string $summary    Completion summary.
	 */
	private function complete_retained_edit( string $request_id, array $before, array $after, string $summary = 'Completed edit.' ): array {
		$jobs    = new Ai_Job_Store();
		$created = $jobs->create( 1, 42, $request_id, $before );
		$job     = $created['job'];
		$this->store->create_ai_edit( $job, $before );
		$this->assertTrue( $jobs->claim( (string) $job['job_uuid'] ) );
		$this->assertTrue( $jobs->complete( (string) $job['job_uuid'], $after, $summary, array() ) );
		return $job;
	}
}
