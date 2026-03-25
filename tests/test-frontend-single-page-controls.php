<?php
/**
 * Front-end single-page/public visibility tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Post_Type;

class KayzArt_Frontend_Redirect_Exception extends Exception {
	public string $location;

	public function __construct( string $location ) {
		$this->location = $location;
		parent::__construct( $location );
	}
}

class KayzArt_Frontend_Template_Exception extends Exception {
}

class Test_Frontend_Single_Page_Controls extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	public function test_maybe_redirect_single_page_redirects_when_disabled(): void {
		$post_id = $this->create_kayzart_post();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_single_page_enabled', '0' );
		$original_wp_query = $this->set_query_for_post( $post_id, $post );

		$redirect_target = home_url( '/kayzart-landing/' );
		$redirect_filter = static function () use ( $redirect_target ) {
			return $redirect_target;
		};
		add_filter( 'kayzart_single_page_redirect', $redirect_filter );

		$wp_redirect_filter = static function ( $location ) {
			throw new KayzArt_Frontend_Redirect_Exception( (string) $location );
		};
		add_filter( 'wp_redirect', $wp_redirect_filter );

		try {
			Frontend::maybe_redirect_single_page();
			$this->fail( 'Expected redirect to be triggered.' );
		} catch ( KayzArt_Frontend_Redirect_Exception $e ) {
			$this->assertSame( $redirect_target, $e->location );
		} finally {
			remove_filter( 'wp_redirect', $wp_redirect_filter );
			remove_filter( 'kayzart_single_page_redirect', $redirect_filter );
			$this->restore_query( $original_wp_query );
		}
	}

	public function test_maybe_redirect_single_page_sets_404_when_filter_requests_it(): void {
		$post_id = $this->create_kayzart_post();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_single_page_enabled', '0' );
		$original_wp_query = $this->set_query_for_post( $post_id, $post );

		$template_path = trailingslashit( get_temp_dir() ) . 'kayzart-test-404-template.php';
		file_put_contents( $template_path, '<?php throw new KayzArt_Frontend_Template_Exception("404-template-loaded");' );

		$redirect_filter = static function () {
			return '404';
		};
		$template_filter = static function () use ( $template_path ) {
			return $template_path;
		};
		add_filter( 'kayzart_single_page_redirect', $redirect_filter );
		add_filter( '404_template', $template_filter );

		try {
			Frontend::maybe_redirect_single_page();
			$this->fail( 'Expected 404 template to interrupt before exit.' );
		} catch ( KayzArt_Frontend_Template_Exception $e ) {
			$this->assertSame( '404-template-loaded', $e->getMessage() );
			$this->assertTrue( is_404() );
		} finally {
			remove_filter( '404_template', $template_filter );
			remove_filter( 'kayzart_single_page_redirect', $redirect_filter );
			if ( file_exists( $template_path ) ) {
				unlink( $template_path );
			}
			$this->restore_query( $original_wp_query );
		}
	}

	public function test_maybe_add_noindex_outputs_meta_for_disabled_single_page(): void {
		$post_id = $this->create_kayzart_post();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_single_page_enabled', '0' );
		$original_wp_query = $this->set_query_for_post( $post_id, $post );

		ob_start();
		Frontend::maybe_add_noindex();
		$output = (string) ob_get_clean();

		$this->restore_query( $original_wp_query );

		$this->assertStringContainsString( '<meta name="robots" content="noindex">', $output );
	}

	public function test_exclude_single_page_from_search_query_adds_meta_filter(): void {
		global $wp_query;
		global $wp_the_query;
		$original_wp_query = $wp_query ?? null;
		$original_wp_the_query = $wp_the_query ?? null;

		$query    = new WP_Query();
		$wp_query = $query;
		$wp_the_query = $query;
		$query->is_singular = false;
		$query->set( 'post_type', 'any' );

		Frontend::exclude_single_page_from_query( $query );
		$meta_query = $query->get( 'meta_query' );

		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}
		if ( null !== $original_wp_the_query ) {
			$wp_the_query = $original_wp_the_query;
		} else {
			unset( $wp_the_query );
		}

		$this->assertIsArray( $meta_query );
		$this->assertCount( 1, $meta_query );
		$this->assertSame( 'OR', $meta_query[0]['relation'] ?? '' );
		$this->assertSame( '_kayzart_single_page_enabled', $meta_query[0][0]['key'] ?? '' );
		$this->assertSame( 'NOT EXISTS', $meta_query[0][0]['compare'] ?? '' );
		$this->assertSame( '_kayzart_single_page_enabled', $meta_query[0][1]['key'] ?? '' );
		$this->assertSame( '1', $meta_query[0][1]['value'] ?? '' );
	}

	public function test_maybe_disable_autop_removes_filters_for_kayzart_posts(): void {
		$post_id = $this->create_kayzart_post();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$had_wpautop          = false !== has_filter( 'the_content', 'wpautop' );
		$had_shortcode_unautop = false !== has_filter( 'the_content', 'shortcode_unautop' );

		if ( ! $had_wpautop ) {
			add_filter( 'the_content', 'wpautop' );
		}
		if ( ! $had_shortcode_unautop ) {
			add_filter( 'the_content', 'shortcode_unautop' );
		}

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::maybe_disable_autop();
		$this->restore_query( $original_wp_query );

		$this->assertFalse( has_filter( 'the_content', 'wpautop' ) );
		$this->assertFalse( has_filter( 'the_content', 'shortcode_unautop' ) );

		if ( $had_wpautop ) {
			add_filter( 'the_content', 'wpautop' );
		}
		if ( $had_shortcode_unautop ) {
			add_filter( 'the_content', 'shortcode_unautop' );
		}
	}

	public function test_maybe_override_template_uses_layout_templates_and_preview_override(): void {
		$post_id = $this->create_kayzart_post();
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );

		update_post_meta( $post_id, '_kayzart_template_mode', 'frame' );
		$legacy_template = Frontend::maybe_override_template( 'theme-template.php' );
		$this->assertSame( 'theme-template.php', $legacy_template );

		update_post_meta( $post_id, '_kayzart_template_mode', 'standalone' );
		$standalone_template = Frontend::maybe_override_template( 'theme-template.php' );
		$this->assertStringContainsString( 'templates/single-kayzart-standalone.php', str_replace( '\\', '/', $standalone_template ) );

		update_post_meta( $post_id, '_kayzart_template_mode', 'theme' );
		$theme_template = Frontend::maybe_override_template( 'theme-template.php' );
		$this->assertSame( 'theme-template.php', $theme_template );

		update_post_meta( $post_id, '_kayzart_template_mode', 'default' );
		$GLOBALS['wp_query']->set( 'kayzart_preview', '1' );
		$GLOBALS['wp_query']->set( 'kayzart_template_mode', 'standalone' );
		$preview_template = Frontend::maybe_override_template( 'theme-template.php' );
		$this->assertStringContainsString( 'templates/single-kayzart-standalone.php', str_replace( '\\', '/', $preview_template ) );

		$this->restore_query( $original_wp_query );
	}

	private function create_kayzart_post(): int {
		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		return (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => $author_id,
				'post_content' => '<p>KayzArt content</p>',
			)
		);
	}

	private function set_query_for_post( int $post_id, WP_Post $post ): ?WP_Query {
		global $wp_query;
		$original_wp_query = $wp_query ?? null;

		$wp_query                    = new WP_Query();
		$wp_query->queried_object_id = $post_id;
		$wp_query->queried_object    = $post;
		$wp_query->is_singular       = true;
		$wp_query->is_single         = true;
		$wp_query->set( 'kayzart_preview', '' );
		$wp_query->set( 'post_type', Post_Type::POST_TYPE );

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
}

