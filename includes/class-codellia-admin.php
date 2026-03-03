<?php
/**
 * Admin screen integration for Codellia.
 *
 * @package Codellia
 */

namespace Codellia;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin UI routes and assets.
 */
class Admin {

	const MENU_SLUG                    = 'codellia';
	const SETTINGS_SLUG                = 'codellia-settings';
	const SETTINGS_GROUP               = 'codellia_settings';
	const NEW_POST_ACTION              = 'codellia_new';
	const NEW_POST_NONCE_ACTION        = 'codellia_new_post';
	const OPTION_POST_SLUG             = 'codellia_post_slug';
	const OPTION_DEFAULT_TEMPLATE_MODE = 'codellia_default_template_mode';
	const OPTION_FLUSH_REWRITE         = 'codellia_flush_rewrite';
	const OPTION_DELETE_ON_UNINSTALL   = 'codellia_delete_on_uninstall';
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
		add_action( 'admin_action_codellia', array( __CLASS__, 'action_redirect' ) ); // admin.php?action=codellia.
		add_action( 'admin_action_' . self::NEW_POST_ACTION, array( __CLASS__, 'action_create_new_post' ) );
		add_action( 'load-post-new.php', array( __CLASS__, 'maybe_redirect_new_post' ) );
		add_filter( 'admin_url', array( __CLASS__, 'filter_admin_url' ), 10, 3 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'override_admin_bar_new_link' ), 100 );
		add_action( 'update_option_' . self::OPTION_POST_SLUG, array( __CLASS__, 'handle_post_slug_update' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION_POST_SLUG, array( __CLASS__, 'handle_post_slug_add' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 20 );
	}

	/**
	 * Suppress all admin notices on the full-screen Codellia editor page.
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
	 * Build the browser title for the Codellia editor screen.
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
		$editor_title = sprintf( __( 'Codellia Editor: %s', 'codellia' ), $post_title );

		if ( '' === $suffix ) {
			return $editor_title;
		}

		return $editor_title . $suffix;
	}

	/**
	 * Check whether the current request targets the Codellia editor page.
	 *
	 * @return bool
	 */
	private static function is_editor_page_request(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::MENU_SLUG === $page;
	}

	/**
	 * Resolve the post title used in the browser title.
	 *
	 * @return string
	 */
	private static function resolve_editor_post_title(): string {
		$fallback_title = __( 'Untitled', 'codellia' );
		$post_id        = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			return $fallback_title;
		}

		$post = get_post( $post_id );
		if ( ! $post || Post_Type::POST_TYPE !== $post->post_type ) {
			return $fallback_title;
		}

		$post_title = trim( wp_strip_all_tags( (string) $post->post_title ) );
		return '' !== $post_title ? $post_title : $fallback_title;
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

		if ( '' !== $title && str_starts_with( $admin_title, $title ) ) {
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
	 * Redirect from admin.php?action=codellia to the custom editor page.
	 */
	public static function action_redirect(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'codellia' ) );
		}
		if ( ! Post_Type::is_codellia_post( $post_id ) ) {
			wp_die( esc_html__( 'This editor is only available for Codellia posts.', 'codellia' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}
		wp_safe_redirect( Post_Type::get_editor_url( $post_id ) );
		exit;
	}

	/**
	 * Redirect new Codellia posts directly to the custom editor.
	 */
	public static function maybe_redirect_new_post(): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Post_Type::POST_TYPE !== $post_type ) {
			return;
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $nonce && ! wp_verify_nonce( $nonce, self::NEW_POST_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		wp_safe_redirect( self::get_new_post_action_url() );
		exit;
	}

	/**
	 * Create a new Codellia draft post from a nonce-protected admin action.
	 */
	public static function action_create_new_post(): void {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : Post_Type::POST_TYPE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Post_Type::POST_TYPE !== $post_type ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, self::NEW_POST_NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => __( 'Untitled Codellia Page', 'codellia' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		wp_safe_redirect( Post_Type::get_editor_url( (int) $post_id ) );
		exit;
	}

	/**
	 * Replace Codellia add-new admin links with a nonce-protected action URL.
	 *
	 * @param string $url     Generated admin URL.
	 * @param string $path    Requested admin path.
	 * @param mixed  $blog_id Site ID.
	 * @return string
	 */
	public static function filter_admin_url( string $url, string $path, $blog_id ): string {
		unset( $blog_id );

		if ( '' === $path ) {
			return $url;
		}

		$normalized_path = str_replace( '&amp;', '&', $path );
		$parts           = wp_parse_url( $normalized_path );
		$route_path      = isset( $parts['path'] ) ? ltrim( (string) $parts['path'], '/' ) : '';
		if ( 'post-new.php' !== basename( $route_path ) ) {
			return $url;
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}
		$post_type = isset( $query['post_type'] ) ? sanitize_key( (string) $query['post_type'] ) : 'post';
		if ( Post_Type::POST_TYPE !== $post_type ) {
			return $url;
		}

		return self::get_new_post_action_url();
	}

	/**
	 * Update admin bar "New Codellia" link to use nonce-protected action URL.
	 *
	 * @param \WP_Admin_Bar $admin_bar Admin bar instance.
	 */
	public static function override_admin_bar_new_link( \WP_Admin_Bar $admin_bar ): void {
		$node = $admin_bar->get_node( 'new-' . Post_Type::POST_TYPE );
		if ( ! $node ) {
			return;
		}

		$node->href = self::get_new_post_action_url();
		$admin_bar->add_node( $node );
	}

	/**
	 * Build nonce-protected URL for creating a new Codellia draft.
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
	 * Register the hidden admin page entry.
	 */
	public static function register_menu(): void {

		// Hidden admin page (no menu entry). Accessed via redirects only.
		add_submenu_page(
			null,
			__( 'Codellia', 'codellia' ),
			__( 'Codellia', 'codellia' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			'edit.php?post_type=' . Post_Type::POST_TYPE,
			__( 'Settings', 'codellia' ),
			__( 'Settings', 'codellia' ),
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
				'default'           => 'theme',
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DELETE_ON_UNINSTALL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_delete_on_uninstall' ),
				'default'           => '0',
			)
		);

		add_settings_section(
			'codellia_permalink',
			__( 'Permalink', 'codellia' ),
			array( __CLASS__, 'render_permalink_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_section(
			'codellia_template_mode',
			__( 'Page template', 'codellia' ),
			array( __CLASS__, 'render_template_mode_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			self::OPTION_POST_SLUG,
			__( 'Codellia slug', 'codellia' ),
			array( __CLASS__, 'render_post_slug_field' ),
			self::SETTINGS_SLUG,
			'codellia_permalink'
		);

		add_settings_field(
			self::OPTION_DEFAULT_TEMPLATE_MODE,
			__( 'Default template mode', 'codellia' ),
			array( __CLASS__, 'render_default_template_mode_field' ),
			self::SETTINGS_SLUG,
			'codellia_template_mode'
		);

		add_settings_section(
			'codellia_cleanup',
			__( 'Cleanup', 'codellia' ),
			array( __CLASS__, 'render_cleanup_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			self::OPTION_DELETE_ON_UNINSTALL,
			__( 'Delete data on uninstall', 'codellia' ),
			array( __CLASS__, 'render_delete_on_uninstall_field' ),
			self::SETTINGS_SLUG,
			'codellia_cleanup'
		);
	}

	/**
	 * Sanitize delete-on-uninstall value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_delete_on_uninstall( $value ): string {

		return '1' === $value ? '1' : '0';
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
	 * Sanitize default template mode value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_default_template_mode( $value ): string {
		$template_mode = is_string( $value ) ? sanitize_key( $value ) : '';
		$valid         = array( 'standalone', 'frame', 'theme' );
		return in_array( $template_mode, $valid, true ) ? $template_mode : 'theme';
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
		echo '<p>' . esc_html__( 'Change the URL slug for Codellia posts. Existing URLs will change after saving.', 'codellia' ) . '</p>';
	}

	/**
	 * Render page template section description.
	 */
	public static function render_template_mode_section(): void {
		echo '<p>' . esc_html__( 'Choose the default page template mode used by Codellia previews.', 'codellia' ) . '</p>';
	}

	/**
	 * Render post slug input field.
	 */
	public static function render_post_slug_field(): void {
		$value = get_option( self::OPTION_POST_SLUG, Post_Type::SLUG );
		echo '<input type="text" class="regular-text" name="' . esc_attr( self::OPTION_POST_SLUG ) . '" value="' . esc_attr( $value ) . '" />';
		echo '<p class="description">' . esc_html__( 'Allowed: lowercase letters, numbers, and hyphens. Default: codellia.', 'codellia' ) . '</p>';
	}

	/**
	 * Render default template mode select field.
	 */
	public static function render_default_template_mode_field(): void {
		$value          = get_option( self::OPTION_DEFAULT_TEMPLATE_MODE, 'theme' );
		$value          = self::sanitize_default_template_mode( $value );
		$template_modes = array(
			'standalone' => __( 'Standalone', 'codellia' ),
			'frame'      => __( 'Frame', 'codellia' ),
			'theme'      => __( 'Theme', 'codellia' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_DEFAULT_TEMPLATE_MODE ) . '">';
		foreach ( $template_modes as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Applies when template mode is set to Use admin default.', 'codellia' ) . '</p>';
	}

	/**
	 * Render cleanup section description.
	 */
	public static function render_cleanup_section(): void {

		echo '<p>' . esc_html__( 'Choose whether Codellia posts should be deleted when the plugin is uninstalled.', 'codellia' ) . '</p>';
	}

	/**
	 * Render delete-on-uninstall checkbox field.
	 */
	public static function render_delete_on_uninstall_field(): void {

		$value = get_option( self::OPTION_DELETE_ON_UNINSTALL, '0' );
		echo '<label>';
		echo '<input type="checkbox" name="' . esc_attr( self::OPTION_DELETE_ON_UNINSTALL ) . '" value="1" ' . checked( '1', $value, false ) . ' />';
		echo ' ' . esc_html__( 'Delete all Codellia posts on uninstall (imported media is kept).', 'codellia' );
		echo '</label>';
	}

	/**
	 * Render the admin editor container.
	 */
	public static function render_page(): void {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Codellia', 'codellia' ) . '</h1><p>' . esc_html__( 'post_id is required.', 'codellia' ) . '</p></div>';
			return;
		}
		if ( ! Post_Type::is_codellia_post( $post_id ) ) {
			wp_die( esc_html__( 'This editor is only available for Codellia posts.', 'codellia' ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codellia' ) );
		}

		echo '<div id="codellia-app" data-post-id="' . esc_attr( $post_id ) . '"></div>';
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Codellia Editor Settings', 'codellia' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::SETTINGS_SLUG );
		submit_button();
		echo '</form>';
		echo '</div>';
	}
	/**
	 * Enqueue admin assets for the Codellia editor.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		// Only load on our hidden page.
		if ( 'admin_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! Post_Type::is_codellia_post( $post_id ) ) {
			return;
		}
		$admin_script_version = self::resolve_asset_version( CODELLIA_PATH . 'assets/dist/main.js' );
		$admin_style_version  = self::resolve_asset_version( CODELLIA_PATH . 'assets/dist/style.css' );

		// Monaco AMD loader lives in assets/monaco/vs/loader.js.
		wp_register_script(
			'codellia-monaco-loader',
			CODELLIA_URL . 'assets/monaco/vs/loader.js',
			array(),
			CODELLIA_VERSION,
			true
		);
		wp_add_inline_script(
			'codellia-monaco-loader',
			'if (typeof window.define === "function" && window.define.amd) { window.__codelliaDefineAmd = window.define.amd; window.define.amd = undefined; }',
			'after'
		);

		// Admin app bundle (Vite output).
		wp_register_script(
			'codellia-admin',
			CODELLIA_URL . 'assets/dist/main.js',
			array( 'codellia-monaco-loader', 'wp-api-fetch', 'wp-element', 'wp-i18n', 'wp-data', 'wp-components', 'wp-notices' ),
			$admin_script_version,
			true
		);

		wp_register_style(
			'codellia-admin',
			CODELLIA_URL . 'assets/dist/style.css',
			array(),
			$admin_style_version
		);
		wp_enqueue_script( 'codellia-admin' );
		wp_enqueue_style( 'codellia-admin' );
		wp_enqueue_style( 'wp-components' );
		wp_add_inline_style(
			'codellia-admin',
			'body.admin_page_codellia #wpbody-content > .notice,'
			. 'body.admin_page_codellia #wpbody-content > .update-nag,'
			. 'body.admin_page_codellia #wpbody-content > .updated,'
			. 'body.admin_page_codellia #wpbody-content > .error{display:none !important;}'
		);
		wp_enqueue_media();

		wp_set_script_translations(
			'codellia-admin',
			'codellia',
			CODELLIA_PATH . 'languages'
		);

		// Inject initial data for the admin app.
		$post     = $post_id ? get_post( $post_id ) : null;
		$html     = $post ? (string) $post->post_content : '';
		$css      = $post_id ? (string) get_post_meta( $post_id, '_codellia_css', true ) : '';
		$js       = $post_id ? (string) get_post_meta( $post_id, '_codellia_js', true ) : '';
		$back_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE );
		$list_url = admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE );

		$preview_token      = $post_id ? wp_create_nonce( 'codellia_preview_' . $post_id ) : '';
		$preview_url        = $post_id ? add_query_arg( 'preview', 'true', get_permalink( $post_id ) ) : home_url( '/' );
		$iframe_preview_url = $post_id
			? add_query_arg(
				array(
					'codellia_preview' => 1,
					'post_id'          => $post_id,
					'token'            => $preview_token,
				),
				get_permalink( $post_id )
			)
			: $preview_url;

		$data = array(
			'post_id'              => $post_id,
			'initialHtml'          => $html,
			'initialCss'           => $css,
			'initialJs'            => $js,
			'canEditJs'            => current_user_can( 'unfiltered_html' ),
			'previewUrl'           => $preview_url,
			'iframePreviewUrl'     => $iframe_preview_url,
			'monacoVsPath'         => CODELLIA_URL . 'assets/monaco/vs',
			'restUrl'              => rest_url( 'codellia/v1/save' ),
			'restCompileUrl'       => rest_url( 'codellia/v1/compile-tailwind' ),
			'renderShortcodesUrl'  => rest_url( 'codellia/v1/render-shortcodes' ),
			'setupRestUrl'         => rest_url( 'codellia/v1/setup' ),
			'importRestUrl'        => rest_url( 'codellia/v1/import' ),
			'backUrl'              => $back_url,
			'listUrl'              => $list_url,
			'settingsRestUrl'      => rest_url( 'codellia/v1/settings' ),
			'settingsData'         => Rest::build_settings_payload( $post_id ),
			'tailwindEnabled'      => (bool) get_post_meta( $post_id, '_codellia_tailwind', true ),
			'setupRequired'        => get_post_meta( $post_id, '_codellia_setup_required', true ) === '1',
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
			'adminTitleSeparators' => array_values( self::ADMIN_TITLE_SEPARATORS ),
		);

		wp_add_inline_script(
			'codellia-admin',
			'window.CODELLIA = ' . wp_json_encode(
				$data,
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			) . ';',
			'before'
		);

		/**
		 * Allow addon plugins to enqueue editor-specific assets.
		*
	 * @param array $context Editor asset context.
	 */
		do_action(
			'codellia_editor_enqueue_assets',
			array(
				'post_id'             => $post_id,
				'hook_suffix'         => $hook_suffix,
				'admin_script_handle' => 'codellia-admin',
				'admin_style_handle'  => 'codellia-admin',
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
			return CODELLIA_VERSION;
		}
		return (string) $mtime;
	}
}
