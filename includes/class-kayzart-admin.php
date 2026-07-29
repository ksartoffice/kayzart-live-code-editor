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
	const NEW_SLUG                     = 'kayzart-new';
	const NEW_TYPE_PARAM               = 'kayzart_post_type';
	const CONVERT_SLUG                 = 'kayzart-convert';
	const SETTINGS_SLUG                = 'kayzart-settings';
	const SETTINGS_GROUP               = 'kayzart_settings';
	const NEW_POST_ACTION              = 'kayzart_new';
	const NEW_POST_NONCE_ACTION        = 'kayzart_new_post';
	const NEW_PAGE_ACTION              = 'kayzart_new_page';
	const NEW_PAGE_NONCE_ACTION        = 'kayzart_new_page';
	const CONVERT_POST_ACTION          = 'kayzart_convert';
	const CONVERT_POST_NONCE_ACTION    = 'kayzart_convert_post';
	const DUPLICATE_POST_ACTION        = 'kayzart_duplicate';
	const DUPLICATE_POST_NONCE_ACTION  = 'kayzart_duplicate_post';
	const REDIRECT_NONCE_ACTION        = 'kayzart_redirect';
	const EDITOR_PAGE_NONCE_ACTION     = 'kayzart_editor_page';
	const OPTION_POST_SLUG             = 'kayzart_post_slug';
	const OPTION_ENABLED_POST_TYPES    = 'kayzart_enabled_post_types';
	const OPTION_DEFAULT_TEMPLATE_MODE = 'kayzart_default_template_mode';
	const OPTION_DEFAULT_EDITOR_LAYOUT = 'kayzart_default_editor_layout';
	const OPTION_AI_DEFAULT_MODEL      = 'kayzart_ai_default_model';
	const INITIAL_AI_REQUEST_META_KEY  = '_kayzart_initial_ai_request';
	const INITIAL_AI_PROMPT_MAX_BYTES  = 8192;
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
		add_action( 'admin_init', array( __CLASS__, 'maybe_skip_convert_screen' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_admin_title' ), 10, 2 );
		add_action( 'current_screen', array( __CLASS__, 'maybe_suppress_editor_notices' ) );
		add_action( 'admin_action_kayzart', array( __CLASS__, 'action_redirect' ) ); // admin.php?action=kayzart.
		add_action( 'admin_action_' . self::NEW_POST_ACTION, array( __CLASS__, 'action_create_new_post' ) );
		add_action( 'admin_action_' . self::NEW_PAGE_ACTION, array( __CLASS__, 'action_create_new_page' ) );
		add_action( 'admin_action_' . self::CONVERT_POST_ACTION, array( __CLASS__, 'action_convert_existing_post' ) );
		add_action( 'admin_action_' . self::DUPLICATE_POST_ACTION, array( __CLASS__, 'action_duplicate_post' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_duplicated_notice' ) );
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
		$editor_title = sprintf( __( 'Kayzart: %s', 'kayzart-live-code-editor' ), $post_title );

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
				wp_die( esc_html__( 'This editor is only available for Kayzart posts.', 'kayzart-live-code-editor' ) );
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
			wp_die( esc_html__( 'This editor is only available for Kayzart posts.', 'kayzart-live-code-editor' ) );
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
		self::create_new_landing_page_post( Post_Type::POST_TYPE, self::read_requested_setup_mode(), self::read_requested_post_title() );
	}

	/**
	 * Create a new post marked for KayzArt editing.
	 */
	public static function action_create_new_page(): void {
		self::verify_action_nonce( self::NEW_PAGE_NONCE_ACTION );
		$post_type = sanitize_key( self::read_request_value( 'post_type' ) );
		if ( '' === $post_type ) {
			$post_type = Post_Type::PAGE_TYPE;
		}
		self::create_new_landing_page_post( $post_type, self::read_requested_setup_mode(), self::read_requested_post_title(), self::read_requested_initial_ai_prompt() );
	}

	/**
	 * Read the requested setup mode from a nonce-verified request.
	 *
	 * An empty string preserves the legacy behaviour of deferring the choice to
	 * the in-editor setup wizard.
	 *
	 * @return string 'normal', 'tailwind', or an empty string.
	 */
	private static function read_requested_setup_mode(): string {
		$mode = sanitize_key( self::read_request_value( 'mode' ) );
		return in_array( $mode, array( 'normal', 'tailwind' ), true ) ? $mode : '';
	}

	/**
	 * Read an optional post title from a nonce-verified request.
	 *
	 * @return string
	 */
	private static function read_requested_post_title(): string {
		return trim( self::read_request_value( 'post_title' ) );
	}

	/**
	 * Read an optional first AI instruction from a nonce-verified request.
	 *
	 * @return string
	 */
	private static function read_requested_initial_ai_prompt(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller verifies the form nonce.
		if ( ! isset( $_POST['initial_ai_prompt'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
		$prompt = trim( sanitize_textarea_field( wp_unslash( (string) $_POST['initial_ai_prompt'] ) ) );
		if ( strlen( $prompt ) > self::INITIAL_AI_PROMPT_MAX_BYTES ) {
			wp_die( esc_html__( 'The initial AI instruction is too large.', 'kayzart-live-code-editor' ) );
		}

		return $prompt;
	}

	/**
	 * Apply a setup mode to a post, or defer it to the in-editor wizard.
	 *
	 * Mirrors Rest_Setup::setup_mode() so both paths write identical meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    'normal', 'tailwind', or an empty string to defer.
	 */
	private static function apply_setup_mode( int $post_id, string $mode ): void {
		if ( 'normal' !== $mode && 'tailwind' !== $mode ) {
			update_post_meta( $post_id, '_kayzart_setup_required', '1' );
			return;
		}

		Css_Mode::initialize_post_mode( $post_id, $mode );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		delete_post_meta( $post_id, '_kayzart_setup_required' );
	}

	/**
	 * Convert an existing post into a KayzArt-managed landing page.
	 */
	public static function action_convert_existing_post(): void {
		self::verify_action_nonce( self::CONVERT_POST_NONCE_ACTION );

		// POST-first: the confirmation screen submits a form, legacy links use GET.
		$post_id = absint( self::read_request_value( 'post_id' ) );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
		}

		if ( ! Post_Type::is_editor_enabled_post( $post_id ) ) {
			wp_die( esc_html__( 'This editor is only available for Kayzart posts.', 'kayzart-live-code-editor' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		if ( ! Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			Post_Type::enable_for_post( $post_id );
			self::apply_setup_mode( $post_id, self::read_requested_setup_mode() );
		}

		wp_safe_redirect( Post_Type::get_editor_url( $post_id ) );
		exit;
	}

	/**
	 * Meta keys that must not be carried over when duplicating a Kayzart post.
	 *
	 * @var array<int,string>
	 */
	const DUPLICATE_META_DENYLIST = array(
		'_kayzart_setup_required',
		'_kayzart_screen',
	);

	/**
	 * Meta keys that require unfiltered_html when duplicating a Kayzart post.
	 *
	 * @var array<int,string>
	 */
	const DUPLICATE_META_UNFILTERED_HTML_KEYS = array(
		'_kayzart_custom_head',
		'_kayzart_js',
	);

	/**
	 * Duplicate an existing Kayzart post into a new draft.
	 */
	public static function action_duplicate_post(): void {
		self::verify_action_nonce( self::DUPLICATE_POST_NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above via verify_action_nonce().
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
		}

		$source = get_post( $post_id );
		if ( ! $source instanceof \WP_Post || ! Post_Type::is_editor_enabled_post( $post_id ) ) {
			wp_die( esc_html__( 'This editor is only available for Kayzart posts.', 'kayzart-live-code-editor' ) );
		}
		if ( Post_Type::POST_TYPE !== $source->post_type && ! Post_Type::is_kayzart_enabled_post( (int) $source->ID ) ) {
			wp_die( esc_html__( 'This editor is only available for Kayzart posts.', 'kayzart-live-code-editor' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		$post_type_object = get_post_type_object( $source->post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
		if ( ! current_user_can( 'unfiltered_html' ) && self::has_restricted_duplicate_meta( (int) $source->ID ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		$new_post_id = wp_insert_post(
			array(
				'post_type'    => $source->post_type,
				'post_status'  => 'draft',
				/* translators: %s: original post title. */
				'post_title'   => wp_slash( sprintf( __( '%s (copy)', 'kayzart-live-code-editor' ), $source->post_title ) ),
				'post_content' => wp_slash( $source->post_content ),
				'post_excerpt' => wp_slash( $source->post_excerpt ),
			),
			true
		);
		if ( is_wp_error( $new_post_id ) ) {
			wp_die( esc_html( $new_post_id->get_error_message() ) );
		}
		$new_post_id = (int) $new_post_id;

		self::copy_kayzart_post_data( $source, $new_post_id );

		if ( Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			Post_Type::enable_for_post( $new_post_id );
		}

		wp_safe_redirect( self::get_post_list_redirect_url( $source->post_type ) );
		exit;
	}

	/**
	 * Copy Kayzart meta, featured image, and taxonomy terms to a duplicated post.
	 *
	 * @param \WP_Post $source      Source post.
	 * @param int      $new_post_id Destination post ID.
	 */
	private static function copy_kayzart_post_data( \WP_Post $source, int $new_post_id ): void {
		$meta = get_post_meta( (int) $source->ID );
		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				if ( 0 !== strpos( (string) $key, '_kayzart_' ) ) {
					continue;
				}
				if ( in_array( $key, self::DUPLICATE_META_DENYLIST, true ) ) {
					continue;
				}
				foreach ( (array) $values as $value ) {
					update_post_meta( $new_post_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
		}

		$thumbnail_id = get_post_thumbnail_id( $source );
		if ( $thumbnail_id ) {
			update_post_meta( $new_post_id, '_thumbnail_id', $thumbnail_id );
		}

		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( (int) $source->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
				wp_set_object_terms( $new_post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Check whether duplicating a post would copy metadata that requires unfiltered_html.
	 *
	 * @param int $post_id Source post ID.
	 * @return bool
	 */
	private static function has_restricted_duplicate_meta( int $post_id ): bool {
		foreach ( self::DUPLICATE_META_UNFILTERED_HTML_KEYS as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && array() !== $value ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the admin list-screen redirect URL after duplicating a post.
	 *
	 * @param string $post_type Source post type.
	 * @return string
	 */
	private static function get_post_list_redirect_url( string $post_type ): string {
		$referer = wp_get_referer();
		$base    = $referer ? $referer : add_query_arg(
			Post_Type::PAGE_TYPE === $post_type ? array( 'post_type' => Post_Type::PAGE_TYPE ) : array( 'post_type' => $post_type ),
			admin_url( 'edit.php' )
		);

		return add_query_arg( 'kayzart_duplicated', '1', $base );
	}

	/**
	 * Render a success notice after a post has been duplicated.
	 */
	public static function maybe_render_duplicated_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag, no state change.
		if ( ! isset( $_GET['kayzart_duplicated'] ) || '1' !== $_GET['kayzart_duplicated'] ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Landing page duplicated. The copy was saved as a draft.', 'kayzart-live-code-editor' )
		);
	}

	/**
	 * Verify an admin action nonce.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	private static function verify_action_nonce( string $nonce_action ): void {
		if ( ! wp_verify_nonce( self::read_request_value( '_wpnonce' ), $nonce_action ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
	}

	/**
	 * Read a raw request value, preferring POST over GET.
	 *
	 * Legacy entry points link with GET; the create and confirmation screens
	 * submit a form with POST.
	 *
	 * @param string $key Request key.
	 * @return string Unslashed, sanitized value.
	 */
	private static function read_request_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility; this is a raw accessor.
		if ( isset( $_POST[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
			return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		if ( isset( $_GET[ $key ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
			return sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) );
		}
		return '';
	}

	/**
	 * Create a new KayzArt-managed draft.
	 *
	 * @param string $post_type Post type.
	 * @param string $mode      Setup mode, or an empty string to defer to the editor wizard.
	 * @param string $title     Optional post title.
	 * @param string $ai_prompt Optional initial AI instruction.
	 */
	private static function create_new_landing_page_post( string $post_type, string $mode = '', string $title = '', string $ai_prompt = '' ): void {
		if ( ! Post_Type::is_post_type_enabled( $post_type ) ) {
			wp_die( esc_html__( 'This post type is not enabled for Kayzart.', 'kayzart-live-code-editor' ) );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => '' !== $title ? $title : __( 'Untitled landing page', 'kayzart-live-code-editor' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ) );
		}

		Post_Type::enable_for_post( (int) $post_id );
		self::apply_setup_mode( (int) $post_id, $mode );
		if ( '' !== $ai_prompt && current_user_can( Ai_Setup::CAPABILITY ) ) {
			update_post_meta(
				(int) $post_id,
				self::INITIAL_AI_REQUEST_META_KEY,
				array(
					'requestId' => 'initial-' . wp_generate_uuid4(),
					'prompt'    => $ai_prompt,
					'userId'    => get_current_user_id(),
				)
			);
		}

		wp_safe_redirect( Post_Type::get_editor_url( (int) $post_id ) );
		exit;
	}

	/**
	 * Return a pending initial request only to the user who created it.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private static function get_initial_ai_request( int $post_id ): ?array {
		$request = get_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY, true );
		if ( ! is_array( $request ) || get_current_user_id() !== (int) ( $request['userId'] ?? 0 ) ) {
			return null;
		}
		if ( ! isset( $request['requestId'], $request['prompt'] ) || ! is_string( $request['requestId'] ) || ! is_string( $request['prompt'] ) ) {
			return null;
		}
		return array(
			'requestId' => $request['requestId'],
			'prompt'    => $request['prompt'],
		);
	}

	/**
	 * Consume a pending initial request after its AI job has been accepted.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $request_id Request ID.
	 * @param int    $user_id    Request owner.
	 */
	public static function consume_initial_ai_request( int $post_id, string $request_id, int $user_id ): void {
		$request = get_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY, true );
		if ( ! is_array( $request ) ) {
			return;
		}
		if ( $request_id === (string) ( $request['requestId'] ?? '' ) && $user_id === (int) ( $request['userId'] ?? 0 ) ) {
			delete_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY );
		}
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
	 * Build nonce-protected admin action URL for converting an existing post.
	 *
	 * This is the form target of the confirmation screen, not a link target.
	 *
	 * @param int $post_id Optional post ID.
	 * @return string
	 */
	public static function get_convert_post_action_url( int $post_id = 0 ): string {
		$args = array(
			'action'   => self::CONVERT_POST_ACTION,
			'_wpnonce' => wp_create_nonce( self::CONVERT_POST_NONCE_ACTION ),
		);
		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Build the confirmation screen URL for opening an unmanaged post in Kayzart.
	 *
	 * No nonce: this is a side-effect-free screen. The form it renders is
	 * nonce-protected.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_convert_screen_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page'    => self::CONVERT_SLUG,
				'post_id' => $post_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build nonce-protected admin action URL for duplicating an existing post.
	 *
	 * @param int $post_id Optional post ID.
	 * @return string
	 */
	public static function get_duplicate_post_action_url( int $post_id = 0 ): string {
		$args = array(
			'action'   => self::DUPLICATE_POST_ACTION,
			'_wpnonce' => wp_create_nonce( self::DUPLICATE_POST_NONCE_ACTION ),
		);
		if ( $post_id > 0 ) {
			$args['post_id'] = $post_id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
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
	 * Build the Kayzart create screen URL.
	 *
	 * No nonce: this is a side-effect-free screen. The form it renders is
	 * nonce-protected.
	 *
	 * The post type is passed as kayzart_post_type, not post_type: a post_type
	 * query arg makes WordPress set $typenow, which in turn makes admin.php
	 * resolve this page's parent as "admin.php?post_type=..." instead of the
	 * Kayzart menu, and the page then fails to load.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function get_new_screen_url( string $post_type = Post_Type::PAGE_TYPE ): string {
		return add_query_arg(
			array(
				'page'               => self::NEW_SLUG,
				self::NEW_TYPE_PARAM => sanitize_key( $post_type ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build the Kayzart settings page URL.
	 *
	 * Single source of truth for the settings URL; kayzart-pro resolves its
	 * license tab link through this method.
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

		return add_query_arg( $args, admin_url( 'admin.php' ) );
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

		// The top-level menu opens the create screen directly. Kept at edit_posts
		// so editors keep the create flow even though Settings needs manage_options.
		add_menu_page(
			__( 'Kayzart', 'kayzart-live-code-editor' ),
			__( 'Kayzart', 'kayzart-live-code-editor' ),
			'edit_posts',
			self::NEW_SLUG,
			array( __CLASS__, 'render_new_page' ),
			'dashicons-editor-code',
			21
		);

		// Renames the auto-generated first submenu item from "Kayzart" to "Add new".
		add_submenu_page(
			self::NEW_SLUG,
			__( 'Add new', 'kayzart-live-code-editor' ),
			__( 'Add new', 'kayzart-live-code-editor' ),
			'edit_posts',
			self::NEW_SLUG,
			array( __CLASS__, 'render_new_page' )
		);

		add_submenu_page(
			self::NEW_SLUG,
			__( 'Settings', 'kayzart-live-code-editor' ),
			__( 'Settings', 'kayzart-live-code-editor' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);

		// Hidden admin pages (no menu entry). Accessed via redirects and row
		// actions only. admin.php is a virtual parent so WordPress can resolve a
		// non-null page title.
		add_submenu_page(
			self::HIDDEN_PARENT_SLUG,
			__( 'Kayzart', 'kayzart-live-code-editor' ),
			__( 'Kayzart', 'kayzart-live-code-editor' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		add_submenu_page(
			self::HIDDEN_PARENT_SLUG,
			__( 'Edit with Kayzart', 'kayzart-live-code-editor' ),
			__( 'Edit with Kayzart', 'kayzart-live-code-editor' ),
			'edit_posts',
			self::CONVERT_SLUG,
			array( __CLASS__, 'render_convert_page' )
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
				__( 'Add with Kayzart', 'kayzart-live-code-editor' ),
				__( 'Add with Kayzart', 'kayzart-live-code-editor' ),
				(string) $post_type_object->cap->create_posts,
				self::get_new_screen_url( $post_type ),
				'',
				11
			);
		}
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
			self::OPTION_DEFAULT_EDITOR_LAYOUT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_default_editor_layout' ),
				'default'           => 'code_hidden',
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

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_AI_DEFAULT_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ai_default_model' ),
				'default'           => '',
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
				__( 'Kayzart slug', 'kayzart-live-code-editor' ),
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
			'kayzart_editor_display',
			__( 'Editor display', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_editor_display_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			self::OPTION_DEFAULT_EDITOR_LAYOUT,
			__( 'Default editor layout', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_default_editor_layout_field' ),
			self::SETTINGS_SLUG,
			'kayzart_editor_display'
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

		add_settings_section(
			'kayzart_ai',
			__( 'AI editing', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_section' ),
			self::SETTINGS_SLUG
		);

		add_settings_field(
			self::OPTION_AI_DEFAULT_MODEL,
			__( 'Default AI model', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_default_model_field' ),
			self::SETTINGS_SLUG,
			'kayzart_ai'
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
	 * Sanitize default editor layout value.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_default_editor_layout( $value ): string {

		$layout = is_string( $value ) ? sanitize_key( $value ) : '';
		$valid  = array( 'code_visible', 'code_hidden' );
		return in_array( $layout, $valid, true ) ? $layout : 'code_hidden';
	}

	/**
	 * Sanitize the default AI model. Empty means auto (let the AI Client pick).
	 *
	 * Only a model currently offered by the SDK is accepted; anything else
	 * (including a model that vanished from the catalog) falls back to auto.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_ai_default_model( $value ): string {
		$model = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $model ) {
			return '';
		}
		return self::validate_ai_default_model( $model, Ai_Models::available_for_text() );
	}

	/**
	 * Validate a default AI model against an already discovered model list.
	 *
	 * Keeping this separate lets the settings field use one catalog lookup for
	 * both validation and rendering. The public sanitizer still performs its own
	 * lookup when WordPress saves a submitted setting.
	 *
	 * @param string                                   $model     Normalized model ID.
	 * @param array<int,array{id:string,label:string}> $available Discovered models.
	 * @return string
	 */
	private static function validate_ai_default_model( string $model, array $available ): string {
		if ( empty( $available ) ) {
			// Catalog could not be verified (provider offline, SDK missing). Keep
			// the submitted value rather than silently resetting a valid choice.
			return $model;
		}
		foreach ( $available as $candidate ) {
			if ( $candidate['id'] === $model ) {
				return $model;
			}
		}
		return '';
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
		echo '<p>' . esc_html__( 'Change the URL slug for Kayzart posts. Existing URLs will change after saving.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render page template section description.
	 */
	public static function render_template_mode_section(): void {

		echo '<p>' . esc_html__( 'Choose the default page template mode used by Kayzart previews.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render editor display section description.
	 */
	public static function render_editor_display_section(): void {
		echo '<p>' . esc_html__( 'Choose how the Kayzart editor opens by default.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render post type section description.
	 */
	public static function render_post_types_section(): void {
		echo '<p>' . esc_html__( 'Choose which post types can use the Kayzart landing page editor.', 'kayzart-live-code-editor' ) . '</p>';
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
	 * Render default editor layout select field.
	 */
	public static function render_default_editor_layout_field(): void {

		$value   = get_option( self::OPTION_DEFAULT_EDITOR_LAYOUT, 'code_hidden' );
		$value   = self::sanitize_default_editor_layout( $value );
		$layouts = array(
			'code_visible' => __( 'Show code', 'kayzart-live-code-editor' ),
			'code_hidden'  => __( 'Hide code', 'kayzart-live-code-editor' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_DEFAULT_EDITOR_LAYOUT ) . '">';
		foreach ( $layouts as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $value, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'When code is hidden, the editor opens with the preview area expanded. The toolbar can still show code for the current session.', 'kayzart-live-code-editor' ) . '</p>';
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

		echo '<p class="description">' . esc_html__( 'Existing posts start using Kayzart only when you choose Edit with Kayzart, or create one with Add with Kayzart.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render AI editing section description.
	 */
	public static function render_ai_section(): void {

		echo '<p>' . esc_html__( 'Choose which model AI editing uses. Auto lets the configured AI provider pick.', 'kayzart-live-code-editor' ) . '</p>';
		self::render_ai_status_section();
	}

	/**
	 * Render the default AI model select field.
	 */
	public static function render_ai_default_model_field(): void {

		$models = Ai_Models::available_for_text();
		$stored = get_option( self::OPTION_AI_DEFAULT_MODEL, '' );
		$model  = is_string( $stored ) ? trim( $stored ) : '';
		$value  = '' === $model ? '' : self::validate_ai_default_model( $model, $models );

		echo '<select name="' . esc_attr( self::OPTION_AI_DEFAULT_MODEL ) . '">';
		echo '<option value="" ' . selected( '', $value, false ) . '>' . esc_html__( 'Auto (recommended)', 'kayzart-live-code-editor' ) . '</option>';
		foreach ( $models as $model ) {
			echo '<option value="' . esc_attr( $model['id'] ) . '" ' . selected( $model['id'], $value, false ) . '>' . esc_html( $model['label'] ) . '</option>';
		}
		echo '</select>';

		if ( empty( $models ) ) {
			echo '<p class="description">' . esc_html__( 'No selectable models were found. Configure an AI provider (Connector); editing will use the provider default until then.', 'kayzart-live-code-editor' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Auto follows the provider default and any newly added models automatically.', 'kayzart-live-code-editor' ) . '</p>';
		}
	}

	/**
	 * Render the admin editor container.
	 */
	public static function render_page(): void {
		$post_id = self::get_valid_editor_post_id( true );

		echo '<div id="kayzart-app" data-post-id="' . esc_attr( $post_id ) . '"></div>';
	}

	/**
	 * Render the AI availability checklist.
	 */
	private static function render_ai_status_section(): void {
		if ( ! class_exists( __NAMESPACE__ . '\\Ai_Availability' ) ) {
			return;
		}

		// Ai_Availability::get_status() probes the configured provider, so keep
		// this on the settings screen only and never on routine admin screens.
		$status = Ai_Availability::get_status();
		$checks = array(
			'sdk_present'         => array(
				__( 'AI Client SDK', 'kayzart-live-code-editor' ),
				__( 'Requires a WordPress version that bundles the AI Client.', 'kayzart-live-code-editor' ),
			),
			'provider_configured' => array(
				__( 'AI provider configured', 'kayzart-live-code-editor' ),
				__( 'Connect an AI provider so Kayzart can send edit requests.', 'kayzart-live-code-editor' ),
			),
			'scheduler_present'   => array(
				__( 'Action Scheduler', 'kayzart-live-code-editor' ),
				__( 'Required to run AI edits in the background.', 'kayzart-live-code-editor' ),
			),
			'mbstring_present'    => array(
				__( 'PHP mbstring extension', 'kayzart-live-code-editor' ),
				__( 'Ask your host to enable the mbstring extension.', 'kayzart-live-code-editor' ),
			),
			'dom_present'         => array(
				__( 'PHP DOM extension', 'kayzart-live-code-editor' ),
				__( 'Ask your host to enable the DOM and libxml extensions.', 'kayzart-live-code-editor' ),
			),
			'feature_enabled'     => array(
				__( 'AI editing enabled for this site', 'kayzart-live-code-editor' ),
				__( 'AI editing was disabled by a site filter.', 'kayzart-live-code-editor' ),
			),
		);

		echo '<h2>' . esc_html__( 'AI editing', 'kayzart-live-code-editor' ) . '</h2>';
		if ( ! empty( $status['available'] ) ) {
			echo '<p>' . esc_html__( 'AI editing is ready to use.', 'kayzart-live-code-editor' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'AI editing is unavailable until every requirement below is met.', 'kayzart-live-code-editor' ) . '</p>';
		}

		echo '<table class="widefat striped" style="max-width:60em">';
		echo '<tbody>';
		foreach ( $checks as $key => $check ) {
			$passed = ! empty( $status[ $key ] );
			echo '<tr>';
			echo '<td style="width:2em">' . ( $passed ? '<span aria-hidden="true">&#10003;</span>' : '<span aria-hidden="true">&#10007;</span>' ) . '</td>';
			echo '<td>' . esc_html( $check[0] );
			echo '<span class="screen-reader-text">' . ( $passed ? esc_html__( 'Available', 'kayzart-live-code-editor' ) : esc_html__( 'Unavailable', 'kayzart-live-code-editor' ) ) . '</span>';
			if ( ! $passed ) {
				echo '<p class="description">' . esc_html( $check[1] ) . '</p>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Render the create screen.
	 */
	public static function render_new_page(): void {
		$post_types = self::get_creatable_post_types();

		echo '<div class="wrap kayzart-create-page">';
		echo '<header class="kayzart-create-page__header">';
		echo '<span class="kayzart-create-page__eyebrow">' . esc_html__( 'Create with Kayzart', 'kayzart-live-code-editor' ) . '</span>';
		echo '<h1>' . esc_html__( 'Add new', 'kayzart-live-code-editor' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create a landing page with AI. By default, it is created as an independent page that is not affected by your theme design.', 'kayzart-live-code-editor' ) . '</p>';
		echo '</header>';

		if ( 0 === count( $post_types ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'You do not have permission to create Kayzart pages.', 'kayzart-live-code-editor' ) . '</p></div>';
			echo '</div>';
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen preference, validated below.
		$requested = isset( $_GET[ self::NEW_TYPE_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::NEW_TYPE_PARAM ] ) ) : '';
		$selected  = isset( $post_types[ $requested ] ) ? $requested : (string) array_key_first( $post_types );

		echo '<form class="kayzart-create-form" method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::NEW_PAGE_ACTION ) . '" />';
		wp_nonce_field( self::NEW_PAGE_NONCE_ACTION );

		echo '<section class="kayzart-create-section" aria-labelledby="kayzart-create-basics-title">';
		echo '<div class="kayzart-create-section__heading">';
		echo '<span class="kayzart-create-section__step" aria-hidden="true">1</span>';
		echo '<div><h2 id="kayzart-create-basics-title">' . esc_html__( 'Basic information', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose where to create the page and give it a working title.', 'kayzart-live-code-editor' ) . '</p></div>';
		echo '</div>';
		echo '<div class="kayzart-create-field">';
		echo '<label for="kayzart-create-title">' . esc_html__( 'Title', 'kayzart-live-code-editor' ) . '</label>';
		echo '<input id="kayzart-create-title" type="text" name="post_title" value="" placeholder="' . esc_attr__( 'Landing page title', 'kayzart-live-code-editor' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Optional. You can rename the page later.', 'kayzart-live-code-editor' ) . '</p>';
		echo '</div>';

		echo '<fieldset class="kayzart-create-field kayzart-create-fieldset">';
		echo '<legend>' . esc_html__( 'Create as', 'kayzart-live-code-editor' ) . '</legend>';
		echo '<div class="kayzart-create-options kayzart-create-options--post-type">';
		if ( count( $post_types ) > 1 ) {
			foreach ( $post_types as $post_type => $label ) {
				printf(
					'<label class="kayzart-create-option kayzart-create-option--compact"><input type="radio" name="post_type" value="%s"%s /><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><strong>%s</strong></span></label>',
					esc_attr( $post_type ),
					checked( $post_type, $selected, false ),
					esc_html( $label )
				);
			}
		} else {
			echo '<input type="hidden" name="post_type" value="' . esc_attr( $selected ) . '" />';
			printf(
				'<div class="kayzart-create-option kayzart-create-option--compact is-selected"><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><strong>%s</strong></span></div>',
				esc_html( $post_types[ $selected ] )
			);
		}
		echo '</div>';
		echo '</fieldset>';
		echo '</section>';

		echo '<section class="kayzart-create-section" aria-labelledby="kayzart-create-mode-title">';
		echo '<div class="kayzart-create-section__heading">';
		echo '<span class="kayzart-create-section__step" aria-hidden="true">2</span>';
		echo '<div><h2 id="kayzart-create-mode-title">' . esc_html__( 'Mode', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Choose how the page styles will be created.', 'kayzart-live-code-editor' ) . '</p></div>';
		echo '</div>';
		echo '<fieldset class="kayzart-create-fieldset"><legend class="screen-reader-text">' . esc_html__( 'Mode', 'kayzart-live-code-editor' ) . '</legend>';
		echo '<div class="kayzart-create-options kayzart-create-options--mode">';
		echo '<label class="kayzart-create-option kayzart-create-option--mode"><input type="radio" name="mode" value="tailwind" checked="checked" /><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><span class="kayzart-create-option__title"><strong>' . esc_html__( 'TailwindCSS', 'kayzart-live-code-editor' ) . '</strong><span class="kayzart-create-badge">' . esc_html__( 'Recommended', 'kayzart-live-code-editor' ) . '</span></span><span class="kayzart-create-option__description">' . esc_html__( 'Create the page with Tailwind CSS utility classes. Recommended because AI can understand and edit the code more easily.', 'kayzart-live-code-editor' ) . '</span></span></label>';
		echo '<label class="kayzart-create-option kayzart-create-option--mode"><input type="radio" name="mode" value="normal" /><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><span class="kayzart-create-option__title"><strong>' . esc_html__( 'Normal HTML/CSS', 'kayzart-live-code-editor' ) . '</strong></span><span class="kayzart-create-option__description">' . esc_html__( 'Create the page with standard HTML and CSS.', 'kayzart-live-code-editor' ) . '</span></span></label>';
		echo '</div>';
		echo '<p class="kayzart-create-note"><span class="dashicons dashicons-edit" aria-hidden="true"></span>' . esc_html__( 'You can change this later in the editor.', 'kayzart-live-code-editor' ) . '</p>';
		echo '</fieldset>';
		echo '</section>';

		$can_use_ai      = current_user_can( Ai_Setup::CAPABILITY );
		$ai_status       = $can_use_ai ? Ai_Availability::get_status() : array( 'available' => false );
		$ai_is_available = $can_use_ai && ! empty( $ai_status['available'] );
		echo '<section class="kayzart-create-section kayzart-create-section--ai' . ( $ai_is_available ? '' : ' is-disabled' ) . '" aria-labelledby="kayzart-create-ai-title">';
		echo '<div class="kayzart-create-section__heading">';
		echo '<span class="kayzart-create-section__step kayzart-create-section__step--ai" aria-hidden="true">✦</span>';
		echo '<div><h2 id="kayzart-create-ai-title">' . esc_html__( 'AI instruction', 'kayzart-live-code-editor' ) . '</h2>';
		echo '<p>' . esc_html__( 'Describe the page you want, and AI will start building it when the editor opens.', 'kayzart-live-code-editor' ) . '</p></div>';
		echo '</div>';
		echo '<div class="kayzart-create-field">';
		echo '<label class="screen-reader-text" for="kayzart-initial-ai-prompt">' . esc_html__( 'AI instruction', 'kayzart-live-code-editor' ) . '</label>';
		echo '<textarea id="kayzart-initial-ai-prompt" name="initial_ai_prompt" rows="7" maxlength="' . esc_attr( (string) self::INITIAL_AI_PROMPT_MAX_BYTES ) . '"' . disabled( $ai_is_available, false, false ) . ' aria-describedby="kayzart-initial-ai-prompt-description kayzart-initial-ai-prompt-count" placeholder="' . esc_attr__( 'Example: Create a landing page for a new service with a hero section, features, pricing, and a contact form.', 'kayzart-live-code-editor' ) . '"></textarea>';
		echo '<div class="kayzart-create-field__meta">';
		if ( ! $can_use_ai ) {
			echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'You do not have permission to use AI editing.', 'kayzart-live-code-editor' ) . '</p>';
		} elseif ( ! $ai_is_available ) {
			echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'AI editing must be configured before you can start with a request.', 'kayzart-live-code-editor' ) . '</p>';
		} else {
			echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'Optional. This instruction will be sent automatically when the editor opens.', 'kayzart-live-code-editor' ) . '</p>';
		}
		echo '<p id="kayzart-initial-ai-prompt-count" class="kayzart-create-counter" aria-live="polite">0 / ' . esc_html( (string) self::INITIAL_AI_PROMPT_MAX_BYTES ) . ' ' . esc_html__( 'bytes', 'kayzart-live-code-editor' ) . '</p>';
		echo '</div>';
		echo '</div>';
		echo '</section>';

		echo '<footer class="kayzart-create-form__footer">';
		echo '<div><strong>' . esc_html__( 'Ready to create?', 'kayzart-live-code-editor' ) . '</strong><span>' . esc_html__( 'The editor will open after the page is created.', 'kayzart-live-code-editor' ) . '</span></div>';
		submit_button( __( 'Create and open editor', 'kayzart-live-code-editor' ), 'primary large', 'submit', false, array( 'data-loading-label' => __( 'Creating…', 'kayzart-live-code-editor' ) ) );
		echo '</footer>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Send already-managed posts straight to the editor, before headers are sent.
	 *
	 * The confirmation screen only has something to confirm for posts Kayzart
	 * does not manage yet.
	 */
	public static function maybe_skip_convert_screen(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing of a side-effect-free screen.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( self::CONVERT_SLUG !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! Post_Type::is_editor_enabled_post( $post_id ) || ! Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			return;
		}

		wp_safe_redirect( Post_Type::get_editor_url( $post_id ) );
		exit;
	}

	/**
	 * Render the confirmation screen for opening an unmanaged post in Kayzart.
	 */
	public static function render_convert_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen; the form below is nonce-protected.
		$post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( (string) $_GET['post_id'] ) ) : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edit with Kayzart', 'kayzart-live-code-editor' ) . '</h1>';

		if ( ! $post instanceof \WP_Post || ! Post_Type::is_editor_enabled_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			echo '<p>' . esc_html__( 'This page cannot be opened in Kayzart.', 'kayzart-live-code-editor' ) . '</p>';
			echo '</div>';
			return;
		}

		// Already-managed posts are normally intercepted on admin_init; this is
		// only reached if headers were already sent.
		if ( Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( Post_Type::get_editor_url( $post_id ) ),
				esc_html__( 'Open editor', 'kayzart-live-code-editor' )
			);
			echo '</div>';
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: post title. */
					__( '"%s" will be opened in the Kayzart editor. Its existing content is kept as the initial HTML.', 'kayzart-live-code-editor' ),
					get_the_title( $post )
				)
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::CONVERT_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post_id ) . '" />';
		wp_nonce_field( self::CONVERT_POST_NONCE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		self::render_setup_mode_row();
		echo '</tbody></table>';
		submit_button( __( 'Open editor', 'kayzart-live-code-editor' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render the shared Normal/Tailwind mode chooser row.
	 *
	 * @param string $default_mode Optional selected mode.
	 */
	private static function render_setup_mode_row( string $default_mode = '' ): void {
		$default = in_array( $default_mode, array( 'normal', 'tailwind' ), true )
			? $default_mode
			: ( 'tailwind' === get_option( self::OPTION_DEFAULT_TEMPLATE_MODE, 'normal' ) ? 'tailwind' : 'normal' );
		$modes   = array(
			'tailwind' => array(
				'label'       => sprintf(
					/* translators: %s: CSS framework name. */
					__( '%s (Recommended)', 'kayzart-live-code-editor' ),
					__( 'TailwindCSS', 'kayzart-live-code-editor' )
				),
				'description' => __( 'Create the page with Tailwind CSS utility classes. Recommended because AI can understand and edit the code more easily.', 'kayzart-live-code-editor' ),
			),
			'normal'   => array(
				'label'       => __( 'Normal HTML/CSS', 'kayzart-live-code-editor' ),
				'description' => __( 'Create the page with standard HTML and CSS.', 'kayzart-live-code-editor' ),
			),
		);

		echo '<tr><th scope="row">' . esc_html__( 'Mode', 'kayzart-live-code-editor' ) . '</th><td><fieldset>';
		foreach ( $modes as $mode => $details ) {
			printf(
				'<label style="display:block"><input type="radio" name="mode" value="%s"%s /> %s<span class="description" style="display:block;margin-left:24px">%s</span></label>',
				esc_attr( $mode ),
				checked( $mode, $default, false ),
				esc_html( $details['label'] ),
				esc_html( $details['description'] )
			);
		}
		echo '<p class="description">' . esc_html__( 'You can change this later in the editor.', 'kayzart-live-code-editor' ) . '</p>';
		echo '</fieldset></td></tr>';
	}

	/**
	 * Return enabled post types the current user may create, as slug => label.
	 *
	 * @return array<string,string>
	 */
	private static function get_creatable_post_types(): array {
		$post_types = array();
		foreach ( Post_Type::get_enabled_post_types() as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || empty( $post_type_object->cap->create_posts ) ) {
				continue;
			}
			if ( ! current_user_can( $post_type_object->cap->create_posts ) ) {
				continue;
			}
			$post_types[ $post_type ] = ! empty( $post_type_object->labels->name )
				? (string) $post_type_object->labels->name
				: $post_type;
		}
		if ( isset( $post_types[ Post_Type::PAGE_TYPE ] ) ) {
			$page_label = $post_types[ Post_Type::PAGE_TYPE ];
			unset( $post_types[ Post_Type::PAGE_TYPE ] );
			$post_types = array_merge( array( Post_Type::PAGE_TYPE => $page_label ), $post_types );
		}
		return $post_types;
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
		echo '<h1>' . esc_html__( 'Settings', 'kayzart-live-code-editor' ) . '</h1>';
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
		if ( 'toplevel_page_' . self::NEW_SLUG === $hook_suffix ) {
			self::enqueue_new_page_assets();
			return;
		}

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
		$post         = $post_id ? get_post( $post_id ) : null;
		$html         = $post ? (string) $post->post_content : '';
		$body_attrs   = $post_id ? (string) get_post_meta( $post_id, Html_Document::BODY_ATTRS_META_KEY, true ) : '';
		$html         = Html_Document::build_editor_html( $html, $body_attrs );
		$custom_head  = $post_id ? Custom_Head::get_for_post( $post_id ) : '';
		$css          = $post_id ? (string) get_post_meta( $post_id, '_kayzart_css', true ) : '';
		$editor_mode  = $post_id && '1' === get_post_meta( $post_id, '_kayzart_tailwind', true ) ? 'tailwind' : 'normal';
		$normal_css   = $post_id && metadata_exists( 'post', $post_id, '_kayzart_normal_css' )
			? (string) get_post_meta( $post_id, '_kayzart_normal_css', true )
			: ( 'normal' === $editor_mode ? $css : null );
		$tailwind_css = $post_id && metadata_exists( 'post', $post_id, '_kayzart_tailwind_css' )
			? (string) get_post_meta( $post_id, '_kayzart_tailwind_css', true )
			: ( 'tailwind' === $editor_mode ? $css : null );
		$js           = $post_id ? (string) get_post_meta( $post_id, '_kayzart_js', true ) : '';
		$js_mode      = self::normalize_js_mode( $post_id ? get_post_meta( $post_id, '_kayzart_js_mode', true ) : '' );
		$back_url     = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'edit.php?post_type=' . Post_Type::POST_TYPE );
		$list_url     = self::get_editor_list_url( $post );
		$list_label   = self::get_editor_list_label( $post );

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

		$ai_status              = Ai_Availability::get_status();
		$initial_ai_request     = self::get_initial_ai_request( $post_id );
		$data      = array(
			'post_id'                => $post_id,
			'initialHtml'            => $html,
			'initialCustomHead'      => $custom_head,
			'initialCss'             => $css,
			'initialCssByMode'       => array(
				'normal'   => $normal_css,
				'tailwind' => $tailwind_css,
			),
			'initialEditorMode'      => $editor_mode,
			'initialJs'              => $js,
			'initialJsMode'          => $js_mode,
			'canEditJs'              => current_user_can( 'unfiltered_html' ),
			'documentHtmlAttributes' => get_language_attributes(),
			'previewUrl'             => $preview_url,
			'iframePreviewUrl'       => $iframe_preview_url,
			'restUrl'                => rest_url( 'kayzart/v1/save' ),
			'restCompileUrl'         => rest_url( 'kayzart/v1/compile-tailwind' ),
			'revisionsRestUrl'       => rest_url( 'kayzart/v1/revisions' ),
			'setupRestUrl'           => rest_url( 'kayzart/v1/setup' ),
			'backUrl'                => $back_url,
			'listUrl'                => $list_url,
			'listLabel'              => $list_label,
			'settingsRestUrl'        => rest_url( 'kayzart/v1/settings' ),
			'settingsData'           => Rest::build_settings_payload( $post_id ),
			'defaultEditorLayout'    => self::sanitize_default_editor_layout(
				get_option( self::OPTION_DEFAULT_EDITOR_LAYOUT, 'code_hidden' )
			),
			'tailwindEnabled'        => 'tailwind' === $editor_mode,
			'setupRequired'          => get_post_meta( $post_id, '_kayzart_setup_required', true ) === '1',
			'restNonce'              => wp_create_nonce( 'wp_rest' ),
			'revisionsSupported'     => Snapshot::is_supported(),
			'wpVersion'              => (string) $GLOBALS['wp_version'],
			'canUpdateCore'          => current_user_can( 'update_core' ),
			'updateCoreUrl'          => current_user_can( 'update_core' ) ? admin_url( 'update-core.php' ) : '',
			'adminTitleSeparators'   => array_values( self::ADMIN_TITLE_SEPARATORS ),
			'ai'                     => array(
				'available'           => $ai_status['available'],
				'featureEnabled'      => $ai_status['feature_enabled'],
				'sdkPresent'          => $ai_status['sdk_present'],
				'providerConfigured'  => $ai_status['provider_configured'],
				'schedulerPresent'    => $ai_status['scheduler_present'],
				'mbstringPresent'     => $ai_status['mbstring_present'],
				'domPresent'          => $ai_status['dom_present'],
				'canEdit'             => current_user_can( Ai_Setup::CAPABILITY ),
				'jobsUrl'             => rest_url( 'kayzart/v1/ai/jobs' ),
				'jobsBaseUrl'         => rest_url( 'kayzart/v1/ai/jobs/' ),
				'timelineUrl'         => rest_url( 'kayzart/v1/ai/timeline' ),
				'timelineBaseUrl'     => rest_url( 'kayzart/v1/ai/timeline/' ),
				'connectorsUrl'       => admin_url( 'options-connectors.php' ),
				'canManageConnectors' => current_user_can( 'manage_options' ),
				'initialRequest'       => $initial_ai_request,
			),
		);
		$json      = wp_json_encode( $data );
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
	 * Enqueue the lightweight assets used by the new page screen.
	 */
	private static function enqueue_new_page_assets(): void {
		$handle = 'kayzart-new-page';

		wp_register_style(
			$handle,
			KAYZART_URL . 'assets/admin/new-page.css',
			array( 'dashicons' ),
			self::resolve_asset_version( KAYZART_PATH . 'assets/admin/new-page.css' )
		);
		wp_register_script(
			$handle,
			KAYZART_URL . 'assets/admin/new-page.js',
			array( 'wp-dom-ready' ),
			self::resolve_asset_version( KAYZART_PATH . 'assets/admin/new-page.js' ),
			true
		);

		wp_enqueue_style( $handle );
		wp_enqueue_script( $handle );
		wp_add_inline_script(
			$handle,
			'window.KAYZART_NEW_PAGE = ' . wp_json_encode(
				array(
					'maxPromptBytes' => self::INITIAL_AI_PROMPT_MAX_BYTES,
					'bytesLabel'     => __( 'bytes', 'kayzart-live-code-editor' ),
				)
			) . ';',
			'before'
		);
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
					'createUrl' => self::get_new_screen_url( $post_type ),
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
