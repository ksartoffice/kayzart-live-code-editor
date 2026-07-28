<?php
/**
 * AI job REST API tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Immediate_Dispatcher;
use KayzArt\Ai_Setup;
use KayzArt\Ai_Worker;
use KayzArt\Post_Type;

/** Verifies API validation, idempotency, authorization, and cancellation. */
class Test_Kayzart_Rest_Ai extends WP_UnitTestCase {
	/** Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/** Target post ID.
	 *
	 * @var int
	 */
	private $post_id;

	/** Number of immediate loopback requests observed during a test.
	 *
	 * @var int
	 */
	private $immediate_dispatches = 0;

	/** Timeline write type to make fail for a REST error-path test.
	 *
	 * @var string
	 */
	private $timeline_write_failure = '';

	/** Prepare REST routes, permissions, and an empty table. */
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
		Ai_Setup::activate();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_timeline_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->post_id  = self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $this->admin_id,
			)
		);
		wp_set_current_user( $this->admin_id );
		add_filter( 'kayzart_ai_sdk_present', '__return_true' );
		add_filter( 'kayzart_ai_provider_configured', '__return_true' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );
		add_filter( 'pre_http_request', array( $this, 'mock_immediate_loopback' ), 10, 3 );
		add_filter( 'query', array( $this, 'fail_timeline_write' ) );
	}

	/** Restore global filters, actions, and user state. */
	protected function tearDown(): void {
		remove_filter( 'kayzart_ai_sdk_present', '__return_true' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_true' );
		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		remove_filter( 'pre_http_request', array( $this, 'mock_immediate_loopback' ), 10 );
		remove_filter( 'query', array( $this, 'fail_timeline_write' ) );
		Ai_Worker::deactivate();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** New creation is accepted and an identical retry is idempotent. */
	public function test_create_returns_202_and_idempotent_retry_returns_200(): void {
		$first = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-idempotent' ) );
		$this->assertSame( 202, $first->get_status() );
		$this->assertSame( 'pending', $first->get_data()['status'] );
		$this->assertArrayHasKey( 'statusUrl', $first->get_data() );
		$this->assertSame( 'rest-idempotent', $first->get_data()['timelineItem']['requestId'] );

		$again = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-idempotent' ) );
		$this->assertSame( 200, $again->get_status() );
		$this->assertSame( $first->get_data()['jobId'], $again->get_data()['jobId'] );
		$this->assertSame( 1, $this->immediate_dispatches );
	}

	/** The server, rather than an untrusted browser field, fixes head-edit permission into each job. */
	public function test_create_captures_head_edit_permission_in_job_payload(): void {
		$deny_unfiltered_html = static function ( array $allcaps, array $caps, array $args ): array {
			if ( isset( $args[0] ) && 'unfiltered_html' === $args[0] ) {
				$allcaps['unfiltered_html'] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny_unfiltered_html, 10, 3 );
		$payload                = $this->payload( 'rest-head-permission' );
		$payload['canEditHead'] = true;
		$response               = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $payload );
		remove_filter( 'user_has_cap', $deny_unfiltered_html, 10 );

		$this->assertSame( 202, $response->get_status() );
		$job    = ( new Ai_Job_Store() )->get( $response->get_data()['jobId'] );
		$stored = json_decode( $job['payload_json'], true );
		$this->assertFalse( $stored['canEditHead'] );
	}

	/** DOM/libxml support is required before a job can be accepted. */
	public function test_create_returns_unavailable_when_dom_support_is_missing(): void {
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_false' );

		$response = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-dom-unavailable' ) );

		$this->assertSame( 503, $response->get_status() );
		$this->assertFalse( $response->get_data()['data']['availability']['dom_present'] );
		remove_filter( 'kayzart_ai_dom_present', '__return_false' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );
	}

	/** Capture the non-blocking internal request without performing network I/O.
	 *
	 * @param mixed  $preempted Existing preempted response.
	 * @param array  $args      HTTP request arguments.
	 * @param string $url       Request URL.
	 * @return mixed
	 */
	public function mock_immediate_loopback( $preempted, array $args, string $url ) {
		if ( false === strpos( $url, Ai_Immediate_Dispatcher::ROUTE ) ) {
			return $preempted;
		}
		++$this->immediate_dispatches;
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 202,
				'message' => 'Accepted',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** Transform the selected timeline write into invalid SQL to exercise database failures.
	 *
	 * @param string $query Database query.
	 */
	public function fail_timeline_write( string $query ): string {
		if ( '' === $this->timeline_write_failure || false === strpos( $query, Ai_Setup::get_timeline_table_name() ) ) {
			return $query;
		}
		if ( 'update' === $this->timeline_write_failure && preg_match( '/^\\s*UPDATE\\s/i', $query ) ) {
			return 'INVALID KAYZART TIMELINE UPDATE';
		}
		if ( 'insert' === $this->timeline_write_failure && preg_match( '/^\\s*INSERT\\s/i', $query ) ) {
			return 'INVALID KAYZART TIMELINE INSERT';
		}
		return $query;
	}

	/** Invalid sizes return 400 and an occupied post returns 409. */
	public function test_create_validates_size_and_reports_post_lock(): void {
		$invalid           = $this->payload( 'rest-large' );
		$invalid['prompt'] = str_repeat( 'x', 8193 );
		$this->assertSame( 400, $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $invalid )->get_status() );

		$this->assertSame( 202, $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-lock-one' ) )->get_status() );
		$this->assertSame( 409, $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-lock-two' ) )->get_status() );
	}

	/** Managers can inspect all jobs while inaccessible IDs remain hidden. */
	public function test_non_owner_cannot_discover_job_but_admin_can(): void {
		$created = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-access' ) );
		$uuid    = $created->get_data()['jobId'];
		$other   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other );
		$this->assertSame( 200, $this->dispatch_json( 'GET', '/kayzart/v1/ai/jobs/' . $uuid )->get_status(), 'manage_options may inspect another user job.' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_role( 'author' )->add_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( $author );
		$this->assertSame( 404, $this->dispatch_json( 'GET', '/kayzart/v1/ai/jobs/' . $uuid )->get_status() );
		get_role( 'author' )->remove_cap( Ai_Setup::CAPABILITY );
	}

	/** Pending cancellation is immediate, idempotent, and unlocks the post. */
	public function test_pending_cancel_is_idempotent_and_releases_post_lock(): void {
		$created = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-cancel' ) );
		$uuid    = $created->get_data()['jobId'];
		$first   = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs/' . $uuid . '/cancel' );
		$again   = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs/' . $uuid . '/cancel' );

		$this->assertSame( 'canceled', $first->get_data()['status'] );
		$this->assertSame( 'canceled', $again->get_data()['status'] );
		$this->assertNull( ( new Ai_Job_Store() )->get( $uuid )['lock_key'] );
	}

	/** Timeline is shared at post level and retained snapshots expire with jobs. */
	public function test_timeline_lists_updates_and_expires_job_snapshot(): void {
		$created  = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-timeline' ) );
		$activity = $created->get_data()['timelineItem'];
		$request  = new WP_REST_Request( 'GET', '/kayzart/v1/ai/timeline' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'post_id', $this->post_id );
		$list = rest_do_request( $request );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( 'rest-timeline', $list->get_data()['items'][0]['requestId'] );

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );
		$application = $this->dispatch_json( 'POST', '/kayzart/v1/ai/timeline/' . $activity['id'] . '/application', array( 'status' => 'applied' ) );
		$this->assertSame( 200, $application->get_status(), 'Editors share the post timeline.' );
		$this->assertSame( 'applied', $application->get_data()['item']['applicationStatus'] );

		$snapshot = $this->dispatch_json( 'GET', '/kayzart/v1/ai/timeline/' . $activity['id'] . '/snapshot', array( 'target' => 'before' ) );
		$this->assertSame( 200, $snapshot->get_status() );
		$this->assertNotEmpty( $snapshot->get_data()['snapshot']['baseHash'] );
		global $wpdb;
		$wpdb->delete( Ai_Setup::get_jobs_table_name(), array( 'job_uuid' => $created->get_data()['jobId'] ) );
		$expired = $this->dispatch_json( 'GET', '/kayzart/v1/ai/timeline/' . $activity['id'] . '/snapshot', array( 'target' => 'before' ) );
		$this->assertSame( 410, $expired->get_status() );
	}

	/** Restore reports an application-state persistence failure instead of returning success. */
	public function test_restore_returns_500_when_application_state_cannot_be_saved(): void {
		$created  = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-restore-update-failure' ) );
		$activity = $created->get_data()['timelineItem'];
		global $wpdb;
		$previous_suppression         = $wpdb->suppress_errors( true );
		$this->timeline_write_failure = 'update';
		$response                     = $this->dispatch_json( 'POST', '/kayzart/v1/ai/timeline/' . $activity['id'] . '/restore', array( 'target' => 'before' ) );
		$this->timeline_write_failure = '';
		$wpdb->suppress_errors( $previous_suppression );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'kayzart_ai_application_failed', $response->get_data()['code'] );
		$this->assertCount( 1, ( new \KayzArt\Ai_Timeline_Store() )->list_for_post( $this->post_id )['items'] );
	}

	/** Restore reports a history INSERT failure after successfully updating the editor state. */
	public function test_restore_returns_500_when_history_cannot_be_saved(): void {
		$created  = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $this->payload( 'rest-restore-insert-failure' ) );
		$activity = $created->get_data()['timelineItem'];
		global $wpdb;
		$previous_suppression         = $wpdb->suppress_errors( true );
		$this->timeline_write_failure = 'insert';
		$response                     = $this->dispatch_json( 'POST', '/kayzart/v1/ai/timeline/' . $activity['id'] . '/restore', array( 'target' => 'before' ) );
		$this->timeline_write_failure = '';
		$wpdb->suppress_errors( $previous_suppression );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'kayzart_ai_restore_history_failed', $response->get_data()['code'] );
		$items = ( new \KayzArt\Ai_Timeline_Store() )->list_for_post( $this->post_id )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'reverted', $items[0]['applicationStatus'] );
	}

	/** Rich selected HTML is stored as a compact descriptor plus an offset record. */
	public function test_selection_context_is_compacted_at_job_boundary(): void {
		$payload                     = $this->payload( 'rest-selection' );
		$payload['selectedContexts'] = array(
			array(
				'lcId'        => 'hero-title',
				'tagName'     => 'h1',
				'outerHTML'   => '<h1>Hello</h1>',
				'sourceRange' => array(
					'startOffset' => 0,
					'endOffset'   => 14,
				),
			),
		);
		$response                    = $this->dispatch_json( 'POST', '/kayzart/v1/ai/jobs', $payload );
		$job                         = ( new Ai_Job_Store() )->get( $response->get_data()['jobId'] );
		$stored                      = json_decode( $job['payload_json'], true );

		$this->assertArrayNotHasKey( 'outerHTML', $stored['selectedContexts'][0] );
		$this->assertNotEmpty( $stored['selectedContexts'][0]['selectionId'] );
		$this->assertTrue( $stored['selectedContexts'][0]['resolvable'] );
		$this->assertCount( 1, $stored['selectionRecords'] );
	}

	/** Build a valid REST request body.
	 *
	 * @param string $request_id Request ID.
	 */
	private function payload( string $request_id ): array {
		return array(
			'requestId'        => $request_id,
			'post_id'          => $this->post_id,
			'editorMode'       => 'normal',
			'prompt'           => 'Change Hello to World.',
			'html'             => '<h1>Hello</h1>',
			'customHead'       => '',
			'css'              => '',
			'js'               => '',
			'jsMode'           => 'classic',
			'baseHash'         => '',
			'selectedContexts' => array(),
		);
	}

	/** Dispatch an authenticated JSON request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route path.
	 * @param array  $body   JSON body.
	 */
	private function dispatch_json( string $method, string $route, array $body = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( ! empty( $body ) ) {
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}
}
