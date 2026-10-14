<?php
/**
 * Deactivation feedback authorization and delivery tests.
 *
 * @package KayzArt
 */

use KayzArt\Deactivation_Feedback;

/** Verify explicit consent, minimal data, and bounded delivery. */
class Test_Deactivation_Feedback extends WP_Ajax_UnitTestCase {
	/** @var int */
	private $admin_id;

	/** Set up an administrator and an otherwise unused installation. */
	protected function setUp(): void {
		parent::setUp();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	/** Clean intercepted requests and admin context. */
	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		$_POST = array();
		$_REQUEST = array();
		set_current_screen( 'front' );
		parent::tearDown();
	}

	/** Return a valid answer with no usage information. */
	private function answer(): array {
		return array( 'submission_id' => 'b8f80d7e-1f2a-4c3b-8d4e-5f6a7b8c9d0e', 'reason' => 'api_setup', 'comment' => '' );
	}

	/** New users are invited without creating pages; other screens are excluded. */
	public function test_visibility_and_assets(): void {
		$this->assertTrue( Deactivation_Feedback::can_show() );
		Deactivation_Feedback::enqueue_assets( 'index.php' );
		$this->assertFalse( wp_script_is( Deactivation_Feedback::ACTION, 'enqueued' ) );
		Deactivation_Feedback::enqueue_assets( 'plugins.php' );
		$this->assertTrue( wp_script_is( Deactivation_Feedback::ACTION, 'enqueued' ) );
		wp_dequeue_script( Deactivation_Feedback::ACTION );
		set_current_screen( 'plugins-network' );
		$this->assertFalse( Deactivation_Feedback::can_show() );
		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertFalse( Deactivation_Feedback::can_show() );
	}

	/** No reason is selected by default and showing the survey makes no request. */
	public function test_render_does_not_send(): void {
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( Deactivation_Feedback::ENDPOINT === $url ) {
				$this->fail( 'Rendering must not send feedback.' );
			}
			return new WP_Error( 'test_external_request_blocked' );
		}, 10, 3 );
		ob_start();
		Deactivation_Feedback::render_dialog();
		$html = ob_get_clean();
		$this->assertStringContainsString( '<dialog ', $html );
		$this->assertSame( 8, substr_count( $html, 'type="radio"' ) );
		$this->assertStringNotContainsString( 'checked', $html );
	}

	/** Validate empty, malformed and Unicode answers before contacting the API. */
	public function test_validation_and_minimal_payload(): void {
		$input = $this->answer();
		$payload = Deactivation_Feedback::prepare_submission( $input + array( 'site_url' => 'private', 'ai_configured' => true ) );
		$this->assertSame( array( 'submission_id', 'survey_version', 'plugin_version', 'locale', 'reason', 'comment' ), array_keys( $payload ) );
		foreach ( array(
			array( 'reason' => '', 'comment' => ' ' ), array( 'reason' => 'invalid' ),
			array( 'reason' => array( 'cost' ) ), array( 'submission_id' => 'invalid' ),
			array( 'comment' => str_repeat( 'あ', 1001 ) ), array( 'comment' => "\xC3\x28" ),
		) as $override ) {
			$this->assertWPError( Deactivation_Feedback::prepare_submission( array_replace( $input, $override ) ) );
		}
		$this->assertIsArray( Deactivation_Feedback::prepare_submission( array_replace( $input, array( 'reason' => '', 'comment' => str_repeat( '🎨', 1000 ) ) ) ) );
	}

	/** Delivery uses a four-second budget and never exposes the default site URL. */
	public function test_delivery_headers_and_acknowledgement(): void {
		$payload = Deactivation_Feedback::prepare_submission( $this->answer() );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( $payload ) {
			$this->assertSame( Deactivation_Feedback::ENDPOINT, $url );
			$this->assertSame( 4, $args['timeout'] );
			$this->assertSame( 0, $args['redirection'] );
			$this->assertStringNotContainsString( home_url(), $args['headers']['User-Agent'] );
			$this->assertSame( $payload, json_decode( $args['body'], true ) );
			return array( 'response' => array( 'code' => 201 ), 'body' => wp_json_encode( array( 'ok' => true, 'submissionId' => $payload['submission_id'] ) ) );
		}, 10, 3 );
		$this->assertTrue( Deactivation_Feedback::send_submission( $payload ) );
	}

	/** Outages, closed surveys and malformed acknowledgements report failure. */
	public function test_delivery_failure(): void {
		foreach ( array( new WP_Error( 'timeout' ), array( 'response' => array( 'code' => 410 ), 'body' => '{}' ), array( 'response' => array( 'code' => 201 ), 'body' => 'invalid' ), array( 'response' => array( 'code' => 201 ), 'body' => '{"ok":true,"submissionId":"wrong"}' ) ) as $response ) {
			remove_all_filters( 'pre_http_request' );
			add_filter( 'pre_http_request', static function () use ( $response ) { return $response; } );
			$this->assertWPError( Deactivation_Feedback::send_submission( Deactivation_Feedback::prepare_submission( $this->answer() ) ) );
		}
	}

	/** An unauthorized or invalid-nonce AJAX request never contacts the receiver. */
	public function test_ajax_authorization(): void {
		// AJAX harness runs admin_init; core update checks are unrelated to consent.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );
		add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
			if ( Deactivation_Feedback::ENDPOINT === $url ) {
				$this->fail( 'An unauthorized request must not send feedback.' );
			}
			return new WP_Error( 'test_external_request_blocked' );
		}, 10, 3 );
		foreach ( array( 'subscriber', 'administrator' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$_POST = $this->answer() + array( 'nonce' => 'invalid' );
			$_REQUEST = $_POST;
			try {
				$this->_handleAjax( Deactivation_Feedback::ACTION );
			} catch ( WPAjaxDieStopException $error ) {
				$this->assertSame( '-1', $error->getMessage() );
			} catch ( WPAjaxDieContinueException $error ) {
				$this->assertStringContainsString( 'forbidden', $this->_last_response );
			}
			$this->_last_response = '';
		}
	}
}
