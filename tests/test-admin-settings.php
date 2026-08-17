<?php
/**
 * Admin settings and rewrite behavior tests for Kayzart.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Ai_Setup;
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
		delete_option( Admin::OPTION_ENABLED_POST_TYPES );
		delete_option( Admin::OPTION_DEFAULT_TEMPLATE_MODE );
		delete_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT );
		delete_option( Admin::OPTION_AI_DEFAULT_MODEL );
		delete_option( Admin::OPTION_AI_MAX_TURNS );
		delete_option( Admin::OPTION_AI_MAX_PROMPT_CHARS );
		delete_option( 'kayzart_openai_api_key' );
		delete_option( 'kayzart_delete_on_uninstall' );
		parent::tearDown();
	}

	private function reset_kayzart_settings_api_state(): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( isset( $wp_settings_sections[ Admin::SETTINGS_SLUG ] ) ) {
			unset( $wp_settings_sections[ Admin::SETTINGS_SLUG ] );
		}

		if ( isset( $wp_settings_fields[ Admin::SETTINGS_SLUG ] ) ) {
			unset( $wp_settings_fields[ Admin::SETTINGS_SLUG ] );
		}
	}

	public function test_sanitize_post_slug_returns_sanitized_value_or_default(): void {
		$this->assertSame( 'my-custom-slug', Admin::sanitize_post_slug( 'My Custom Slug' ) );
		$this->assertSame( Post_Type::SLUG, Admin::sanitize_post_slug( '' ) );
	}

	public function test_should_show_post_slug_settings_only_when_slug_is_custom(): void {
		delete_option( Admin::OPTION_POST_SLUG );
		$this->assertFalse( Admin::should_show_post_slug_settings() );

		update_option( Admin::OPTION_POST_SLUG, Post_Type::SLUG );
		$this->assertFalse( Admin::should_show_post_slug_settings() );

		update_option( Admin::OPTION_POST_SLUG, 'custom-slug' );
		$this->assertTrue( Admin::should_show_post_slug_settings() );
	}

	public function test_sanitize_default_template_mode_allows_known_values_only(): void {
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'standalone' ) );
		$this->assertSame( 'theme', Admin::sanitize_default_template_mode( 'theme' ) );
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'frame' ) );
		$this->assertSame( 'standalone', Admin::sanitize_default_template_mode( 'invalid' ) );
	}

	public function test_sanitize_default_editor_layout_allows_known_values_only(): void {
		$this->assertSame( 'code_visible', Admin::sanitize_default_editor_layout( 'code_visible' ) );
		$this->assertSame( 'code_hidden', Admin::sanitize_default_editor_layout( 'code_hidden' ) );
		$this->assertSame( 'code_hidden', Admin::sanitize_default_editor_layout( 'hidden' ) );
		$this->assertSame( 'code_hidden', Admin::sanitize_default_editor_layout( '' ) );
		$this->assertSame( 'code_hidden', Admin::sanitize_default_editor_layout( array() ) );
	}

	public function test_sanitize_ai_default_model_validates_against_discovered_models(): void {
		$filter = static function ( $models ) {
			return array_merge( $models, array(
				array(
					'id'    => 'provider/model-a',
					'label' => 'Model A',
				),
			) );
		};
		add_filter( 'kayzart_ai_available_models', $filter );

		try {
			$this->assertSame( 'provider/model-a', Admin::sanitize_ai_default_model( 'provider/model-a' ) );
			$this->assertSame( '', Admin::sanitize_ai_default_model( 'provider/model-b' ) );
			$this->assertSame( '', Admin::sanitize_ai_default_model( '' ) );
		} finally {
			remove_filter( 'kayzart_ai_available_models', $filter );
		}
	}

	/** An omitted conditional field is distinct from explicitly selecting Auto. */
	public function test_sanitize_ai_default_model_preserves_stored_value_when_field_is_omitted(): void {
		update_option( Admin::OPTION_AI_DEFAULT_MODEL, 'provider/model-a' );

		$this->assertSame( 'provider/model-a', Admin::sanitize_ai_default_model( null ) );
		$this->assertSame( '', Admin::sanitize_ai_default_model( '' ) );
	}

	public function test_sanitize_ai_max_turns_uses_default_and_clamps_to_the_supported_range(): void {
		$this->assertSame( 15, Admin::sanitize_ai_max_turns( '' ) );
		$this->assertSame( 15, Admin::sanitize_ai_max_turns( 'invalid' ) );
		$this->assertSame( 10, Admin::sanitize_ai_max_turns( 1 ) );
		$this->assertSame( 10, Admin::sanitize_ai_max_turns( 10 ) );
		$this->assertSame( 15, Admin::sanitize_ai_max_turns( '15' ) );
		$this->assertSame( 30, Admin::sanitize_ai_max_turns( 30 ) );
		$this->assertSame( 30, Admin::sanitize_ai_max_turns( 100 ) );
	}

	public function test_sanitize_ai_max_prompt_chars_uses_default_and_clamps_to_the_supported_range(): void {
		$this->assertSame( 8000, Admin::sanitize_ai_max_prompt_chars( '' ) );
		$this->assertSame( 8000, Admin::sanitize_ai_max_prompt_chars( 'invalid' ) );
		$this->assertSame( 1000, Admin::sanitize_ai_max_prompt_chars( 1 ) );
		$this->assertSame( 1000, Admin::sanitize_ai_max_prompt_chars( 1000 ) );
		$this->assertSame( 12000, Admin::sanitize_ai_max_prompt_chars( '12000' ) );
		$this->assertSame( 50000, Admin::sanitize_ai_max_prompt_chars( 50000 ) );
		$this->assertSame( 50000, Admin::sanitize_ai_max_prompt_chars( 999999 ) );
	}

	public function test_get_ai_max_prompt_chars_reads_the_option_and_honors_the_filter(): void {
		$this->assertSame( 8000, Admin::get_ai_max_prompt_chars() );

		update_option( Admin::OPTION_AI_MAX_PROMPT_CHARS, 20000 );
		$this->assertSame( 20000, Admin::get_ai_max_prompt_chars() );

		// The filter is the escape hatch for values outside the settings range.
		$filter = static function () {
			return 120000;
		};
		add_filter( 'kayzart_ai_max_prompt_chars', $filter );

		try {
			$this->assertSame( 120000, Admin::get_ai_max_prompt_chars() );
			$this->assertSame( 20000, Admin::get_stored_ai_max_prompt_chars() );
		} finally {
			remove_filter( 'kayzart_ai_max_prompt_chars', $filter );
		}
	}

	public function test_render_ai_default_model_field_discovers_models_once(): void {
		$calls  = 0;
		$filter = static function ( $models ) use ( &$calls ) {
			++$calls;
			return array_merge( $models, array(
				array(
					'id'    => 'provider/model-a',
					'label' => 'Model A',
				),
			) );
		};
		update_option( Admin::OPTION_AI_DEFAULT_MODEL, 'provider/model-a' );
		add_filter( 'kayzart_ai_available_models', $filter );

		try {
			ob_start();
			Admin::render_ai_default_model_field();
			$output = ob_get_clean();
		} finally {
			remove_filter( 'kayzart_ai_available_models', $filter );
		}

		$this->assertSame( 1, $calls );
		$this->assertStringContainsString( 'value="provider/model-a"', $output );
		$this->assertStringContainsString( "selected='selected'", $output );
	}

	/** Direct OpenAI output carries the inactive Connector preference forward. */
	public function test_render_ai_default_model_field_preserves_connector_model_for_direct_openai(): void {
		update_option( Admin::OPTION_AI_DEFAULT_MODEL, 'provider/model-a' );
		update_option( 'kayzart_openai_api_key', 'sk-test-direct-settings' );
		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		try {
			ob_start();
			Admin::render_ai_default_model_field();
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'kayzart_ai_feature_enabled', '__return_true' );
			remove_filter( 'kayzart_ai_sdk_present', '__return_false' );
			remove_filter( 'kayzart_ai_provider_configured', '__return_false' );
			remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
			remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
			remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		}

		$this->assertStringContainsString( 'type="hidden"', $output );
		$this->assertStringContainsString( 'name="' . Admin::OPTION_AI_DEFAULT_MODEL . '"', $output );
		$this->assertStringContainsString( 'value="provider/model-a"', $output );
		$this->assertStringContainsString( 'Direct OpenAI access uses this fixed model.', $output );
	}

	public function test_render_ai_max_turns_field_shows_the_configured_value_and_range(): void {
		update_option( Admin::OPTION_AI_MAX_TURNS, 20 );

		ob_start();
		Admin::render_ai_max_turns_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . Admin::OPTION_AI_MAX_TURNS . '"', $output );
		$this->assertStringContainsString( 'value="20"', $output );
		$this->assertStringContainsString( 'min="10"', $output );
		$this->assertStringContainsString( 'max="30"', $output );
		$this->assertStringContainsString( 'step="1"', $output );
	}

	public function test_render_ai_max_prompt_chars_field_shows_the_configured_value_and_range(): void {
		update_option( Admin::OPTION_AI_MAX_PROMPT_CHARS, 12000 );

		ob_start();
		Admin::render_ai_max_prompt_chars_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="' . Admin::OPTION_AI_MAX_PROMPT_CHARS . '"', $output );
		$this->assertStringContainsString( 'value="12000"', $output );
		$this->assertStringContainsString( 'min="1000"', $output );
		$this->assertStringContainsString( 'max="50000"', $output );
		$this->assertStringContainsString( 'step="1"', $output );
		$this->assertStringNotContainsString( 'A filter currently overrides', $output );
	}

	public function test_render_ai_max_prompt_chars_field_shows_the_stored_value_when_a_filter_overrides_it(): void {
		update_option( Admin::OPTION_AI_MAX_PROMPT_CHARS, 12000 );

		$filter = static function () {
			return 120000;
		};
		add_filter( 'kayzart_ai_max_prompt_chars', $filter );

		try {
			ob_start();
			Admin::render_ai_max_prompt_chars_field();
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'kayzart_ai_max_prompt_chars', $filter );
		}

		// The input is bound to the option, so it must stay inside min/max and must
		// not write the filtered value back when another setting is saved.
		$this->assertStringContainsString( 'value="12000"', $output );
		$this->assertStringNotContainsString( 'value="120000"', $output );
		$this->assertStringContainsString( 'The limit in use is 120000 characters.', $output );
	}

	public function test_filter_admin_url_keeps_kayzart_add_new_url_unchanged(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$url    = admin_url( 'post-new.php?post_type=' . Post_Type::POST_TYPE );
		$result = Admin::filter_admin_url( $url, 'post-new.php?post_type=' . Post_Type::POST_TYPE, get_current_blog_id() );

		$this->assertSame( $url, $result );
	}

	public function test_filter_admin_url_keeps_non_kayzart_routes_unchanged(): void {
		$path   = 'post-new.php?post_type=post';
		$url    = admin_url( $path );
		$result = Admin::filter_admin_url( $url, $path, get_current_blog_id() );

		$this->assertSame( $url, $result );
	}

	public function test_override_admin_bar_new_link_keeps_legacy_cpt_node(): void {
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
		$this->assertSame( admin_url( 'post-new.php?post_type=' . Post_Type::POST_TYPE ), $node->href );
	}

	public function test_override_new_submenu_link_keeps_legacy_create_and_settings_items(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$parent_slug      = 'edit.php?post_type=' . Post_Type::POST_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		$submenu[ $parent_slug ] = array(
			array( __( 'Pages', 'kayzart-live-code-editor' ), 'edit_posts', 'edit.php?post_type=' . Post_Type::POST_TYPE ),
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

		$this->assertTrue( $has_add_new );
		$this->assertTrue( $has_settings );
	}

	public function test_register_menu_adds_enabled_post_type_lp_create_submenus(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$page_parent_slug = 'edit.php?post_type=' . Post_Type::PAGE_TYPE;
		$post_parent_slug = 'edit.php';
		$submenu          = is_array( $submenu ) ? $submenu : array();
		unset( $submenu[ $page_parent_slug ], $submenu[ $post_parent_slug ] );
		update_option( Admin::OPTION_ENABLED_POST_TYPES, array( Post_Type::PAGE_TYPE, 'post' ) );

		Admin::register_menu();

		$matched_label = '';
		$matched_slug  = '';
		foreach ( (array) ( $submenu[ $page_parent_slug ] ?? array() ) as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false !== strpos( $slug, 'page=' . Admin::NEW_SLUG ) ) {
				$matched_label = isset( $item[0] ) ? (string) $item[0] : '';
				$matched_slug  = $slug;
				break;
			}
		}

		$this->assertSame( __( 'Add with Kayzart', 'kayzart-live-code-editor' ), $matched_label );
		$this->assertStringContainsString( 'page=' . Admin::NEW_SLUG, $matched_slug );
		$this->assertStringContainsString( Admin::NEW_TYPE_PARAM . '=page', $matched_slug );
		// The create screen has no side effects, so it needs no nonce; the form
		// it renders carries one.
		$this->assertStringNotContainsString( 'action=' . Admin::NEW_PAGE_ACTION, $matched_slug );

		$post_matched_slug = '';
		foreach ( (array) ( $submenu[ $post_parent_slug ] ?? array() ) as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( false !== strpos( $slug, 'page=' . Admin::NEW_SLUG ) ) {
				$post_matched_slug = $slug;
				break;
			}
		}
		$this->assertStringContainsString( Admin::NEW_TYPE_PARAM . '=post', $post_matched_slug );

		$submenu = $original_submenu;
	}

	public function test_get_new_screen_url_avoids_a_post_type_query_arg(): void {
		// A post_type query arg sets $typenow, which makes admin.php resolve the
		// page's parent as "admin.php?post_type=..." instead of the Kayzart menu.
		// The screen then dies with "Cannot load kayzart-new".
		$url = Admin::get_new_screen_url( Post_Type::PAGE_TYPE );

		$args = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		$this->assertSame( Admin::NEW_SLUG, $args['page'] ?? '' );
		$this->assertSame( Post_Type::PAGE_TYPE, $args[ Admin::NEW_TYPE_PARAM ] ?? '' );
		$this->assertArrayNotHasKey( 'post_type', $args );
	}

	public function test_get_convert_screen_url_avoids_a_post_type_query_arg(): void {
		$args = array();
		parse_str( (string) wp_parse_url( Admin::get_convert_screen_url( 123 ), PHP_URL_QUERY ), $args );

		$this->assertSame( Admin::CONVERT_SLUG, $args['page'] ?? '' );
		$this->assertSame( '123', (string) ( $args['post_id'] ?? '' ) );
		$this->assertArrayNotHasKey( 'post_type', $args );
	}

	public function test_register_menu_adds_settings_under_kayzart_not_options_or_legacy_cpt(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $submenu;
		$original_submenu = $submenu;
		$options_parent   = 'options-general.php';
		$legacy_parent    = 'edit.php?post_type=' . Post_Type::POST_TYPE;
		$submenu          = is_array( $submenu ) ? $submenu : array();
		unset( $submenu[ $options_parent ], $submenu[ $legacy_parent ], $submenu[ Admin::NEW_SLUG ] );

		Admin::register_menu();

		$hub_settings_cap = '';
		foreach ( (array) ( $submenu[ Admin::NEW_SLUG ] ?? array() ) as $item ) {
			if ( Admin::SETTINGS_SLUG === (string) ( $item[2] ?? '' ) ) {
				$hub_settings_cap = (string) ( $item[1] ?? '' );
				$this->assertSame( __( 'Settings', 'kayzart-live-code-editor' ), (string) ( $item[0] ?? '' ) );
				break;
			}
		}

		$stale_parents = array( $options_parent, $legacy_parent );
		$stale_hits    = array();
		foreach ( $stale_parents as $parent ) {
			foreach ( (array) ( $submenu[ $parent ] ?? array() ) as $item ) {
				if ( Admin::SETTINGS_SLUG === (string) ( $item[2] ?? '' ) ) {
					$stale_hits[] = $parent;
					break;
				}
			}
		}

		$submenu = $original_submenu;

		$this->assertSame( 'manage_options', $hub_settings_cap );
		$this->assertSame( array(), $stale_hits );
	}

	public function test_register_menu_adds_kayzart_top_level_at_edit_posts(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $menu, $submenu;
		$original_menu    = $menu;
		$original_submenu = $submenu;
		$menu             = is_array( $menu ) ? $menu : array();
		$submenu          = is_array( $submenu ) ? $submenu : array();
		unset( $submenu[ Admin::NEW_SLUG ] );

		Admin::register_menu();

		$hub_cap = '';
		foreach ( $menu as $item ) {
			if ( Admin::NEW_SLUG === (string) ( $item[2] ?? '' ) ) {
				$hub_cap = (string) ( $item[1] ?? '' );
				break;
			}
		}

		$child_slugs = array();
		foreach ( (array) ( $submenu[ Admin::NEW_SLUG ] ?? array() ) as $item ) {
			$child_slugs[] = (string) ( $item[2] ?? '' );
		}

		$menu    = $original_menu;
		$submenu = $original_submenu;

		$this->assertSame( 'edit_posts', $hub_cap );
		$this->assertSame(
			array( Admin::NEW_SLUG, Admin::SETTINGS_SLUG ),
			$child_slugs,
			'The menu must expose exactly Add new and Settings — no landing screen and no duplicate page list.'
		);
	}

	public function test_render_ai_section_reports_ai_availability(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$unavailable = static function () {
			return false;
		};
		add_filter( 'kayzart_ai_sdk_present', $unavailable );

		ob_start();
		Admin::render_ai_section();
		$output = (string) ob_get_clean();

		remove_filter( 'kayzart_ai_sdk_present', $unavailable );

		$this->assertStringContainsString( __( 'AI connection configured', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( __( 'Action Scheduler', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString(
			__( 'AI editing is unavailable until every requirement below is met.', 'kayzart-live-code-editor' ),
			$output
		);
	}

	public function test_render_new_page_uses_modern_sections_and_preserves_defaults(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		update_option( 'kayzart_openai_api_key', 'sk-test-create-screen' );
		update_option( Admin::OPTION_ENABLED_POST_TYPES, array( Post_Type::PAGE_TYPE, 'post' ) );

		ob_start();
		Admin::render_new_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'class="wrap kayzart-create-page"', $output );
		$this->assertStringContainsString(
			__( 'Create a landing page with AI. By default, it is created as an independent page that is not affected by your theme design.', 'kayzart-live-code-editor' ),
			$output
		);
		$this->assertStringContainsString( 'name="post_type" value="page" checked=', $output );
		$this->assertStringContainsString( 'name="mode" value="tailwind" checked=', $output );
		$this->assertStringContainsString( __( 'Recommended', 'kayzart-live-code-editor' ), $output );
		$this->assertStringNotContainsString( __( 'You can change this later in the editor.', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( 'name="initial_ai_prompt"', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringNotContainsString( 'class="form-table"', $output );
		$this->assertStringContainsString( '<details class="kayzart-create-advanced">', $output );
		$this->assertStringContainsString( 'class="kayzart-create-advanced__chevron" aria-hidden="true"', $output );
		$this->assertStringContainsString( 'id="kayzart-generate-ai"', $output );
		$this->assertStringContainsString( 'name="start_mode" value="ai"', $output );
		$this->assertStringContainsString( 'id="kayzart-create-blank"', $output );
		$this->assertStringContainsString( 'id="kayzart-create-blank-hint" hidden="hidden"', $output );
		$this->assertStringContainsString( 'name="start_mode" value="blank"', $output );
		$this->assertStringNotContainsString( 'name="start_mode" type="radio"', $output );
		delete_option( 'kayzart_openai_api_key' );

		$title_position    = strpos( $output, 'name="post_title"' );
		$prompt_position   = strpos( $output, 'name="initial_ai_prompt"' );
		$page_position     = strpos( $output, 'name="post_type" value="page"' );
		$post_position     = strpos( $output, 'name="post_type" value="post"' );
		$tailwind_position = strpos( $output, 'name="mode" value="tailwind"' );
		$normal_position   = strpos( $output, 'name="mode" value="normal"' );

		$this->assertIsInt( $title_position );
		$this->assertIsInt( $prompt_position );
		$this->assertIsInt( $page_position );
		$this->assertIsInt( $post_position );
		$this->assertIsInt( $tailwind_position );
		$this->assertIsInt( $normal_position );
		$this->assertLessThan( $prompt_position, $title_position );
		$this->assertLessThan( $page_position, $prompt_position );
		$this->assertLessThan( $post_position, $page_position );
		$this->assertLessThan( $normal_position, $tailwind_position );
	}

	public function test_enqueue_assets_loads_only_new_page_assets_on_new_screen(): void {
		Admin::enqueue_assets( 'toplevel_page_' . Admin::NEW_SLUG );

		$this->assertTrue( wp_style_is( 'kayzart-new-page', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'kayzart-new-page', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'kayzart-admin', 'enqueued' ) );

		$registered = wp_scripts()->registered['kayzart-new-page'] ?? null;
		$this->assertNotNull( $registered );
		$before_inline = isset( $registered->extra['before'] ) ? (array) $registered->extra['before'] : array();
		$inline        = implode( "\n", $before_inline );
		$this->assertStringContainsString( 'maxPromptChars', $inline );
		$this->assertStringContainsString( 'ai\\/prompts\\/improve', $inline );
		$this->assertStringContainsString( 'restNonce', $inline );
	}

	public function test_render_new_page_shows_prompt_improver_only_when_ai_is_available(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );
		wp_set_current_user( $admin_id );
		update_option( 'kayzart_openai_api_key', 'sk-test-create-screen' );

		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		ob_start();
		Admin::render_new_page();
		$available_output = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="kayzart-ai-improve"', $available_output );
		$this->assertStringContainsString( 'id="kayzart-ai-improve-undo"', $available_output );

		delete_option( 'kayzart_openai_api_key' );
		ob_start();
		Admin::render_new_page();
		$unavailable_output = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'id="kayzart-ai-improve"', $unavailable_output );
		$this->assertStringNotContainsString( 'id="kayzart-initial-ai-prompt"', $unavailable_output );
		$this->assertStringNotContainsString( 'id="kayzart-generate-ai"', $unavailable_output );
		$this->assertStringContainsString( 'id="kayzart-create-blank"', $unavailable_output );
		$this->assertStringContainsString( __( 'Create blank page', 'kayzart-live-code-editor' ), $unavailable_output );

		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );
		remove_filter( 'kayzart_ai_sdk_present', '__return_false' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_false' );
	}

	public function test_get_settings_url_points_at_the_hub_and_keeps_tab_support(): void {
		$this->assertSame(
			admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG ),
			Admin::get_settings_url()
		);
		$this->assertSame(
			admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG . '&tab=license' ),
			Admin::get_settings_url( 'license' )
		);
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

		$this->assertStringContainsString( __( 'Settings', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( __( '基本設定', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( 'Sample Tab', $output );
		$this->assertStringContainsString( 'id="sample-settings-tab"', $output );
	}

	public function test_render_settings_page_hides_post_slug_field_for_default_slug(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( Admin::OPTION_POST_SLUG );
		$this->reset_kayzart_settings_api_state();
		Admin::register_settings();

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( __( 'Kayzart slug', 'kayzart-live-code-editor' ), $output );
		$this->assertStringNotContainsString( 'name="' . Admin::OPTION_POST_SLUG . '"', $output );
	}

	public function test_render_settings_page_places_ai_editing_first(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->reset_kayzart_settings_api_state();
		Admin::register_settings();

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$ai_position       = strpos( $output, __( 'AI editing', 'kayzart-live-code-editor' ) );
		$template_position = strpos( $output, __( 'Page template', 'kayzart-live-code-editor' ) );
		$this->assertIsInt( $ai_position );
		$this->assertIsInt( $template_position );
		$this->assertTrue( $ai_position < $template_position );
	}

	public function test_render_settings_page_shows_post_slug_field_for_custom_slug(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option( Admin::OPTION_POST_SLUG, 'custom-slug' );
		$this->reset_kayzart_settings_api_state();
		Admin::register_settings();

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( __( 'Kayzart slug', 'kayzart-live-code-editor' ), $output );
		$this->assertStringContainsString( 'name="' . Admin::OPTION_POST_SLUG . '"', $output );
	}

	public function test_render_settings_page_hides_delete_on_uninstall_field(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		update_option( 'kayzart_delete_on_uninstall', '1' );
		$this->reset_kayzart_settings_api_state();
		Admin::register_settings();

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Delete data on uninstall', $output );
		$this->assertStringNotContainsString( 'kayzart_delete_on_uninstall', $output );
	}

	public function test_render_settings_page_hides_legacy_default_editor_layout_field(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->reset_kayzart_settings_api_state();
		Admin::register_settings();

		ob_start();
		Admin::render_settings_page();
		$output = (string) ob_get_clean();

		$this->assertStringNotContainsString( __( 'Default editor layout', 'kayzart-live-code-editor' ), $output );
		$this->assertStringNotContainsString( 'name="' . Admin::OPTION_DEFAULT_EDITOR_LAYOUT . '"', $output );
	}

	public function test_render_enabled_post_types_field_mentions_convert_action_for_existing_posts(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		ob_start();
		Admin::render_enabled_post_types_field();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Start editing with Kayzart', $output );
		$this->assertStringContainsString( 'Add with Kayzart', $output );
		$this->assertStringNotContainsString( 'opened in the Kayzart editor', $output );
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

		$original_get     = $_GET;
		$_GET['post_id']  = (string) $post_id;
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

	public function test_enqueue_assets_inline_config_includes_document_html_attributes(): void {
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

		$registered = wp_scripts()->registered['kayzart-admin'] ?? null;
		$this->assertNotNull( $registered );
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );

		$this->assertMatchesRegularExpression( '/window\\.KAYZART = (.+);/', $inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'documentHtmlAttributes', $payload );
		$this->assertIsString( $payload['documentHtmlAttributes'] );
		$this->assertStringContainsString( 'lang=', $payload['documentHtmlAttributes'] );
	}

	/**
	 * Editor configuration includes the complete AI availability status.
	 */
	public function test_enqueue_assets_inline_config_includes_ai_availability(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);
		update_option( 'kayzart_openai_api_key', 'sk-test-inline-config' );

		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', '__return_false' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );
		get_role( 'administrator' )->add_cap( Ai_Setup::CAPABILITY );

		wp_dequeue_script( 'kayzart-admin' );
		wp_deregister_script( 'kayzart-admin' );
		$original_get    = $_GET;
		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );
		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );
		$_GET = $original_get;

		remove_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		delete_option( 'kayzart_openai_api_key' );
		remove_filter( 'kayzart_ai_sdk_present', '__return_false' );
		remove_filter( 'kayzart_ai_provider_configured', '__return_false' );
		remove_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		remove_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		remove_filter( 'kayzart_ai_dom_present', '__return_true' );

		$registered    = wp_scripts()->registered['kayzart-admin'] ?? null;
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );
		preg_match( '/window\\.KAYZART = (.+);/', $inline, $matches );
		$payload = json_decode( $matches[1] ?? '', true );

		$this->assertSame(
			array(
				'available'           => true,
				'setupState'          => 'ready',
				'backend'             => 'openai_direct',
				'featureEnabled'      => true,
				'sdkPresent'          => false,
				'providerConfigured'  => true,
				'connectorConfigured' => false,
				'directKeyConfigured' => true,
				'directKeySource'     => 'database',
				'schedulerPresent'    => true,
				'mbstringPresent'     => true,
				'domPresent'          => true,
				'canEdit'             => true,
				'jobsUrl'             => rest_url( 'kayzart/v1/ai/jobs' ),
				'jobsBaseUrl'         => rest_url( 'kayzart/v1/ai/jobs/' ),
				'timelineUrl'         => rest_url( 'kayzart/v1/ai/timeline' ),
				'timelineBaseUrl'     => rest_url( 'kayzart/v1/ai/timeline/' ),
				'connectorsUrl'       => admin_url( 'options-connectors.php' ),
				'settingsUrl'         => Admin::get_settings_url(),
				'canManageConnectors' => true,
				'canManageSettings'   => true,
				'maxPromptChars'      => Admin::AI_MAX_PROMPT_CHARS_DEFAULT,
				'initialRequest'      => null,
			),
			$payload['ai'] ?? null
		);
	}

	public function test_enqueue_assets_inline_config_includes_layout_migration_data(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
			)
		);

		update_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT, 'code_hidden' );
		wp_dequeue_script( 'kayzart-admin' );
		wp_deregister_script( 'kayzart-admin' );

		$original_get     = $_GET;
		$_GET['post_id']  = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );
		$_GET['kayzart_entry'] = 'ai';

		Admin::enqueue_assets( 'admin_page_' . Admin::MENU_SLUG );

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
		$this->assertSame( 'code_hidden', $payload['legacyCodeVisibilityFallback'] ?? '' );
		$this->assertArrayNotHasKey( 'defaultEditorLayout', $payload );
		$this->assertSame( 'ai', $payload['initialEntryMode'] ?? null );
		$this->assertSame(
			'kayzart.editorLayout.v1.site.' . get_current_blog_id() . '.user.' . $admin_id,
			$payload['layoutStorageNamespace'] ?? ''
		);
	}

	public function test_legacy_editor_layout_fallback_respects_only_valid_saved_settings(): void {
		update_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT, 'code_visible' );
		$this->assertSame( 'code_visible', Admin::get_legacy_editor_layout_fallback() );

		update_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT, 'code_hidden' );
		$this->assertSame( 'code_hidden', Admin::get_legacy_editor_layout_fallback() );

		update_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT, 'invalid' );
		$this->assertSame( 'code_hidden', Admin::get_legacy_editor_layout_fallback() );

		delete_option( Admin::OPTION_DEFAULT_EDITOR_LAYOUT );
		$this->assertSame( 'code_hidden', Admin::get_legacy_editor_layout_fallback() );
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

		$original_get     = $_GET;
		$_GET['post_id']  = (string) $post_id;
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
		$this->assertSame( 'Kayzart', (string) ( $editor_item[3] ?? '' ) );
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
		$this->assertSame( 'Kayzart', $page_title );
	}

	/**
	 * WordPress version in place before a test overrode it.
	 *
	 * @var string|null
	 */
	private $original_wp_version = null;

	/**
	 * Apply the shared AI runtime gates with an explicit connector state.
	 *
	 * @param bool   $sdk_present         Whether the AI Client SDK is present.
	 * @param bool   $provider_configured Whether a Connector is configured.
	 * @param string $version             WordPress version to run the test under.
	 */
	private function set_ai_gates( bool $sdk_present, bool $provider_configured, string $version = '7.0' ): void {
		global $wp_version;

		$this->original_wp_version = (string) $wp_version;
		$wp_version                = $version;

		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', $sdk_present ? '__return_true' : '__return_false' );
		add_filter( 'kayzart_ai_provider_configured', $provider_configured ? '__return_true' : '__return_false' );
	}

	/**
	 * Remove every AI gate override applied by a test.
	 */
	private function clear_ai_gates(): void {
		global $wp_version;

		if ( null !== $this->original_wp_version ) {
			$wp_version                = $this->original_wp_version;
			$this->original_wp_version = null;
		}

		remove_all_filters( 'kayzart_ai_feature_enabled' );
		remove_all_filters( 'kayzart_ai_scheduler_present' );
		remove_all_filters( 'kayzart_ai_mbstring_present' );
		remove_all_filters( 'kayzart_ai_dom_present' );
		remove_all_filters( 'kayzart_ai_sdk_present' );
		remove_all_filters( 'kayzart_ai_provider_configured' );
		remove_all_filters( 'kayzart_ai_show_direct_key_field' );
		delete_option( Admin::OPTION_DORMANT_KEY_NOTICE );
		delete_option( Admin::OPTION_CONNECTOR_NOTICE );
		Admin::flush_cached_ai_backend();
	}

	/**
	 * Evaluate the direct field gate as if the settings screen were being rendered.
	 *
	 * @return bool
	 */
	private function should_show_direct_field_on_settings_screen(): bool {
		$original_get = $_GET;
		$_GET['page'] = Admin::SETTINGS_SLUG;
		$result       = Admin::should_show_direct_openai_field();
		$_GET         = $original_get;

		return $result;
	}

	/** A Connector-configured site with no stored key is not offered the field. */
	public function test_direct_openai_field_is_hidden_once_a_connector_serves_the_site(): void {
		$this->set_ai_gates( true, true );

		try {
			$this->assertFalse( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** A stored key keeps the field reachable so it can be reviewed and removed. */
	public function test_direct_openai_field_stays_visible_while_a_key_is_stored(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-dormant' );
		$this->set_ai_gates( true, true );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** Sites without the AI Client SDK still need the direct field. */
	public function test_direct_openai_field_is_offered_without_the_sdk(): void {
		$this->set_ai_gates( false, false );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** WordPress 7.0 without a ready Connector keeps the direct fallback available. */
	public function test_direct_openai_field_is_offered_when_no_connector_is_configured(): void {
		$this->set_ai_gates( true, false );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** The gate is filterable for bespoke integrations. */
	public function test_direct_openai_field_visibility_is_filterable(): void {
		$this->set_ai_gates( true, true );
		add_filter( 'kayzart_ai_show_direct_key_field', '__return_true' );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** The probe is skipped outside the settings screen so admin_init stays cheap. */
	public function test_direct_openai_field_gate_does_not_probe_outside_the_settings_screen(): void {
		$this->set_ai_gates( true, true );
		$probes = 0;
		$filter = function ( $configured ) use ( &$probes ) {
			++$probes;
			return $configured;
		};
		add_filter( 'kayzart_ai_provider_configured', $filter );

		try {
			$this->assertTrue( Admin::should_show_direct_openai_field() );
			$this->assertSame( 0, $probes );
		} finally {
			remove_filter( 'kayzart_ai_provider_configured', $filter );
			$this->clear_ai_gates();
		}
	}

	/** A dormant key is flagged for removal instead of reading as configuration. */
	public function test_render_openai_api_key_field_flags_a_dormant_key(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-dormant-render' );
		$this->set_ai_gates( true, true );

		try {
			ob_start();
			Admin::render_openai_api_key_field();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringContainsString( 'This key is not in use.', $output );
		$this->assertStringContainsString( '<details open>', $output );
		$this->assertStringContainsString( 'Remove saved API key', $output );
	}

	/** Without a Connector the field renders plainly, with no disclosure wrapper. */
	public function test_render_openai_api_key_field_stays_plain_without_a_connector(): void {
		$this->set_ai_gates( false, false );

		try {
			ob_start();
			Admin::render_openai_api_key_field();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringNotContainsString( '<details', $output );
		$this->assertStringContainsString( 'name="' . Admin::OPTION_OPENAI_API_KEY . '"', $output );
	}

	/** The dormant-key notice fires once and only for a database-stored key. */
	public function test_dormant_openai_key_notice_is_shown_once(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'kayzart_openai_api_key', 'sk-test-dormant-notice' );
		$this->set_ai_gates( true, true );

		try {
			ob_start();
			Admin::maybe_render_dormant_openai_key_notice();
			$first = (string) ob_get_clean();

			ob_start();
			Admin::maybe_render_dormant_openai_key_notice();
			$second = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringContainsString( 'no longer used', $first );
		$this->assertSame( '', $second );
	}

	/** No Connector means the saved key is still in use, so nothing is announced. */
	public function test_dormant_openai_key_notice_is_silent_while_the_key_is_in_use(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'kayzart_openai_api_key', 'sk-test-active-notice' );
		$this->set_ai_gates( true, false );

		try {
			ob_start();
			Admin::maybe_render_dormant_openai_key_notice();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertSame( '', $output );
	}

	/**
	 * A Connector below WordPress 7.0 still loses to the direct backend.
	 *
	 * The AI Client can be present and configured on 5.9-6.9, where
	 * Ai_Availability rejects it on version, so the stored key is still serving
	 * every request and must not be presented as unused.
	 */
	public function test_configured_connector_below_wp_70_leaves_the_direct_key_active(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-legacy-connector' );
		$this->set_ai_gates( true, true, '6.9' );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );

			ob_start();
			Admin::render_openai_api_key_field();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringNotContainsString( 'This key is not in use.', $output );
		$this->assertStringNotContainsString( '<details', $output );
	}

	/** Hiding the field below WordPress 7.0 would lock AI editing out entirely. */
	public function test_direct_openai_field_is_offered_below_wp_70_even_with_a_configured_sdk(): void {
		$this->set_ai_gates( true, true, '6.9' );

		try {
			$this->assertTrue( $this->should_show_direct_field_on_settings_screen() );
		} finally {
			$this->clear_ai_gates();
		}
	}

	/** The notice probe is bounded, not repeated on every admin page load. */
	public function test_dormant_openai_key_notice_probes_the_provider_at_most_once(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'kayzart_openai_api_key', 'sk-test-probe-budget' );
		// The direct-key fallback state: SDK present, no Connector, key in use.
		$this->set_ai_gates( true, false );
		$probes = 0;
		$filter = function ( $configured ) use ( &$probes ) {
			++$probes;
			return $configured;
		};
		add_filter( 'kayzart_ai_provider_configured', $filter );

		try {
			ob_start();
			Admin::maybe_render_dormant_openai_key_notice();
			Admin::maybe_render_dormant_openai_key_notice();
			Admin::maybe_render_dormant_openai_key_notice();
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'kayzart_ai_provider_configured', $filter );
			$this->clear_ai_gates();
		}

		$this->assertSame( '', $output );
		$this->assertSame( 1, $probes );
	}

	/** Below WordPress 7.0 the dormant notice never probes at all. */
	public function test_dormant_openai_key_notice_skips_the_probe_below_wp_70(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'kayzart_openai_api_key', 'sk-test-legacy-probe' );
		$this->set_ai_gates( true, true, '6.9' );
		$probes = 0;
		$filter = function ( $configured ) use ( &$probes ) {
			++$probes;
			return $configured;
		};
		add_filter( 'kayzart_ai_provider_configured', $filter );

		try {
			ob_start();
			Admin::maybe_render_dormant_openai_key_notice();
			$output = (string) ob_get_clean();
		} finally {
			remove_filter( 'kayzart_ai_provider_configured', $filter );
			$this->clear_ai_gates();
		}

		$this->assertSame( '', $output );
		$this->assertSame( 0, $probes );
	}

	/**
	 * Setup advice below WordPress 7.0 names the only action that can work.
	 *
	 * A separately loaded AI Client does not make Connectors reachable there:
	 * the backend is rejected on version and options-connectors.php does not
	 * exist, so entering a direct key is the only way to enable AI editing.
	 */
	public function test_setup_advice_below_wp_70_points_at_the_direct_key(): void {
		$this->set_ai_gates( true, false, '6.9' );

		try {
			ob_start();
			Admin::render_ai_section();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringContainsString( 'Enter an OpenAI API key below.', $output );
		$this->assertStringNotContainsString( 'Configure a WordPress Connector', $output );
		$this->assertStringNotContainsString( 'options-connectors.php', $output );
	}

	/** On WordPress 7.0 the checklist points at Connectors instead. */
	public function test_setup_advice_on_wp_70_points_at_connectors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_ai_gates( true, false );

		try {
			ob_start();
			Admin::render_ai_section();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringContainsString( 'Configure a WordPress Connector', $output );
		$this->assertStringContainsString( 'options-connectors.php', $output );
	}

	/** Without the SDK the advice stays on the direct key. */
	public function test_setup_advice_without_the_sdk_points_at_the_direct_key(): void {
		$this->set_ai_gates( false, false );

		try {
			ob_start();
			Admin::render_ai_section();
			$output = (string) ob_get_clean();
		} finally {
			$this->clear_ai_gates();
		}

		$this->assertStringContainsString( 'Enter an OpenAI API key below.', $output );
		$this->assertStringNotContainsString( 'Configure a WordPress Connector', $output );
	}

	/** Saving or removing the key invalidates the cached backend. */
	public function test_cached_ai_backend_is_flushed_when_the_direct_key_changes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_ai_gates( true, false );

		try {
			update_option( 'kayzart_openai_api_key', 'sk-test-cache-flush' );
			ob_start();
			Admin::maybe_render_connector_migration_notice();
			ob_end_clean();
			$this->assertNotFalse( get_transient( Admin::TRANSIENT_AI_BACKEND ) );

			delete_option( 'kayzart_openai_api_key' );
			$this->assertFalse( get_transient( Admin::TRANSIENT_AI_BACKEND ) );
		} finally {
			$this->clear_ai_gates();
		}
	}
}
