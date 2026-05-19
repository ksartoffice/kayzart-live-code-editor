<?php
/**
 * Front-end rendering success tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Post_Type;

class Test_Frontend_Output extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! shortcode_exists( 'kayzart' ) ) {
			Frontend::init();
		}

		$this->reset_frontend_state();
	}

	protected function tearDown(): void {
		$this->reset_frontend_state();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_filter_content_ignores_legacy_shadow_meta_and_uses_normal_dom(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_shadow_dom', '1' );
		update_post_meta( $post_id, '_kayzart_css', 'body{color:red;}' );
		update_post_meta( $post_id, '_kayzart_js', 'console.log("x");' );
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/app.css' ) )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/app.js' ) )
		);

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		$output            = Frontend::filter_content( (string) $post->post_content );
		$this->restore_query( $original_wp_query );

		$this->assertStringNotContainsString( '<kayzart-output', $output );
		$this->assertStringNotContainsString( 'shadowrootmode', $output );
		$this->assertStringNotContainsString( 'https://example.com/app.css', $output );
		$this->assertStringNotContainsString( 'body{color:red;}', $output );
		$this->assertStringContainsString( '<p>KayzArt content</p>', $output );
		$this->assertStringNotContainsString( '<script src="https://example.com/app.js"></script>', $output );
		$this->assertStringNotContainsString( '<script id="kayzart-script">console.log("x");</script>', $output );
		$this->assertStringContainsString( 'data-kayzart-js="1"', $output );
		$this->assertStringContainsString( 'data-kayzart-js-mode="classic"', $output );
	}

	public function test_legacy_shortcode_returns_empty_and_does_not_enqueue_assets(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		update_post_meta( $post_id, '_kayzart_css', 'body{font-size:16px;}' );
		update_post_meta( $post_id, '_kayzart_js', 'console.log("inline");' );
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/inline.css' ) )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/inline.js' ) )
		);

		wp_set_current_user( $admin_id );
		$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertSame( '', $output );
		$this->assertFalse( wp_style_is( 'kayzart-shortcode-style-' . $post_id, 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'kayzart-runtime', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'kayzart-ext-' . $post_id . '-0', 'enqueued' ) );
	}

	public function test_enqueue_css_preserves_tailwind_escaped_arbitrary_values(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_tailwind', '1' );

		$generated_css = '.text-\\[2rem\\]{font-size:2rem;}';
		update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $generated_css ) );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::enqueue_css();
		$this->restore_query( $original_wp_query );

		$this->assertTrue( wp_style_is( 'kayzart', 'enqueued' ) );

		$styles       = wp_styles();
		$inline_rules = $styles->get_data( 'kayzart', 'after' );
		$this->assertIsArray( $inline_rules );
		$inline_css = implode( "\n", $inline_rules );

		$this->assertStringContainsString( $generated_css, $inline_css );
		$this->assertStringNotContainsString( '.text-[2rem]', $inline_css );
	}

	public function test_marked_page_receives_frontend_assets_and_unmarked_page_does_not(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$page_id  = $this->create_page( $admin_id, 'publish', true );
		$page     = get_post( $page_id );

		$this->assertInstanceOf( WP_Post::class, $page );

		update_post_meta( $page_id, '_kayzart_css', 'body{color:green;}' );
		update_post_meta( $page_id, '_kayzart_js', 'console.log("page");' );

		$original_wp_query = $this->set_query_for_post( $page_id, $page );
		$output            = Frontend::filter_content( (string) $page->post_content );
		Frontend::enqueue_css();
		Frontend::enqueue_js();
		$this->restore_query( $original_wp_query );

		$this->assertStringContainsString( 'data-kayzart-js="1"', $output );
		$this->assertTrue( wp_style_is( 'kayzart', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'kayzart-runtime', 'enqueued' ) );

		$this->reset_frontend_state();
		wp_dequeue_style( 'kayzart' );
		wp_deregister_style( 'kayzart' );
		wp_dequeue_script( 'kayzart-runtime' );
		wp_deregister_script( 'kayzart-runtime' );

		$normal_page_id = $this->create_page( $admin_id, 'publish', false );
		$normal_page    = get_post( $normal_page_id );
		$this->assertInstanceOf( WP_Post::class, $normal_page );

		update_post_meta( $normal_page_id, '_kayzart_css', 'body{color:red;}' );
		update_post_meta( $normal_page_id, '_kayzart_js', 'console.log("normal");' );

		$original_wp_query = $this->set_query_for_post( $normal_page_id, $normal_page );
		$output            = Frontend::filter_content( (string) $normal_page->post_content );
		Frontend::enqueue_css();
		Frontend::enqueue_js();
		$this->restore_query( $original_wp_query );

		$this->assertStringNotContainsString( 'data-kayzart-js="1"', $output );
		$this->assertFalse( wp_style_is( 'kayzart', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'kayzart-runtime', 'enqueued' ) );
	}

	public function test_standalone_mode_dequeues_core_global_styles(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_template_mode', 'standalone' );
		wp_register_style( 'global-styles', false, array(), KAYZART_VERSION );
		wp_enqueue_style( 'global-styles' );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::dequeue_theme_assets_for_standalone();
		$this->restore_query( $original_wp_query );

		$this->assertFalse( wp_style_is( 'global-styles', 'enqueued' ) );
	}

	public function test_theme_mode_preserves_core_global_styles(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_template_mode', 'theme' );
		wp_register_style( 'global-styles', false, array(), KAYZART_VERSION );
		wp_enqueue_style( 'global-styles' );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::dequeue_theme_assets_for_standalone();
		$this->restore_query( $original_wp_query );

		$this->assertTrue( wp_style_is( 'global-styles', 'enqueued' ) );
	}

	public function test_standalone_style_filter_preserves_core_global_styles(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_template_mode', 'standalone' );
		wp_register_style( 'global-styles', false, array(), KAYZART_VERSION );
		wp_enqueue_style( 'global-styles' );

		add_filter( 'kayzart_standalone_dequeue_theme_styles', '__return_false' );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::dequeue_theme_assets_for_standalone();
		$this->restore_query( $original_wp_query );

		remove_filter( 'kayzart_standalone_dequeue_theme_styles', '__return_false' );

		$this->assertTrue( wp_style_is( 'global-styles', 'enqueued' ) );
	}

	private function create_kayzart_post( int $author_id, string $status ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_author'  => $author_id,
				'post_content' => '<p>KayzArt content</p>',
			)
		);
	}

	private function set_query_for_post( int $post_id, WP_Post $post ): ?WP_Query {
		global $wp_query;
		$original_wp_query = $wp_query ?? null;

		$wp_query                       = new WP_Query();
		$wp_query->queried_object_id    = $post_id;
		$wp_query->queried_object       = $post;
		$wp_query->is_singular          = true;
		$wp_query->is_single            = true;
		$wp_query->set( 'kayzart_preview', '' );

		return $original_wp_query;
	}

	private function restore_query( ?WP_Query $original_wp_query ): void {
		global $wp_query;
		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}
	}

	private function reset_frontend_state(): void {
		$external_property = new ReflectionProperty( Frontend::class, 'external_script_handles' );
		$external_property->setAccessible( true );
		$external_property->setValue( null, array() );

		$runtime_property = new ReflectionProperty( Frontend::class, 'runtime_enqueued' );
		$runtime_property->setAccessible( true );
		$runtime_property->setValue( null, false );

		remove_filter( 'kayzart_standalone_dequeue_theme_styles', '__return_false' );
		wp_dequeue_style( 'global-styles' );
		wp_deregister_style( 'global-styles' );
	}

	private function create_page( int $author_id, string $status, bool $kayzart_enabled ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::PAGE_TYPE,
				'post_status'  => $status,
				'post_author'  => $author_id,
				'post_content' => '<p>Page content</p>',
			)
		);

		if ( $kayzart_enabled ) {
			update_post_meta( $post_id, Post_Type::ENABLED_META, '1' );
		}

		return $post_id;
	}
}
