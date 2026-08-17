<?php
/**
 * AI runtime availability tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Availability;

/**
 * Verify every Phase 0 availability gate.
 */
class Test_Kayzart_Ai_Availability extends WP_UnitTestCase {

	/**
	 * Remove availability overrides after each test.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'kayzart_ai_feature_enabled' );
		remove_all_filters( 'kayzart_ai_sdk_present' );
		remove_all_filters( 'kayzart_ai_provider_configured' );
		remove_all_filters( 'kayzart_ai_scheduler_present' );
		remove_all_filters( 'kayzart_ai_mbstring_present' );
		remove_all_filters( 'kayzart_ai_dom_present' );
		parent::tearDown();
	}

	/**
	 * All gates passing makes AI available.
	 */
	public function test_status_is_available_when_all_gates_pass(): void {
		$this->set_all_gates( true );

		$this->assertSame(
			array(
				'feature_enabled'     => true,
				'sdk_present'         => true,
				'provider_configured' => true,
				'connector_configured'=> true,
				'direct_key_configured' => false,
				'direct_key_source'   => 'none',
				'backend'             => 'wordpress_ai_client',
				'scheduler_present'   => true,
				'mbstring_present'    => true,
				'dom_present'         => true,
				'setup_state'         => 'ready',
				'available'           => true,
			),
			Ai_Availability::get_status()
		);
		$this->assertTrue( Ai_Availability::is_available() );
	}

	/** A direct key makes AI available without the WordPress AI Client. */
	public function test_direct_openai_key_supports_sites_without_the_sdk(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-direct' );
		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		$status = Ai_Availability::get_status();
		$this->assertTrue( $status['available'] );
		$this->assertSame( 'openai_direct', $status['backend'] );
		$this->assertSame( 'database', $status['direct_key_source'] );
		delete_option( 'kayzart_openai_api_key' );
	}

	/**
	 * Every individual gate can disable AI.
	 */
	public function test_each_failed_gate_makes_ai_unavailable(): void {
		$filters = array(
			'kayzart_ai_feature_enabled',
			'kayzart_ai_sdk_present',
			'kayzart_ai_provider_configured',
			'kayzart_ai_scheduler_present',
			'kayzart_ai_mbstring_present',
			'kayzart_ai_dom_present',
		);

		foreach ( $filters as $failed_filter ) {
			$this->set_all_gates( true );
			remove_all_filters( $failed_filter );
			add_filter( $failed_filter, '__return_false' );

			$this->assertFalse( Ai_Availability::is_available(), $failed_filter . ' must gate AI availability.' );
			$this->clear_all_gates();
		}
	}

	/**
	 * The bundled Action Scheduler API loads during plugin bootstrap.
	 */
	public function test_action_scheduler_api_is_loaded(): void {
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}

	/**
	 * Set every availability filter to the same result.
	 *
	 * @param bool $enabled Filter result.
	 */
	private function set_all_gates( bool $enabled ): void {
		$callback = $enabled ? '__return_true' : '__return_false';
		add_filter( 'kayzart_ai_feature_enabled', $callback );
		add_filter( 'kayzart_ai_sdk_present', $callback );
		add_filter( 'kayzart_ai_provider_configured', $callback );
		add_filter( 'kayzart_ai_scheduler_present', $callback );
		add_filter( 'kayzart_ai_mbstring_present', $callback );
		add_filter( 'kayzart_ai_dom_present', $callback );
	}

	/**
	 * Clear every availability filter.
	 */
	private function clear_all_gates(): void {
		remove_all_filters( 'kayzart_ai_feature_enabled' );
		remove_all_filters( 'kayzart_ai_sdk_present' );
		remove_all_filters( 'kayzart_ai_provider_configured' );
		remove_all_filters( 'kayzart_ai_scheduler_present' );
		remove_all_filters( 'kayzart_ai_mbstring_present' );
		remove_all_filters( 'kayzart_ai_dom_present' );
	}
}
