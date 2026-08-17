<?php
/**
 * Prompt improvement REST API tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Setup;
use KayzArt\Rest_Ai_Prompt;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/**
 * Verifies prompt improvement validation, authorization, and rate limiting.
 */
class Test_Kayzart_Rest_Ai_Prompt extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Fake AI client returned by the test filter.
	 *
	 * @var Ai_Client_Fake
	 */
	private $fake;

	/**
	 * Prepare routes and AI availability.
	 */
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( $this->admin_id );
		delete_transient( Rest_Ai_Prompt::RATE_KEY_PREFIX . $this->admin_id );
		update_option( 'kayzart_openai_api_key', 'sk-test-prompt-improver' );

		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		$this->fake = new Ai_Client_Fake();
		add_filter( 'kayzart_ai_prompt_improver_client', array( $this, 'filtered_client' ) );
	}

	/**
	 * Restore filters, rate state, and user state.
	 */
	protected function tearDown(): void {
		delete_transient( Rest_Ai_Prompt::RATE_KEY_PREFIX . $this->admin_id );
		delete_option( 'kayzart_openai_api_key' );
		remove_filter( 'kayzart_ai_sdk_present', '__return_false' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_false' );
		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		remove_filter( 'kayzart_ai_prompt_improver_client', array( $this, 'filtered_client' ) );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Return the configured fake client.
	 *
	 * @return Ai_Client_Fake
	 */
	public function filtered_client(): Ai_Client_Fake {
		return $this->fake;
	}

	/**
	 * A valid request returns improved text and sanitized title context.
	 */
	public function test_improve_returns_prompt_and_sanitizes_title(): void {
		$this->fake->queue_final_text( 'Create a clear hero and booking CTA.' );

		$response = $this->dispatch(
			array(
				'prompt' => 'Make a salon LP.',
				'title'  => '<b>Salon launch</b>',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Create a clear hero and booking CTA.', $response->get_data()['improvedPrompt'] );
		$message = $this->fake->calls()[0]['messages'][0]['text'];
		$this->assertStringContainsString( 'Salon launch', $message );
		$this->assertStringNotContainsString( '<b>', $message );
	}

	/**
	 * Markup in an instruction remains plain-text source material for improvement.
	 */
	public function test_improve_preserves_markup_in_prompt(): void {
		$prompt = "Use <header> and <main>.\nKeep </script> and `<section>` as instruction text.";
		$this->fake->queue_final_text( 'Create a semantic page structure.' );

		$response = $this->dispatch( array( 'prompt' => $prompt ) );

		$this->assertSame( 200, $response->get_status() );
		$message = $this->fake->calls()[0]['messages'][0]['text'];
		$this->assertStringContainsString(
			"<source-instruction>\n{$prompt}\n</source-instruction>",
			$message
		);
	}

	/**
	 * Missing nonce, capability, and provider availability are rejected.
	 */
	public function test_improve_requires_nonce_capability_and_ai_availability(): void {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1' . Rest_Ai_Prompt::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'prompt' => 'Build a page.' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );

		get_role( 'administrator' )->remove_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->admin_id );
		$this->assertSame( 403, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( 0 );
		wp_set_current_user( $this->admin_id );

		delete_option( 'kayzart_openai_api_key' );
		$this->assertSame( 503, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
		update_option( 'kayzart_openai_api_key', 'sk-test-prompt-improver' );
	}

	/**
	 * Invalid input and invalid model output do not replace browser text.
	 */
	public function test_improve_validates_input_and_provider_output(): void {
		$this->assertSame( 400, $this->dispatch( array( 'prompt' => '' ) )->get_status() );
		$this->assertSame( 400, $this->dispatch( array( 'prompt' => str_repeat( 'x', 8193 ) ) )->get_status() );

		$this->fake->queue_final_text( '' );
		$this->assertSame( 502, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
	}

	/**
	 * The sixth provider attempt in a minute is rate limited.
	 */
	public function test_improve_limits_each_user_to_five_attempts_per_minute(): void {
		for ( $index = 0; $index < 5; ++$index ) {
			$this->fake->queue_final_text( 'Improved ' . $index );
			$this->assertSame( 200, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
		}

		$limited = $this->dispatch( array( 'prompt' => 'Build a page.' ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertGreaterThan( 0, $limited->get_data()['data']['retryAfter'] );

		set_transient(
			Rest_Ai_Prompt::RATE_KEY_PREFIX . $this->admin_id,
			array(
				'startedAt' => time() - 61,
				'count'     => 5,
			),
			60
		);
		$this->fake->queue_final_text( 'Allowed again.' );
		$this->assertSame( 200, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
	}

	/**
	 * Provider failures still consume attempts in the current rate window.
	 */
	public function test_improve_counts_failed_provider_attempts(): void {
		for ( $index = 0; $index < 5; ++$index ) {
			$this->assertSame( 502, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
		}

		$limited = $this->dispatch( array( 'prompt' => 'Build a page.' ) );
		$this->assertSame( 429, $limited->get_status() );
		$this->assertCount( 5, $this->fake->calls() );
	}

	/**
	 * A concurrent request cannot enter the transient read-modify-write section.
	 */
	public function test_improve_rejects_request_while_user_rate_lock_is_held(): void {
		global $wpdb;

		$lock_name = Rest_Ai_Prompt::RATE_LOCK_PREFIX . md5( $wpdb->prefix . ':' . $this->admin_id );
		$locker    = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$acquired  = $locker->get_var( $locker->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		$this->assertSame( '1', (string) $acquired );

		try {
			$limited = $this->dispatch( array( 'prompt' => 'Build a page.' ) );
			$this->assertSame( 429, $limited->get_status() );
			$this->assertSame( 1, $limited->get_data()['data']['retryAfter'] );
			$this->assertCount( 0, $this->fake->calls() );
		} finally {
			$locker->get_var( $locker->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			$locker->close();
		}

		$this->fake->queue_final_text( 'Allowed after lock release.' );
		$this->assertSame( 200, $this->dispatch( array( 'prompt' => 'Build a page.' ) )->get_status() );
	}

	/**
	 * Dispatch an authenticated JSON request.
	 *
	 * @param array $body JSON request body.
	 */
	private function dispatch( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1' . Rest_Ai_Prompt::ROUTE );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}
}
