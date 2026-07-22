<?php
/**
 * Immediate AI Action Scheduler dispatcher tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Immediate_Dispatcher;
use KayzArt\Ai_Job_Store;
use KayzArt\Ai_Message;
use KayzArt\Ai_Setup;
use KayzArt\Ai_Worker;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/** Verifies signed dispatch, fallback behavior, and Action Scheduler execution. */
class Test_Kayzart_Ai_Immediate_Dispatcher extends WP_UnitTestCase {
	/**
	 * Job store under test.
	 *
	 * @var Ai_Job_Store
	 */
	private $store;

	/** Prepare empty Kayzart queues and job storage. */
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
		Ai_Setup::activate();
		Ai_Worker::deactivate();
		$this->store = new Ai_Job_Store();
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Ai_Immediate_Dispatcher::LOCK_OPTION );
	}

	/** Remove substitutions, pending actions, and locks. */
	protected function tearDown(): void {
		remove_all_filters( 'kayzart_ai_client' );
		remove_all_filters( 'kayzart_ai_immediate_dispatch_enabled' );
		remove_all_filters( 'pre_http_request' );
		Ai_Worker::deactivate();
		parent::tearDown();
	}

	/** Dispatch sends one signed same-origin non-blocking request. */
	public function test_dispatch_sends_signed_non_blocking_request(): void {
		$uuid      = $this->create_job( 'immediate-http', 20 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		$captured  = null;
		add_filter(
			'pre_http_request',
			function ( $preempted, $args, $url ) use ( &$captured ) {
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);
				return $this->http_response();
			},
			10,
			3
		);

		$this->assertTrue( Ai_Immediate_Dispatcher::dispatch( $scheduled['run_action_id'], $uuid ) );
		$this->assertStringContainsString( Ai_Immediate_Dispatcher::ROUTE, $captured['url'] );
		$this->assertFalse( $captured['args']['blocking'] );
		$this->assertSame( (string) $scheduled['run_action_id'], $captured['args']['headers'][ Ai_Immediate_Dispatcher::HEADER_ACTION_ID ] );
		$this->assertSame( $uuid, $captured['args']['headers'][ Ai_Immediate_Dispatcher::HEADER_JOB_UUID ] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $captured['args']['headers'][ Ai_Immediate_Dispatcher::HEADER_SIGNATURE ] );
		$this->assertSame( 0, ActionScheduler::store()->fetch_action( $scheduled['run_action_id'] )->get_priority() );
	}

	/** A transport error leaves the Action Scheduler fallback pending. */
	public function test_dispatch_failure_keeps_fallback_action_pending(): void {
		$uuid      = $this->create_job( 'immediate-http-failure', 21 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'loopback_failed', 'Loopback unavailable.' );
			}
		);

		$this->assertFalse( Ai_Immediate_Dispatcher::dispatch( $scheduled['run_action_id'], $uuid ) );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $scheduled['run_action_id'] ) );
		$this->assertSame( 'pending', $this->store->get( $uuid )['status'] );
	}

	/** The rollout filter restores Action Scheduler-only behavior. */
	public function test_disabled_filter_skips_http_dispatch(): void {
		$uuid      = $this->create_job( 'immediate-disabled', 22 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		$calls     = 0;
		add_filter( 'kayzart_ai_immediate_dispatch_enabled', '__return_false' );
		add_filter(
			'pre_http_request',
			static function ( $preempted ) use ( &$calls ) {
				$calls++;
				return $preempted;
			}
		);

		$this->assertFalse( Ai_Immediate_Dispatcher::dispatch( $scheduled['run_action_id'], $uuid ) );
		$this->assertSame( 0, $calls );
	}

	/** Invalid signatures and mismatched action identities are rejected. */
	public function test_internal_route_rejects_invalid_signature_and_identity(): void {
		$uuid      = $this->create_job( 'immediate-auth', 23 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		$request   = $this->signed_request( $scheduled['run_action_id'], $uuid );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_SIGNATURE, str_repeat( '0', 64 ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		$other   = $this->create_job( 'immediate-auth-other', 24 );
		$request = $this->signed_request( $scheduled['run_action_id'], $other );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		$this->assertSame( 403, rest_do_request( $this->signed_request( $scheduled['run_action_id'], $uuid, time() - 1 ) )->get_status() );
		$this->assertSame( 403, rest_do_request( $this->signed_request( $scheduled['run_action_id'], $uuid, time() + Ai_Immediate_Dispatcher::SIGNATURE_TTL + 1 ) )->get_status() );
	}

	/** A valid loopback uses Action Scheduler and a replay is a harmless no-op. */
	public function test_valid_loopback_completes_once_and_replay_is_noop(): void {
		$uuid      = $this->create_job( 'immediate-success', 25 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		$fake      = $this->successful_fake();
		add_filter(
			'kayzart_ai_client',
			static function () use ( $fake ) {
				return $fake;
			}
		);
		add_filter( 'pre_http_request', array( $this, 'preempt_http' ), 10, 3 );
		$request = $this->signed_request( $scheduled['run_action_id'], $uuid );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['started'] );
		$this->assertSame( 'completed', $this->store->get( $uuid )['status'] );
		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $scheduled['run_action_id'] ) );
		$this->assertFalse( get_option( Ai_Immediate_Dispatcher::LOCK_OPTION, false ) );
		$this->assertCount( 3, $fake->calls() );

		$replay = rest_do_request( $request );
		$this->assertSame( 200, $replay->get_status() );
		$this->assertFalse( $replay->get_data()['started'] );
		$this->assertCount( 3, $fake->calls() );
	}

	/** A held site lock prevents a second immediate worker from starting. */
	public function test_site_lock_serializes_immediate_execution(): void {
		$uuid      = $this->create_job( 'immediate-busy', 26 );
		$scheduled = Ai_Worker::enqueue( $uuid );
		add_option(
			Ai_Immediate_Dispatcher::LOCK_OPTION,
			wp_json_encode(
				array(
					'token'   => 'other',
					'expires' => time() + 600,
				)
			),
			'',
			'no'
		);

		$response = rest_do_request( $this->signed_request( $scheduled['run_action_id'], $uuid ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'busy', $response->get_data()['reason'] );
		$this->assertSame( 'pending', $this->store->get( $uuid )['status'] );
		$this->assertSame( ActionScheduler_Store::STATUS_PENDING, ActionScheduler::store()->get_status( $scheduled['run_action_id'] ) );
	}

	/** Completing one action immediately kicks the next post's pending action. */
	public function test_completion_dispatches_next_pending_job(): void {
		$first_uuid  = $this->create_job( 'immediate-chain-one', 27 );
		$second_uuid = $this->create_job( 'immediate-chain-two', 28 );
		$first       = Ai_Worker::enqueue( $first_uuid );
		$second      = Ai_Worker::enqueue( $second_uuid );
		$fake        = $this->successful_fake();
		$dispatched  = array();
		add_filter(
			'kayzart_ai_client',
			static function () use ( $fake ) {
				return $fake;
			}
		);
		add_filter(
			'pre_http_request',
			function ( $preempted, $args ) use ( &$dispatched ) {
				$dispatched[] = $args['headers'];
				return $this->http_response();
			},
			10,
			3
		);

		$response = rest_do_request( $this->signed_request( $first['run_action_id'], $first_uuid ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'completed', $this->store->get( $first_uuid )['status'] );
		$this->assertSame( 'pending', $this->store->get( $second_uuid )['status'] );
		$this->assertCount( 1, $dispatched );
		$this->assertSame( (string) $second['run_action_id'], $dispatched[0][ Ai_Immediate_Dispatcher::HEADER_ACTION_ID ] );
		$this->assertSame( $second_uuid, $dispatched[0][ Ai_Immediate_Dispatcher::HEADER_JOB_UUID ] );
	}

	/** Preempt a loopback request with a successful HTTP response.
	 *
	 * @return array
	 */
	public function preempt_http(): array {
		return $this->http_response();
	}

	/** Create one pending test job.
	 *
	 * @param string $request_id Request ID.
	 * @param int    $post_id    Unique post ID.
	 */
	private function create_job( string $request_id, int $post_id ): string {
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

	/** Build a correctly signed internal REST request.
	 *
	 * @param int      $action_id Action Scheduler action ID.
	 * @param string   $job_uuid  Job UUID.
	 * @param int|null $expires Optional explicit signature expiry.
	 */
	private function signed_request( int $action_id, string $job_uuid, ?int $expires = null ): WP_REST_Request {
		$expires   = null === $expires ? time() + Ai_Immediate_Dispatcher::SIGNATURE_TTL : $expires;
		$nonce     = str_repeat( 'a', 32 );
		$signature = hash_hmac( 'sha256', $action_id . '|' . $job_uuid . '|' . $expires . '|' . $nonce, wp_salt( 'auth' ) );
		$request   = new WP_REST_Request( 'POST', '/kayzart/v1' . Ai_Immediate_Dispatcher::ROUTE );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_ACTION_ID, (string) $action_id );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_JOB_UUID, $job_uuid );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_EXPIRES, (string) $expires );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_NONCE, $nonce );
		$request->set_header( Ai_Immediate_Dispatcher::HEADER_SIGNATURE, $signature );
		return $request;
	}

	/** Build a deterministic successful edit client. */
	private function successful_fake(): Ai_Client_Fake {
		return new Ai_Client_Fake(
			array(
				array(
					'toolCalls' => array(
						Ai_Message::tool_call(
							'replace',
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
	}

	/** Return a valid preempted WordPress HTTP response. */
	private function http_response(): array {
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
}
