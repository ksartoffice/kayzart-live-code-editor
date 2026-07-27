<?php
/**
 * AI editor asset and preview integration tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Editor;
use KayzArt\Ai_Setup;

/** Verifies capability and availability gates around the browser UI. */
class Test_Kayzart_Ai_Editor extends WP_UnitTestCase {
	/** Reset availability filters. */
	protected function tearDown(): void {
		remove_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		remove_filter( 'kayzart_ai_sdk_present', '__return_true' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_true' );
		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Preview context control requires both capability and full availability. */
	public function test_preview_action_uses_capability_and_availability(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );
		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', '__return_true' );
		add_filter( 'kayzart_ai_provider_configured', '__return_true' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		$payload = Ai_Editor::filter_preview_payload( array(), 1 );
		$this->assertSame( Ai_Editor::PREVIEW_ACTION_ID, $payload['overlayAction']['actionId'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$payload = Ai_Editor::filter_preview_payload( $payload, 1 );
		$this->assertArrayNotHasKey( 'overlayAction', $payload );
	}
}
