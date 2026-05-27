<?php
/**
 * Legacy shortcode compatibility tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Post_Type;

class Test_Shortcode_Permissions extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! shortcode_exists( 'kayzart' ) ) {
			Frontend::init();
		}
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_legacy_shortcode_returns_empty_for_missing_post(): void {
		$output = do_shortcode( '[kayzart post_id="123"]' );

		$this->assertSame( '', $output );
	}

	public function test_legacy_shortcode_returns_empty_without_post_id(): void {
		$output = do_shortcode( '[kayzart]' );

		$this->assertSame( '', $output );
	}

	public function test_legacy_shortcode_returns_empty_for_non_kayzart_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = (int) self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_author'  => $admin_id,
				'post_content' => '<p>Normal content</p>',
			)
		);

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_set_current_user( $admin_id );

		$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertSame( '', $output );
	}

	public function test_legacy_shortcode_returns_empty_for_private_post_without_permission(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'private' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_set_current_user( 0 );

		$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertSame( '', $output );
	}

	public function test_legacy_shortcode_renders_private_post_with_permission(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'private' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_set_current_user( $admin_id );

		$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertStringContainsString( '<p>KayzArt content</p>', $output );
	}

	public function test_legacy_shortcode_returns_empty_for_password_protected_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish', 'secret' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_set_current_user( $admin_id );

		$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertSame( '', $output );
	}

	private function create_kayzart_post( int $author_id, string $status, string $password = '' ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'     => Post_Type::POST_TYPE,
				'post_status'   => $status,
				'post_author'   => $author_id,
				'post_content'  => '<p>KayzArt content</p>',
				'post_password' => $password,
			)
		);
	}
}
