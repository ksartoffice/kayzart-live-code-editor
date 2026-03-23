<?php
/**
 * Admin settings and rewrite behavior tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Post_Type;

class Test_Admin_Settings extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! class_exists( 'WP_Admin_Bar' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		}
	}

	protected function tearDown(): void {
		delete_option( Admin::OPTION_FLUSH_REWRITE );
		delete_option( Admin::OPTION_POST_SLUG );
		delete_option( Admin::OPTION_DEFAULT_TEMPLATE_MODE );
		delete_option( Admin::OPTION_SHORTCODE_ALLOWLIST );
		delete_option( Admin::OPTION_DELETE_ON_UNINSTALL );
		parent::tearDown();
	}

	public function test_sanitize_post_slug_returns_sanitized_value_or_default(): void {
		$this->assertSame( 'my-custom-slug', Admin::sanitize_post_slug( 'My Custom Slug' ) );
		$this->assertSame( Post_Type::SLUG, Admin::sanitize_post_slug( '' ) );
	}

	public function test_sanitize_default_template_mode_allows_known_values_only(): void {
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'standalone' ) );
		$this->assertSame( 'frame', Admin::sanitize_default_template_mode( 'frame' ) );
		$this->assertSame( 'theme', Admin::sanitize_default_template_mode( 'invalid' ) );
	}

	public function test_sanitize_delete_on_uninstall_accepts_only_string_one(): void {
		$this->assertSame( '1', Admin::sanitize_delete_on_uninstall( '1' ) );
		$this->assertSame( '0', Admin::sanitize_delete_on_uninstall( '0' ) );
		$this->assertSame( '0', Admin::sanitize_delete_on_uninstall( 1 ) );
	}

	public function test_sanitize_shortcode_allowlist_normalizes_and_deduplicates(): void {
		$input = " Gallery \r\ncontact-form-7\r\nCONTACT-FORM-7\r\n\r\ninvalid tag\r\n";
		$this->assertSame(
			"gallery\ncontact-form-7\ninvalidtag",
			Admin::sanitize_shortcode_allowlist( $input )
		);
	}

	public function test_filter_admin_url_rewrites_kayzart_add_new_url_with_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$url    = admin_url( 'post-new.php?post_type=' . Post_Type::POST_TYPE );
		$result = Admin::filter_admin_url( $url, 'post-new.php?post_type=' . Post_Type::POST_TYPE, get_current_blog_id() );

		$this->assertStringNotContainsString( '&amp;', $result );
		$parts = wp_parse_url( $result );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		$this->assertSame( Admin::NEW_POST_ACTION, $query['action'] ?? '' );
		$this->assertSame( Post_Type::POST_TYPE, $query['post_type'] ?? '' );
		$this->assertNotEmpty( $query['_wpnonce'] ?? '' );
	}

	public function test_filter_admin_url_keeps_non_kayzart_routes_unchanged(): void {
		$path   = 'post-new.php?post_type=post';
		$url    = admin_url( $path );
		$result = Admin::filter_admin_url( $url, $path, get_current_blog_id() );

		$this->assertSame( $url, $result );
	}

	public function test_override_admin_bar_new_link_replaces_href(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$admin_bar = new WP_Admin_Bar();
		$admin_bar->add_node(
			array(
				'id'   => 'new-' . Post_Type::POST_TYPE,
				'href' => admin_url( 'post-new.php?post_type=' . Post_Type::POST_TYPE ),
			)
		);

		Admin::override_admin_bar_new_link( $admin_bar );
		$node = $admin_bar->get_node( 'new-' . Post_Type::POST_TYPE );

		$this->assertNotNull( $node );
		$this->assertStringNotContainsString( '&amp;', (string) $node->href );
		$this->assertStringContainsString( 'action=' . Admin::NEW_POST_ACTION, $node->href );
		$this->assertStringContainsString( '_wpnonce=', $node->href );
	}

	public function test_override_new_submenu_link_replaces_add_new_slug(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$parent_slug      = 'edit.php?post_type=' . Post_Type::POST_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		$submenu[ $parent_slug ] = array(
			array( __( 'All KayzArt Pages', 'kayzart-live-code-editor' ), 'edit_posts', 'edit.php?post_type=' . Post_Type::POST_TYPE ),
			array( __( 'Add New', 'kayzart-live-code-editor' ), 'edit_posts', 'post-new.php?post_type=' . Post_Type::POST_TYPE ),
			array( __( 'Settings', 'kayzart-live-code-editor' ), 'manage_options', Admin::SETTINGS_SLUG ),
		);

		Admin::override_new_submenu_link();

		$updated_slug = '';
		$updated_label = '';
		$updated_index = -1;
		$settings_index = -1;
		$items = array_values( (array) ( $submenu[ $parent_slug ] ?? array() ) );
		foreach ( $items as $index => $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( str_contains( $slug, 'action=' . Admin::NEW_POST_ACTION ) ) {
				$updated_slug  = $slug;
				$updated_label = isset( $item[0] ) ? (string) $item[0] : '';
				$updated_index = (int) $index;
			}
			if ( Admin::SETTINGS_SLUG === $slug ) {
				$settings_index = (int) $index;
			}
		}
		$submenu      = $original_submenu;

		$this->assertNotSame( '', $updated_slug );
		$this->assertSame( __( 'Add New KayzArt Page', 'kayzart-live-code-editor' ), $updated_label );
		$this->assertNotSame( -1, $updated_index );
		$this->assertNotSame( -1, $settings_index );
		$this->assertLessThan( $settings_index, $updated_index );
		$this->assertStringContainsString( 'action=' . Admin::NEW_POST_ACTION, $updated_slug );
		$this->assertStringContainsString( 'post_type=' . Post_Type::POST_TYPE, $updated_slug );
		$this->assertStringContainsString( '_wpnonce=', $updated_slug );
	}

	public function test_handle_post_slug_update_sets_flush_flag_only_when_value_changes(): void {
		update_option( Admin::OPTION_FLUSH_REWRITE, '0' );

		Admin::handle_post_slug_update( 'kayzart', 'kayzart' );
		$this->assertSame( '0', get_option( Admin::OPTION_FLUSH_REWRITE, '0' ) );

		Admin::handle_post_slug_update( 'kayzart', 'kayzart-new' );
		$this->assertSame( '1', get_option( Admin::OPTION_FLUSH_REWRITE, '0' ) );
	}

	public function test_handle_post_slug_add_sets_flush_flag_for_non_empty_value(): void {
		update_option( Admin::OPTION_FLUSH_REWRITE, '0' );

		Admin::handle_post_slug_add( Admin::OPTION_POST_SLUG, '' );
		$this->assertSame( '0', get_option( Admin::OPTION_FLUSH_REWRITE, '0' ) );

		Admin::handle_post_slug_add( Admin::OPTION_POST_SLUG, 'custom-slug' );
		$this->assertSame( '1', get_option( Admin::OPTION_FLUSH_REWRITE, '0' ) );
	}

	public function test_maybe_flush_rewrite_rules_clears_flush_option(): void {
		update_option( Admin::OPTION_FLUSH_REWRITE, '1' );

		Admin::maybe_flush_rewrite_rules();

		$this->assertFalse( get_option( Admin::OPTION_FLUSH_REWRITE, false ) );
	}

	public function test_enqueue_assets_calls_wp_enqueue_media_for_editor_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$original_get    = $_GET;
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );
		$before          = did_action( 'wp_enqueue_media' );

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

		$_GET = $original_get;

		$this->assertTrue( wp_script_is( 'kayzart-admin', 'enqueued' ) );
		$this->assertSame( $before + 1, did_action( 'wp_enqueue_media' ) );
	}

	public function test_enqueue_assets_does_not_register_legacy_monaco_loader_and_inline_config_has_no_monaco_path(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$original_get    = $_GET;
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

		$_GET = $original_get;

		$this->assertFalse( wp_script_is( 'kayzart-monaco-loader', 'registered' ) );

		$registered = wp_scripts()->registered['kayzart-admin'] ?? null;
		$this->assertNotNull( $registered );
		$this->assertIsArray( $registered->deps );
		$this->assertNotContains( 'kayzart-monaco-loader', $registered->deps );

		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART = (.+);/', $inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'monacoVsPath', $payload );
	}

	public function test_enqueue_assets_fires_editor_extension_hook_with_context(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$captured_context = null;
		$listener         = static function ( $context ) use ( &$captured_context ): void {
			$captured_context = $context;
		};
		add_action( 'kayzart_editor_enqueue_assets', $listener, 10, 1 );

		$original_get    = $_GET;
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

		$_GET = $original_get;
		remove_action( 'kayzart_editor_enqueue_assets', $listener, 10 );

		$this->assertIsArray( $captured_context );
		$this->assertSame( $post_id, $captured_context['post_id'] ?? null );
		$this->assertSame( 'admin_page_' . Admin::MENU_SLUG, $captured_context['hook_suffix'] ?? null );
		$this->assertSame( 'kayzart-admin', $captured_context['admin_script_handle'] ?? null );
		$this->assertSame( 'kayzart-admin', $captured_context['admin_style_handle'] ?? null );
	}

	public function test_enqueue_assets_does_not_fire_editor_extension_hook_on_other_pages(): void {
		$fired    = false;
		$listener = static function () use ( &$fired ): void {
			$fired = true;
		};
		add_action( 'kayzart_editor_enqueue_assets', $listener, 10, 0 );

		Admin::enqueue_assets( 'settings_page_kayzart-settings' );

		remove_action( 'kayzart_editor_enqueue_assets', $listener, 10 );
		$this->assertFalse( $fired );
	}
}

