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
		delete_option( Admin::OPTION_DELETE_ON_UNINSTALL );
		parent::tearDown();
	}

	public function test_sanitize_post_slug_returns_sanitized_value_or_default(): void {
		$this->assertSame( 'my-custom-slug', Admin::sanitize_post_slug( 'My Custom Slug' ) );
		$this->assertSame( Post_Type::SLUG, Admin::sanitize_post_slug( '' ) );
	}

	public function test_sanitize_default_template_mode_allows_known_values_only(): void {
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'standalone' ) );
		$this->assertSame( 'theme', Admin::sanitize_default_template_mode( 'theme' ) );
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'frame' ) );
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'invalid' ) );
	}

	public function test_sanitize_delete_on_uninstall_accepts_only_string_one(): void {
		$this->assertSame( '1', Admin::sanitize_delete_on_uninstall( '1' ) );
		$this->assertSame( '0', Admin::sanitize_delete_on_uninstall( '0' ) );
		$this->assertSame( '0', Admin::sanitize_delete_on_uninstall( 1 ) );
	}

	public function test_filter_admin_url_rewrites_kayzart_add_new_url_to_page_lp_flow(): void {
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

		$this->assertSame( Admin::NEW_PAGE_ACTION, $query['action'] ?? '' );
		$this->assertArrayNotHasKey( 'post_type', $query );
		$this->assertNotEmpty( $query['_wpnonce'] ?? '' );
	}

	public function test_filter_admin_url_keeps_non_kayzart_routes_unchanged(): void {
		$path   = 'post-new.php?post_type=post';
		$url    = admin_url( $path );
		$result = Admin::filter_admin_url( $url, $path, get_current_blog_id() );

		$this->assertSame( $url, $result );
	}

	public function test_override_admin_bar_new_link_removes_legacy_cpt_node(): void {
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

		$this->assertNull( $node );
	}

	public function test_override_new_submenu_link_removes_legacy_create_and_settings_items(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$parent_slug      = 'edit.php?post_type=' . Post_Type::POST_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		$submenu[ $parent_slug ] = array(
			array( __( 'LP list', 'kayzart-live-code-editor' ), 'edit_posts', 'edit.php?post_type=' . Post_Type::POST_TYPE ),
			array( __( 'Add New', 'kayzart-live-code-editor' ), 'edit_posts', 'post-new.php?post_type=' . Post_Type::POST_TYPE ),
			array( __( 'Settings', 'kayzart-live-code-editor' ), 'manage_options', Admin::SETTINGS_SLUG ),
		);

		Admin::override_new_submenu_link();

		$has_add_new  = false;
		$has_settings = false;
		$items = array_values( (array) ( $submenu[ $parent_slug ] ?? array() ) );
		foreach ( $items as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( 'post-new.php?post_type=' . Post_Type::POST_TYPE === $slug ) {
				$has_add_new = true;
			}
			if ( Admin::SETTINGS_SLUG === $slug ) {
				$has_settings = true;
			}
		}
		$submenu      = $original_submenu;

		$this->assertFalse( $has_add_new );
		$this->assertFalse( $has_settings );
	}

	public function test_register_menu_adds_page_lp_create_submenu(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$page_parent_slug = 'edit.php?post_type=' . Post_Type::PAGE_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		unset( $submenu[ $page_parent_slug ] );

		Admin::register_menu();

		$matched_label = '';
		$matched_slug  = '';
		foreach ( (array) ( $submenu[ $page_parent_slug ] ?? array() ) as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( str_contains( $slug, 'action=' . Admin::NEW_PAGE_ACTION ) ) {
				$matched_label = isset( $item[0] ) ? (string) $item[0] : '';
				$matched_slug  = $slug;
				break;
			}
		}

		$submenu = $original_submenu;

		$this->assertSame( __( 'LPを追加', 'kayzart-live-code-editor' ), $matched_label );
		$this->assertStringContainsString( 'action=' . Admin::NEW_PAGE_ACTION, $matched_slug );
		$this->assertStringContainsString( '_wpnonce=', $matched_slug );
	}

	public function test_register_menu_adds_lp_settings_under_options_not_legacy_cpt(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$options_parent   = 'options-general.php';
		$legacy_parent    = 'edit.php?post_type=' . Post_Type::POST_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		unset( $submenu[ $options_parent ], $submenu[ $legacy_parent ] );

		Admin::register_menu();

		$options_has_settings = false;
		foreach ( (array) ( $submenu[ $options_parent ] ?? array() ) as $item ) {
			if ( Admin::SETTINGS_SLUG === (string) ( $item[2] ?? '' ) ) {
				$options_has_settings = true;
				$this->assertSame( __( 'LP設定', 'kayzart-live-code-editor' ), (string) ( $item[0] ?? '' ) );
				break;
			}
		}

		$legacy_has_settings = false;
		foreach ( (array) ( $submenu[ $legacy_parent ] ?? array() ) as $item ) {
			if ( Admin::SETTINGS_SLUG === (string) ( $item[2] ?? '' ) ) {
				$legacy_has_settings = true;
				break;
			}
		}

		$submenu = $original_submenu;

		$this->assertTrue( $options_has_settings );
		$this->assertFalse( $legacy_has_settings );
	}

	public function test_render_settings_page_supports_extension_tabs(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$tabs_filter = static function ( $tabs ) {
			$tabs['sample'] = 'Sample Tab';
			return $tabs;
		};
		$tab_action = static function (): void {
			echo '<div id="sample-settings-tab">Sample content</div>';
		};
		add_filter( 'kayzart_settings_tabs', $tabs_filter );
		add_action( 'kayzart_render_settings_tab_sample', $tab_action );

		$original_get = $_GET;
		$_GET['tab'] = 'sample';

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$_GET = $original_get;
		remove_filter( 'kayzart_settings_tabs', $tabs_filter );
		remove_action( 'kayzart_render_settings_tab_sample', $tab_action );

		$this->assertStringContainsString( __( 'LP設定', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( __( '基本設定', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( 'Sample Tab', $output );
		$this->assertStringContainsString( 'id="sample-settings-tab"', $output );
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

	public function test_enqueue_assets_does_not_register_legacy_loader_and_inline_config_has_no_legacy_path(): void {
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

		$this->assertFalse( wp_script_is( 'kayzart-legacy-loader', 'registered' ) );

		$registered = wp_scripts()->registered['kayzart-admin'] ?? null;
		$this->assertNotNull( $registered );
		$this->assertIsArray( $registered->deps );
		$this->assertNotContains( 'kayzart-legacy-loader', $registered->deps );

		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART = (.+);/', $inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );
		$this->assertArrayNotHasKey( 'legacyVsPath', $payload );
	}

	public function test_enqueue_assets_inline_config_escapes_script_breakout_sequences(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		$malicious_js = '</script><script>alert("x")</script>';
		update_post_meta( $post_id, '_kayzart_js', $malicious_js );

		$original_get     = $_GET;
		$_GET['post_id']  = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

		$_GET = $original_get;

		$registered = wp_scripts()->registered['kayzart-admin'] ?? null;
		$this->assertNotNull( $registered );
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = (string) end( $before_inline );

		$this->assertStringNotContainsString( '</script>', $inline );
		$this->assertStringContainsString( '<\\/script>', $inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART = (.+);/', $inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );
		$this->assertSame( $malicious_js, $payload['initialJs'] ?? '' );
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

	public function test_register_menu_uses_admin_php_hidden_parent_slug(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu, $_parent_pages;
		$original_submenu      = $submenu;
		$original_parent_pages = $_parent_pages;

		$submenu       = is_array( $submenu ) ? $submenu : array();
		$_parent_pages = is_array( $_parent_pages ) ? $_parent_pages : array();

		Admin::register_menu();

		$hidden_items = (array) ( $submenu[ Admin::HIDDEN_PARENT_SLUG ] ?? array() );
		$editor_item  = null;
		foreach ( $hidden_items as $item ) {
			if ( Admin::MENU_SLUG === (string) ( $item[2] ?? '' ) ) {
				$editor_item = $item;
				break;
			}
		}
		$registered_parent = (string) ( $_parent_pages[ Admin::MENU_SLUG ] ?? '' );

		$submenu       = $original_submenu;
		$_parent_pages = $original_parent_pages;

		$this->assertNotNull( $editor_item );
		$this->assertSame( 'KayzArt', (string) ( $editor_item[3] ?? '' ) );
		$this->assertSame( 'admin.php', Admin::HIDDEN_PARENT_SLUG );
		$this->assertSame( Admin::HIDDEN_PARENT_SLUG, $registered_parent );
	}

	public function test_enqueue_assets_falls_back_when_permalink_filter_returns_null(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$original_get     = $_GET;
		$_GET['post_id']  = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$null_permalink_filter = static function ( $permalink, $post, $leavename ) {
			unset( $permalink, $post, $leavename );
			return null;
		};
		add_filter( 'post_type_link', $null_permalink_filter, 999, 3 );

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

		remove_filter( 'post_type_link', $null_permalink_filter, 999 );
		$_GET = $original_get;

		$registered = wp_scripts()->registered['kayzart-admin'] ?? null;
		$this->assertNotNull( $registered );
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART = (.+);/', $inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );
		$this->assertIsString( $payload['previewUrl'] ?? null );
		$this->assertNotSame( '', $payload['previewUrl'] ?? '' );
		$this->assertIsString( $payload['iframePreviewUrl'] ?? null );
		$this->assertNotSame( '', $payload['iframePreviewUrl'] ?? '' );
	}

	public function test_register_menu_provides_non_null_admin_page_title_for_editor_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $menu, $submenu, $pagenow, $plugin_page, $title, $_parent_pages;
		$original_menu         = $menu;
		$original_submenu      = $submenu;
		$original_pagenow      = $pagenow;
		$original_plugin_page  = $plugin_page;
		$original_title        = $title;
		$original_parent_pages = $_parent_pages;

		$menu          = is_array( $menu ) ? $menu : array();
		$submenu       = is_array( $submenu ) ? $submenu : array();
		$_parent_pages = is_array( $_parent_pages ) ? $_parent_pages : array();

		Admin::register_menu();

		$pagenow     = 'admin.php';
		$plugin_page = Admin::MENU_SLUG;
		$title       = null;

		$page_title = get_admin_page_title();

		$menu          = $original_menu;
		$submenu       = $original_submenu;
		$pagenow       = $original_pagenow;
		$plugin_page   = $original_plugin_page;
		$title         = $original_title;
		$_parent_pages = $original_parent_pages;

		$this->assertIsString( $page_title );
		$this->assertNotSame( '', $page_title );
		$this->assertSame( 'KayzArt', $page_title );
	}
}

