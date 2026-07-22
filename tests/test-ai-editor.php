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
	/** Reset global assets and availability filters. */
	protected function tearDown(): void {
		wp_dequeue_script( Ai_Editor::SCRIPT_HANDLE );
		wp_deregister_script( Ai_Editor::SCRIPT_HANDLE );
		wp_dequeue_style( Ai_Editor::STYLE_HANDLE );
		wp_deregister_style( Ai_Editor::STYLE_HANDLE );
		remove_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		remove_filter( 'kayzart_ai_sdk_present', '__return_true' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_true' );
		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** Administrators receive the AI script after the main editor bundle. */
	public function test_enqueue_assets_requires_ai_capability(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );

		Ai_Editor::enqueue_assets( array( 'admin_script_handle' => 'kayzart-admin' ) );

		$this->assertTrue( wp_script_is( Ai_Editor::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Ai_Editor::STYLE_HANDLE, 'enqueued' ) );
		$this->assertContains( 'kayzart-admin', wp_scripts()->registered[ Ai_Editor::SCRIPT_HANDLE ]->deps );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		wp_dequeue_script( Ai_Editor::SCRIPT_HANDLE );
		Ai_Editor::enqueue_assets( array() );
		$this->assertFalse( wp_script_is( Ai_Editor::SCRIPT_HANDLE, 'enqueued' ) );
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

		$payload = Ai_Editor::filter_preview_payload( array(), 1 );
		$this->assertSame( Ai_Editor::PREVIEW_ACTION_ID, $payload['overlayAction']['actionId'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$payload = Ai_Editor::filter_preview_payload( $payload, 1 );
		$this->assertArrayNotHasKey( 'overlayAction', $payload );
	}
}
