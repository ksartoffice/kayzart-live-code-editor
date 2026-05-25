<?php
/**
 * Admin screen integration for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin UI routes and assets.
 */
class Admin {

	const MENU_SLUG                    = 'kayzart';
	const SETTINGS_SLUG                = 'kayzart-settings';
	const SETTINGS_GROUP               = 'kayzart_settings';
	const NEW_POST_ACTION              = 'kayzart_new';
	const NEW_POST_NONCE_ACTION        = 'kayzart_new_post';
	const NEW_PAGE_ACTION              = 'kayzart_new_page';
	const NEW_PAGE_NONCE_ACTION        = 'kayzart_new_page';
	const REDIRECT_NONCE_ACTION        = 'kayzart_redirect';
	const EDITOR_PAGE_NONCE_ACTION     = 'kayzart_editor_page';
	const OPTION_POST_SLUG             = 'kayzart_post_slug';
	const OPTION_ENABLED_POST_TYPES    = 'kayzart_enabled_post_types';
	const OPTION_DEFAULT_TEMPLATE_MODE = 'kayzart_default_template_mode';
	const OPTION_FLUSH_REWRITE         = 'kayzart_flush_rewrite';
	const HIDDEN_PARENT_SLUG           = 'admin.php';
	const ADMIN_TITLE_SEPARATORS       = array(
		' ' . "\xE2\x80\xB9" . ' ',
		' &lsaquo; ',
	);
	/**
	 * Register admin hooks.
	 */
	public static function init(): void {

		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ), 10, 2 );
		add_action( 'current_screen', array( __CLASS__, 'maybe_suppress_editor_notices' ) );
		add_action( 'admin_action_kayzart', array( __CLASS__, 'action_redirect' ) ); // admin.php?action=kayzart.
		add_action( 'admin_action_' . self::NEW_POST_ACTION, array( __CLASS__, 'action_create_new_post' ) );
		add_action( 'admin_action_' . self::NEW_PAGE_ACTION, array( __CLASS__, 'action_create_new_page' ) );
		add_action( 'update_option_' . self::OPTION_POST_SLUG, array( __CLASS__, 'handle_post_slug_update' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION_POST_SLUG, array( __CLASS__, 'handle_post_slug_add' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 20 );
	}

	/**
	 * Suppress all admin notices on the full-screen KayzArt editor page.
	 *
	 * @param \WP_Screen $screen Current admin screen.
	 */
	public static function maybe_suppress_editor_notices( $screen ): void {
		if ( ! $screen instanceof \WP_Screen ) {
			return;
		}

		if ( 'admin_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}

		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}

	/**
	 * Build the browser title for the KayzArt editor screen.
	 *
	 * @param string $admin_title Current admin title.
	 * @param string $title       Current admin page title (left side).
	 * @return string
	 */
	public static function filter_admin_title( string $admin_title, string $title ): string {
		if ( ! self::is_editor_page_request() ) {
			return $admin_title;
		}

		$post_title = self::resolve_editor_post_title();
		$suffix     = self::extract_admin_title_suffix( $admin_title, $title );

		/* translators: %s: post title. */
		$editor_title = sprintf( __( 'KayzArt Landing Page Editor: %s', 'kayzart-live-code-editor' ), $post_title );

		if ( '' === $suffix ) {
			return $editor_title;
		}

		return $editor_title . $suffix;
	}

	/**
	 * Check whether the current request targets the KayzArt editor page.
	 *
	 * @return bool
	 */
	private static function is_editor_page_request(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		return $screen instanceof \WP_Screen && 'admin_page_' . self::MENU_SLUG === $screen->id;
	}

	/**
	 * Resolve the post title used in the browser title.
	 *
	 * @return string
	 */
	private static function resolve_editor_post_title(): string {

		$fallback_title = __( 'Untitled', 'kayzart-live-code-editor' );
		$post_id        = self::get_valid_editor_post_id( false );
		if ( ! $post_id ) {
			return $fallback_title;
		}

		$post = get_post( $post_id );
		if ( ! $post || ! Post_Type::is_editor_enabled_post( $post ) ) {
			return $fallback_title;
		}

		$post_title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		return '' !== $post_title ? $post_title : $fallback_title;
	}

	/**
	 * Resolve and validate editor page post ID from current request.
	 *
	 * @param bool $die_on_failure Whether to abort with wp_die on validation failure.
	 * @return int
	 */
	private static function get_valid_editor_post_id( bool $die_on_failure ): int {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::EDITOR_PAGE_NONCE_ACTION ) ) {
			if ( $die_on_failure ) {
				wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
			}
			return 0;
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			if ( $die_on_failure ) {
				wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
			}
			return 0;
		}

		if ( ! Post_Type::is_editor_enabled_post( $post_id ) ) {
			if ( $die_on_failure ) {
				wp_die( esc_html__( 'This editor is only available for KayzArt posts.', 'kayzart-live-code-editor' ) );
			}
			return 0;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			if ( $die_on_failure ) {
				wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
			}
			return 0;
		}

		Post_Type::enable_for_post( $post_id );

		return $post_id;
	}

	/**
	 * Keep WordPress suffix (site name + WordPress) as-is when replacing the left title.
	 *
	 * @param string $admin_title Current admin title.
	 * @param string $title       Current admin page title (left side).
	 * @return string
	 */
	private static function extract_admin_title_suffix( string $admin_title, string $title ): string {
		if ( '' === $admin_title ) {
			return '';
		}

		if ( '' !== $title && 0 === strpos( $admin_title, $title ) ) {
			return (string) substr( $admin_title, strlen( $title ) );
		}

		foreach ( self::ADMIN_TITLE_SEPARATORS as $separator ) {
			$position = strpos( $admin_title, $separator );
			if ( false !== $position ) {
				return (string) substr( $admin_title, $position );
			}
		}

		return '';
	}
	/**
	 * Redirect from admin.php?action=kayzart to the custom editor page.
	 */
	public static function action_redirect(): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::REDIRECT_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
		}
		if ( ! Post_Type::is_editor_enabled_post( $post_id ) ) {
			wp_die( esc_html__( 'This editor is only available for KayzArt posts.', 'kayzart-live-code-editor' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
		Post_Type::enable_for_post( $post_id );
		wp_safe_redirect( Post_Type::get_editor_url( $post_id ) );
		exit;
	}

	/**
	 * Redirect new KayzArt posts directly to the custom editor.
	 */
	public static function maybe_redirect_new_post(): void {
	}

	/**
	 * Resolve current post type on post-new.php from screen context.
	 *
	 * @return string
	 */
	private static function resolve_new_post_screen_post_type(): string {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen instanceof \WP_Screen && is_string( $screen->post_type ) && '' !== $screen->post_type ) {
				return sanitize_key( $screen->post_type );
			}
		}

		$typenow = isset( $GLOBALS['typenow'] ) ? sanitize_key( (string) $GLOBALS['typenow'] ) : '';
		return '' !== $typenow ? $typenow : 'post';
	}

	/**
	 * Create a new KayzArt CPT draft from legacy action URLs.
	 */
	public static function action_create_new_post(): void {
		self::verify_action_nonce( self::NEW_POST_NONCE_ACTION );
		self::create_new_landing_page_post( Post_Type::POST_TYPE );
	}

	/**
	 * Create a new post marked for KayzArt editing.
	 */
	public static function action_create_new_page(): void {
		self::verify_action_nonce( self::NEW_PAGE_NONCE_ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above via verify_action_nonce().
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : Post_Type::PAGE_TYPE;
		self::create_new_landing_page_post( $post_type );
	}

	/**
	 * Verify an admin action nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function verify_action_nonce( string $nonce_action ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
	}

	/**
	 * Create a new KayzArt-managed draft.
	 *
	 * @param string $post_type Post type.
	 */
	private static function create_new_landing_page_post( string $post_type ): void {
		if ( ! Post_Type::is_post_type_enabled( $post_type ) ) {
			wp_die( esc_html__( 'This post type is not enabled for KayzArt.', 'kayzart-live-code-editor' ) );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => __( 'Untitled landing page', 'kayzart-live-code-editor' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		Post_Type::enable_for_post( (int) $post_id );
		update_post_meta( (int) $post_id, '_kayzart_setup_required', '1' );

		wp_safe_redirect( Post_Type::get_editor_url( (int) $post_id ) );
		exit;
	}

	/**
	 * Build nonce-protected admin action URL for opening the KayzArt editor bridge.
	 *
	 * @return string
	 */
	public static function get_action_redirect_url(): string {
		return add_query_arg(
			array(
				'action'   => 'kayzart',
				'_wpnonce' => wp_create_nonce( self::REDIRECT_NONCE_ACTION ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Replace KayzArt add-new admin links with a nonce-protected action URL.
	 *
	 * @param string $url     Generated admin URL.
	 * @param string $path    Requested admin path.
	 * @param mixed  $blog_id Site ID.
	 * @return string
	 */
	public static function filter_admin_url( string $url, string $path, $blog_id ): string {
		unset( $blog_id );
		unset( $path );
		return $url;
	}

	/**
	 * Update admin bar "New KayzArt" link to use nonce-protected action URL.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public static function override_admin_bar_new_link( \WP_Admin_Bar $admin_bar ): void {
		unset( $admin_bar );
	}

	/**
	 * Build nonce-protected URL for creating a new KayzArt draft.
	 *
	 * @return string
	 */
	private static function get_new_post_action_url(): string {

		return add_query_arg(
			array(
				'action'    => self::NEW_POST_ACTION,
				'post_type' => Post_Type::POST_TYPE,
				'_wpnonce'  => wp_create_nonce( self::NEW_POST_NONCE_ACTION ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build nonce-protected URL for creating a new KayzArt-managed WordPress page.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_new_page_action_url( string $post_type = Post_Type::PAGE_TYPE ): string {

		return add_query_arg(
			array(
				'action'    => self::NEW_PAGE_ACTION,
				'post_type' => sanitize_key( $post_type ),
				'_wpnonce'  => wp_create_nonce( self::NEW_PAGE_NONCE_ACTION ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the landing page settings page URL.
	 *
	 * @param string $tab Optional tab ID.
	 * @return string
	 */
	public static function get_settings_url( string $tab = '' ): string {
		$args = array(
			'page' => self::SETTINGS_SLUG,
		);
		if ( '' !== $tab ) {
			$args['tab'] = sanitize_key( $tab );
		}

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Resolve the admin menu parent slug for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	private static function get_post_type_menu_parent_slug( string $post_type ): string {
		return 'post' === $post_type ? 'edit.php' : 'edit.php?post_type=' . sanitize_key( $post_type );
	}

	/**
	 * Remove legacy KayzArt CPT creation/settings submenu entries.
	 */
	public static function override_new_submenu_link(): void {
	}

	/**
	 * Display a migration notice on the legacy KayzArt CPT list screen.
	 */
	public static function render_legacy_cpt_notice(): void {
	}
	/**
	 * Register the hidden admin page entry.
	 */
	public static function register_menu(): void {

		// Hidden admin page (no menu entry). Accessed via redirects only.
		// Use admin.php as a virtual parent so WordPress can resolve a non-null page title.
		add_submenu_page(
			self::HIDDEN_PARENT_SLUG,
			__( 'KayzArt', 'kayzart-live-code-editor' ),
			__( 'KayzArt', 'kayzart-live-code-editor' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		foreach ( Post_Type::get_enabled_post_types() as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || empty( $post_type_object->cap->create_posts ) ) {
				continue;
			}
			if ( ! current_user_can( $post_type_object->cap->create_posts ) ) {
				continue;
			}

			add_submenu_page(
				self::get_post_type_menu_parent_slug( $post_type ),
				__( 'Add landing page', 'kayzart-live-code-editor' ),
				__( 'Add landing page', 'kayzart-live-code-editor' ),
				(string) $post_type_object->cap->create_posts,
				self::get_new_page_action_url( $post_type ),
				'',
				11
			);
		}

		add_options_page(
			__( 'Landing page settings', 'kayzart-live-code-editor' ),
			__( 'Landing page settings', 'kayzart-live-code-editor' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings for the plugin.
	 */
	public static function register_settings(): void {

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_POST_SLUG,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_post_slug' ),
				'default'           => Post_Type::SLUG,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DEFAULT_TEMPLATE_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_default_template_mode' ),
				'default'           => 'standalone',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_ENABLED_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled_post_types' ),
				'default'           => array( Post_Type::PAGE_TYPE ),
			)
		);

		if ( self::should_show_post_slug_settings() ) {
			add_settings_section(
				'kayzart_permalink',
				__( 'Permalink', 'kayzart-live-code-editor' ),
				array( __CLASS__, 'render_permalink_section' ),
				self::SETTINGS_SLUG
			);
		}

		add_settings_section(
			'kayzart_template_mode',
			__( 'Page template', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_template_mode_section' ),
			self::SETTINGS_SLUG
		);

		if ( self::should_show_post_slug_settings() ) {
			add_settings_field(
				self::OPTION_POST_SLUG,
				__( 'KayzArt slug', 'kayzart-live-code-editor' ),
				array( __CLASS__, 'render_post_slug_field' ),
				self::SETTINGS_SLUG,
				'kayzart_permalink'
			);
		}

		add_settings_field(
			self::OPTION_DEFAULT_TEMPLATE_MODE,
			__( 'Default template mode', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_default_template_mode_field' ),
			self::SETTINGS_SLUG,
			'kayzart_template_mode'
		);

		add_settings_section(
			'kayzart_post_types',
			__( 'Post types', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_post_types_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			self::OPTION_ENABLED_POST_TYPES,
			__( 'Enabled post types', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_enabled_post_types_field' ),
			self::SETTINGS_SLUG,
			'kayzart_post_types'
		);
	}

	/**
	 * Sanitize post slug value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_slug( $value ): string {
		$slug = sanitize_title( (string) $value );
		return '' !== $slug ? $slug : Post_Type::SLUG;
	}

	/**
	 * Check whether legacy slug settings should be visible.
	 *
	 * @return bool
	 */
	public static function should_show_post_slug_settings(): bool {
		$slug = self::sanitize_post_slug( get_option( self::OPTION_POST_SLUG, Post_Type::SLUG ) );
		return Post_Type::SLUG !== $slug;
	}

	/**
	 * Sanitize default template mode value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_default_template_mode( $value ): string {

		$template_mode = is_string( $value ) ? sanitize_key( $value ) : '';
		$valid         = array( 'standalone', 'theme' );
		return in_array( $template_mode, $valid, true ) ? $template_mode : 'standalone';
	}

	/**
	 * Sanitize enabled post type values.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int,string>
	 */
	public static function sanitize_enabled_post_types( $value ): array {
		return Post_Type::sanitize_enabled_post_types( $value );
	}

	/**
	 * Flush rewrite rules when the post slug changes.
	 *
	 * @param string $old_value Old value.
	 * @param string $new_value New value.
	 */
	public static function handle_post_slug_update( $old_value, $new_value ): void {
		if ( (string) $old_value !== (string) $new_value ) {
			update_option( self::OPTION_FLUSH_REWRITE, '1' );
		}
	}

	/**
	 * Flush rewrite rules when the post slug is added for the first time.
	 *
	 * @param string $option Option name.
	 * @param string $value Option value.
	 */
	public static function handle_post_slug_add( $option, $value ): void {
		if ( '' !== (string) $value ) {
			update_option( self::OPTION_FLUSH_REWRITE, '1' );
		}
	}

	/**
	 * Flush rewrite rules after the post type is registered.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		$should_flush = get_option( self::OPTION_FLUSH_REWRITE, '0' );
		if ( '1' !== $should_flush ) {
			return;
		}

		flush_rewrite_rules( false );
		delete_option( self::OPTION_FLUSH_REWRITE );
	}

	/**
	 * Render permalink section description.
	 */
	public static function render_permalink_section(): void {
		echo '<p>' . esc_html__( 'Change the URL slug for KayzArt posts. Existing URLs will change after saving.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render page template section description.
	 */
	public static function render_template_mode_section(): void {

		echo '<p>' . esc_html__( 'Choose the default page template mode used by KayzArt previews.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render post type section description.
	 */
	public static function render_post_types_section(): void {
		echo '<p>' . esc_html__( 'Choose which post types can use the KayzArt landing page editor.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render post slug input field.
	 */
	public static function render_post_slug_field(): void {
		$value = get_option( self::OPTION_POST_SLUG, Post_Type::SLUG );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_POST_SLUG ) . '" value="' . esc_attr( $value ) . '" />';
		echo '<p class="description">' . esc_html__( 'Allowed: lowercase letters, numbers, and hyphens. Default: kayzart.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render default template mode select field.
	 */
	public static function render_default_template_mode_field(): void {

		$value          = get_option( self::OPTION_DEFAULT_TEMPLATE_MODE, 'standalone' );
		$value          = self::sanitize_default_template_mode( $value );
		$template_modes = array(
			'standalone' => __( 'Standalone', 'kayzart-live-code-editor' ),
			'theme'      => __( 'Theme', 'kayzart-live-code-editor' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_DEFAULT_TEMPLATE_MODE ) . '">';
		foreach ( $template_modes as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Applies when template mode is set to Use admin default.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render enabled post type checkboxes.
	 */
	public static function render_enabled_post_types_field(): void {
		$enabled    = Post_Type::get_enabled_post_types();
		$post_types = Post_Type::get_selectable_post_types();

		echo '<input type="hidden" name="' . esc_attr( self::OPTION_ENABLED_POST_TYPES ) . '[]" value="" />';
		foreach ( $post_types as $name => $post_type ) {
			$label = $post_type->labels->name ?? $name;
			echo '<label style="display:block;margin:0 0 6px;">';
			echo '<input type="checkbox" name="' . esc_attr( self::OPTION_ENABLED_POST_TYPES ) . '[]" value="' . esc_attr( $name ) . '" ' . checked( in_array( $name, $enabled, true ), true, false ) . ' />';
			echo ' ' . esc_html( (string) $label ) . ' <code>' . esc_html( $name ) . '</code>';
			echo '</label>';
		}

		echo '<p class="description">' . esc_html__( 'Existing posts are not converted until they are opened in the KayzArt editor or created with Add landing page.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render the admin editor container.
	 */
	public static function render_page(): void {
		$post_id = self::get_valid_editor_post_id( true );

		echo '<div id="kayzart-app" data-post-id="' . esc_attr( $post_id ) . '"></div>';
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs       = self::get_settings_tabs();
		$active_tab = self::get_active_settings_tab( $tabs );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Landing page settings', 'kayzart-live-code-editor' ) . '</h1>';
		self::render_settings_tabs_nav( $tabs, $active_tab );
		if ( 'basic' === $active_tab ) {
			echo '<form action="options.php" method="post">';
			settings_fields( self::SETTINGS_GROUP );
			do_settings_sections( self::SETTINGS_SLUG );
			submit_button();
			echo '</form>';
		} else {
			/**
			 * Render a custom landing page settings tab.
			 *
			 * The dynamic portion of the hook name is the tab ID.
			 */
			do_action( 'kayzart_render_settings_tab_' . $active_tab );
		}
		echo '</div>';
	}

	/**
	 * Resolve registered landing page settings tabs.
	 *
	 * @return array<string,string>
	 */
	private static function get_settings_tabs(): array {
		$tabs = array(
			'basic' => __( '基本設定', 'kayzart-live-code-editor' ),
		);

		/**
		 * Filter landing page settings tabs.
		 *
		 * @param array<string,string> $tabs Tab ID to label map.
		 */
		$tabs = apply_filters( 'kayzart_settings_tabs', $tabs );
		if ( ! is_array( $tabs ) || empty( $tabs ) ) {
			return array(
				'basic' => __( '基本設定', 'kayzart-live-code-editor' ),
			);
		}

		$normalized = array();
		foreach ( $tabs as $id => $label ) {
			$id = sanitize_key( (string) $id );
			if ( '' === $id || ! is_string( $label ) || '' === $label ) {
				continue;
			}
			$normalized[ $id ] = $label;
		}

		if ( empty( $normalized ) ) {
			$normalized['basic'] = __( '基本設定', 'kayzart-live-code-editor' );
		}

		return $normalized;
	}

	/**
	 * Resolve the active landing page settings tab.
	 *
	 * @param array<string,string> $tabs Registered tabs.
	 * @return string
	 */
	private static function get_active_settings_tab( array $tabs ): string {
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $tabs[ $active ] ) ? $active : 'basic';
	}

	/**
	 * Render landing page settings tab navigation.
	 *
	 * @param array<string,string> $tabs       Registered tabs.
	 * @param string               $active_tab Active tab ID.
	 */
	private static function render_settings_tabs_nav( array $tabs, string $active_tab ): void {
		if ( count( $tabs ) < 2 ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			$class = 'nav-tab';
			if ( $active_tab === $id ) {
				$class .= ' nav-tab-active';
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( self::get_settings_url( 'basic' === $id ? '' : $id ) ) . '">';
			echo esc_html( $label );
			echo '</a>';
		}
		echo '</nav>';
	}
	/**
	 * Enqueue admin assets for the KayzArt editor.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( 'edit.php' === $hook_suffix ) {
			self::maybe_enqueue_post_type_list_assets();
			return;
		}

		// Only load on our hidden page.
		if ( 'admin_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$post_id = self::get_valid_editor_post_id( false );
		if ( ! $post_id ) {
			return;
		}
		$admin_script_version = self::resolve_asset_version( KAYZART_PATH . 'assets/dist/main.js' );
		$admin_style_version  = self::resolve_asset_version( KAYZART_PATH . 'assets/dist/style.css' );

		// Admin app bundle (Vite output).
		wp_register_script(
			'kayzart-admin',
			KAYZART_URL . 'assets/dist/main.js',
			array( 'wp-api-fetch', 'wp-element', 'wp-i18n', 'wp-data', 'wp-components', 'wp-notices' ),
			$admin_script_version,
			true
		);
		wp_register_style(
			'kayzart-admin',
			KAYZART_URL . 'assets/dist/style.css',
			array(),
			$admin_style_version
		);
		wp_enqueue_script( 'kayzart-admin' );
		wp_enqueue_style( 'kayzart-admin' );
		wp_enqueue_style( 'wp-components' );
		wp_add_inline_style(
			'kayzart-admin',
			'body.admin_page_kayzart #wpbody-content > .notice,'
			. 'body.admin_page_kayzart #wpbody-content > .update-nag,'
			. 'body.admin_page_kayzart #wpbody-content > .updated,'
			. 'body.admin_page_kayzart #wpbody-content > .error{display:none !important;}'
		);
		wp_enqueue_media();

		wp_set_script_translations(
			'kayzart-admin',
			'kayzart-live-code-editor',
			KAYZART_PATH . 'languages'
		);

		// Inject initial data for the admin app.
		$post       = $post_id ? get_post( $post_id ) : null;
		$html       = $post ? (string) $post->post_content : '';
		$css        = $post_id ? (string) get_post_meta( $post_id, '_kayzart_css', true ) : '';
		$js         = $post_id ? (string) get_post_meta( $post_id, '_kayzart_js', true ) : '';
		$js_mode    = self::normalize_js_mode( $post_id ? get_post_meta( $post_id, '_kayzart_js_mode', true ) : '' );
		$back_url   = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE );
		$list_url   = self::get_editor_list_url( $post );
		$list_label = self::get_editor_list_label( $post );

		$preview_token = $post_id ? wp_create_nonce( 'kayzart_preview_' . $post_id ) : '';
		$permalink     = $post_id ? get_permalink( $post_id ) : '';
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			$permalink = home_url( '/' );
		}
		$preview_url        = add_query_arg( 'preview', 'true', $permalink );
		$iframe_preview_url = $post_id
			? add_query_arg(
				array(
					'kayzart_preview' => 1,
					'post_id'         => $post_id,
					'token'           => $preview_token,
				),
				$permalink
			)
			: $preview_url;

		$data = array(
			'post_id'              => $post_id,
			'initialHtml'          => $html,
			'initialCss'           => $css,
			'initialJs'            => $js,
			'initialJsMode'        => $js_mode,
			'canEditJs'            => current_user_can( 'unfiltered_html' ),
			'previewUrl'           => $preview_url,
			'iframePreviewUrl'     => $iframe_preview_url,
			'restUrl'              => rest_url( 'kayzart/v1/save' ),
			'restCompileUrl'       => rest_url( 'kayzart/v1/compile-tailwind' ),
			'setupRestUrl'         => rest_url( 'kayzart/v1/setup' ),
			'backUrl'              => $back_url,
			'listUrl'              => $list_url,
			'listLabel'            => $list_label,
			'settingsRestUrl'      => rest_url( 'kayzart/v1/settings' ),
			'settingsData'         => Rest::build_settings_payload( $post_id ),
			'tailwindEnabled'      => (bool) get_post_meta( $post_id, '_kayzart_tailwind', true ),
			'setupRequired'        => get_post_meta( $post_id, '_kayzart_setup_required', true ) === '1',
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
			'adminTitleSeparators' => array_values( self::ADMIN_TITLE_SEPARATORS ),
		);
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			$json = '{}';
		}

		wp_add_inline_script(
			'kayzart-admin',
			'window.KAYZART = ' . $json . ';',
			'before'
		);

		/**
		 * Allow addon plugins to enqueue editor-specific assets.
		*
	 * @param array $context Editor asset context.
	 */
		do_action(
			'kayzart_editor_enqueue_assets',
			array(
				'post_id'             => $post_id,
				'hook_suffix'         => $hook_suffix,
				'admin_script_handle' => 'kayzart-admin',
				'admin_style_handle'  => 'kayzart-admin',
			)
		);
	}
	/**
	 * Resolve asset version with filemtime fallback.
	 *
	 * @param string $path Absolute file path.
	 * @return string
	 */
	private static function resolve_asset_version( string $path ): string {

		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		if ( false === $mtime ) {
			return KAYZART_VERSION;
		}
		return (string) $mtime;
	}

	/**
	 * Enqueue list action button assets on enabled post type list screens.
	 */
	private static function maybe_enqueue_post_type_list_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen || ! is_string( $screen->post_type ) || '' === $screen->post_type ) {
			return;
		}

		$post_type = sanitize_key( $screen->post_type );
		if ( ! Post_Type::is_post_type_enabled( $post_type ) ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			return;
		}

		$handle = 'kayzart-post-type-list';
		wp_register_script(
			$handle,
			KAYZART_URL . 'assets/admin/post-type-list.js',
			array( 'wp-i18n', 'wp-dom-ready' ),
			self::resolve_asset_version( KAYZART_PATH . 'assets/admin/post-type-list.js' ),
			true
		);

		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.KAYZART_POST_TYPE_LIST = ' . wp_json_encode(
				array(
					'createUrl' => self::get_new_page_action_url( $post_type ),
				)
			) . ';',
			'before'
		);
		wp_set_script_translations(
			$handle,
			'kayzart-live-code-editor',
			KAYZART_PATH . 'languages'
		);
	}

	/**
	 * Resolve the list URL to return to from the editor.
	 *
	 * @param \WP_Post|null $post Current editor post.
	 * @return string
	 */
	private static function get_editor_list_url( ?\WP_Post $post ): string {
		if ( ! $post ) {
			return admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE );
		}

		return admin_url( self::get_post_type_menu_parent_slug( $post->post_type ) );
	}

	/**
	 * Resolve the list label to return to from the editor.
	 *
	 * @param \WP_Post|null $post Current editor post.
	 * @return string
	 */
	private static function get_editor_list_label( ?\WP_Post $post ): string {
		if ( ! $post ) {
			return __( 'Posts', 'kayzart-live-code-editor' );
		}

		$post_type = get_post_type_object( $post->post_type );
		if ( $post_type && ! empty( $post_type->labels->name ) ) {
			return (string) $post_type->labels->name;
		}

		return __( 'Posts', 'kayzart-live-code-editor' );
	}

	/**
	 * Normalize JavaScript execution mode.
	 *
	 * @param mixed $value Raw mode value.
	 * @return string
	 */
	private static function normalize_js_mode( $value ): string {
		$mode = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( 'module' === $mode ) {
			return 'module';
		}
		if ( 'classic' === $mode || 'auto' === $mode ) {
			return 'classic';
		}
		return 'classic';
	}
}
