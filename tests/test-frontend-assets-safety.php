<?php
/**
 * Front-end legacy external asset tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Post_Type;

class Test_Frontend_Assets_Safety extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	public function test_frontend_ignores_legacy_external_asset_meta(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/legacy.js' ) )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/legacy.css' ) )
		);

		global $wp_query;
		$original_wp_query = $wp_query ?? null;
		$wp_query          = new WP_Query();
		$wp_query->queried_object_id = $post_id;
		$wp_query->queried_object    = $post;

		$output = Frontend::filter_content( (string) $post->post_content );
		Frontend::enqueue_css();
		Frontend::enqueue_js();

		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}

		$this->assertStringNotContainsString( 'https://example.com/legacy.css', $output );
		$this->assertStringNotContainsString( 'https://example.com/legacy.js', $output );
		$this->assertFalse( wp_style_is( 'kayzart-ext-style-' . $post_id . '-0', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'kayzart-ext-' . $post_id . '-0', 'enqueued' ) );
	}

	private function create_kayzart_post( int $author_id ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => $author_id,
				'post_content' => '<p>KayzArt content</p>',
			)
		);
	}
}
