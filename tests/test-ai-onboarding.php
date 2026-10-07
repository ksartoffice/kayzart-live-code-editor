<?php
/**
 * AI connection guidance and read-only availability tests.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Ai_Onboarding;
use KayzArt\Ai_Setup;
use KayzArt\Rest_Ai;

/** Verify onboarding branches and authenticated availability reads. */
class Test_Kayzart_Ai_Onboarding extends WP_UnitTestCase {
	private $original_version;
	private $admin_id;

	protected function setUp(): void {
		parent::setUp();
		$this->original_version = $GLOBALS['wp_version'];
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( $this->admin_id );
		foreach ( array( 'feature_enabled', 'scheduler_present', 'mbstring_present', 'dom_present' ) as $gate ) {
			add_filter( 'kayzart_ai_' . $gate, '__return_true' );
		}
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		delete_option( 'kayzart_openai_api_key' );
	}

	protected function tearDown(): void {
		$GLOBALS['wp_version'] = $this->original_version;
		foreach ( array( 'feature_enabled', 'sdk_present', 'provider_configured', 'scheduler_present', 'mbstring_present', 'dom_present' ) as $gate ) {
			remove_all_filters( 'kayzart_ai_' . $gate );
		}
		delete_option( 'kayzart_openai_api_key' );
		parent::tearDown();
	}

	public function test_settings_target_uses_version_and_sdk_capability(): void {
		add_filter( 'kayzart_ai_sdk_present', '__return_true' );
		$GLOBALS['wp_version'] = '6.9';
		$data = Ai_Onboarding::get_data();
		$this->assertSame( 'direct', $data['setupMode'] );
		$this->assertSame( Admin::get_settings_url() . '#kayzart-ai-connection', $data['setupUrl'] );
		$GLOBALS['wp_version'] = '7.0';
		$data = Ai_Onboarding::get_data();
		$this->assertSame( 'connectors', $data['setupMode'] );
		$this->assertSame( admin_url( 'options-connectors.php' ), $data['setupUrl'] );
		remove_all_filters( 'kayzart_ai_sdk_present' );
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		$this->assertSame( 'direct', Ai_Onboarding::get_data()['setupMode'] );
	}

	public function test_saved_key_keeps_working_after_upgrade_and_is_never_exposed(): void {
		$GLOBALS['wp_version'] = '7.0';
		add_filter( 'kayzart_ai_sdk_present', '__return_true' );
		update_option( 'kayzart_openai_api_key', 'sk-private-onboarding-fixture' );
		$data = Ai_Onboarding::get_data();
		$this->assertTrue( $data['available'] );
		$this->assertFalse( $data['canSetUp'] );
		$this->assertSame( 'openai_direct', $data['backend'] );
		$this->assertStringNotContainsString( 'sk-private-onboarding-fixture', wp_json_encode( $data ) );
	}

	public function test_permission_policy_and_environment_failures_are_distinct(): void {
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$data = Ai_Onboarding::get_data();
		$this->assertFalse( $data['canSetUp'] );
		$this->assertSame( '', $data['setupUrl'] );
		$this->assertStringContainsString( 'permission', $data['unavailableMessage'] );
		wp_set_current_user( $this->admin_id );
		remove_all_filters( 'kayzart_ai_feature_enabled' );
		add_filter( 'kayzart_ai_feature_enabled', '__return_false' );
		$this->assertStringContainsString( 'policy', Ai_Onboarding::get_data()['unavailableMessage'] );
		remove_all_filters( 'kayzart_ai_feature_enabled' );
		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		remove_all_filters( 'kayzart_ai_mbstring_present' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_false' );
		$data = Ai_Onboarding::get_data();
		$this->assertFalse( $data['canSetUp'] );
		$this->assertStringContainsString( 'environment requirements', $data['unavailableMessage'] );
	}

	public function test_rest_check_requires_login_nonce_ai_permission_and_post_permission(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$request = new WP_REST_Request( 'GET', '/kayzart/v1/ai/availability' );
		$request->set_param( 'post_id', $post_id );
		$this->assertWPError( Rest_Ai::availability_permission( $request ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertTrue( Rest_Ai::availability_permission( $request ) );
		$request->set_param( 'post_id', 99999999 );
		$this->assertFalse( Rest_Ai::availability_permission( $request ) );
		wp_set_current_user( 0 );
		$this->assertFalse( Rest_Ai::availability_permission( $request ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertFalse( Rest_Ai::availability_permission( $request ) );
	}

	public function test_rest_response_is_read_only_and_contains_no_jobs_or_credentials(): void {
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		$response = Rest_Ai::availability();
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertFalse( $data['ai']['available'] );
		$this->assertArrayNotHasKey( 'jobsUrl', $data['ai'] );
		$this->assertArrayNotHasKey( 'initialRequest', $data['ai'] );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );
	}

	public function test_registered_get_route_validates_nonce_and_target_without_creating_a_page(): void {
		rest_get_server();
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		$post_count = wp_count_posts( 'page' );
		$request = new WP_REST_Request( 'GET', '/kayzart/v1/ai/availability' );
		$request->set_header( 'X-WP-Nonce', 'invalid' );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertEquals( $post_count, wp_count_posts( 'page' ) );
		$request->set_param( 'post_id', 0 );
		$this->assertSame( 400, rest_do_request( $request )->get_status() );
		$request->set_param( 'post_id', 99999999 );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_collapsed_advanced_inputs_are_submitted_with_key_save(): void {
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		$values = array(
			Admin::OPTION_AI_DEFAULT_MODEL => 'gpt-5-mini',
			Admin::OPTION_AI_SITE_INSTRUCTIONS => 'Keep the cafe name.',
			Admin::OPTION_AI_REFERENCE_FETCH => '1',
			Admin::OPTION_AI_MAX_TURNS => 12,
			Admin::OPTION_AI_MAX_PROMPT_CHARS => 1900,
		);
		foreach ( $values as $option => $value ) {
			update_option( $option, $value );
		}
		Admin::register_settings();
		ob_start();
		Admin::render_settings_page();
		$html = (string) ob_get_clean();
		$document = new DOMDocument();
		@$document->loadHTML( $html );
		$xpath = new DOMXPath( $document );
		$this->assertSame( 1, $xpath->query( '//details[@class="kayzart-ai-advanced" and not(@open)]' )->length );
		foreach ( $values as $option => $value ) {
			$inputs = $xpath->query( '//details[@class="kayzart-ai-advanced"]//*[@name="' . $option . '"]' );
			$this->assertGreaterThan( 0, $inputs->length, $option );
			$this->assertFalse( $inputs->item( 0 )->hasAttribute( 'disabled' ) );
			update_option( $option, sanitize_option( $option, $value ) );
			$this->assertSame( $value, get_option( $option ) );
		}
		update_option( 'kayzart_openai_api_key', sanitize_option( 'kayzart_openai_api_key', 'sk-new-test-key' ) );
		foreach ( $values as $option => $value ) {
			$this->assertSame( $value, get_option( $option ) );
		}
		$this->assertGreaterThanOrEqual( 2, substr_count( $html, 'name="submit"' ) );
	}
}
