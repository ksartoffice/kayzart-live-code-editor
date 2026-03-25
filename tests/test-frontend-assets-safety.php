<?php
/**
 * Front-end external asset safety tests for KayzArt.
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

	public function test_frontend_filters_invalid_external_assets(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_shadow_dom', '1' );

		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode(
				array(
					'http://example.com/bad.js',
					'https://example.com/good.js',
					'javascript:alert(1)',
				)
			)
		);

		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode(
				array(
					'http://example.com/bad.css',
					'https://example.com/good.css',
					'javascript:alert(2)',
				)
			)
		);

		global $wp_query;
		$original_wp_query = $wp_query ?? null;
		$wp_query          = new WP_Query();
		$wp_query->queried_object_id = $post_id;
		$wp_query->queried_object    = $post;

		$output = Frontend::filter_content( (string) $post->post_content );
		Frontend::enqueue_js();
		$scripts = wp_scripts();

		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}

		$this->assertStringContainsString( 'https://example.com/good.css', $output, 'Valid https styles should render.' );
		$this->assertStringNotContainsString( 'http://example.com/bad.css', $output, 'Invalid style URLs should be filtered.' );
		$this->assertStringNotContainsString( 'javascript:', $output, 'javascript: URLs should be filtered.' );

		$good_handle = 'kayzart-ext-' . $post_id . '-0';
		$this->assertTrue( wp_script_is( $good_handle, 'enqueued' ), 'Valid https scripts should enqueue.' );
		$this->assertSame( 'https://example.com/good.js', $scripts->registered[ $good_handle ]->src );
		foreach ( $scripts->registered as $script ) {
			if ( empty( $script->src ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'http://example.com/bad.js', $script->src, 'Invalid script URLs should be filtered.' );
			$this->assertStringNotContainsString( 'javascript:', $script->src, 'javascript: URLs should be filtered.' );
		}
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
