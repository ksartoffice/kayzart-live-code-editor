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
	const RETURN_POST_ACTION           = 'kayzart_return_to_wordpress';
	const RETURN_POST_NONCE_ACTION     = 'kayzart_return_to_wordpress_post';
	const DUPLICATE_POST_ACTION        = 'kayzart_duplicate';
	const DUPLICATE_POST_NONCE_ACTION  = 'kayzart_duplicate_post';
	const REDIRECT_NONCE_ACTION        = 'kayzart_redirect';
	const EDITOR_PAGE_NONCE_ACTION     = 'kayzart_editor_page';
	const OPTION_POST_SLUG             = 'kayzart_post_slug';
	const OPTION_ENABLED_POST_TYPES    = 'kayzart_enabled_post_types';
	const OPTION_DEFAULT_TEMPLATE_MODE = 'kayzart_default_template_mode';
	const OPTION_DEFAULT_EDITOR_LAYOUT = 'kayzart_default_editor_layout';
	const OPTION_AI_DEFAULT_MODEL      = 'kayzart_ai_default_model';
	const OPTION_OPENAI_API_KEY        = 'kayzart_openai_api_key';
	const OPTION_AI_MAX_TURNS          = 'kayzart_ai_max_turns';
	const AI_MAX_TURNS_DEFAULT         = 15;
	const AI_MAX_TURNS_MIN             = 10;
	const AI_MAX_TURNS_MAX             = 30;
	const INITIAL_AI_REQUEST_META_KEY  = '_kayzart_initial_ai_request';
	const OPTION_AI_MAX_PROMPT_CHARS   = 'kayzart_ai_max_prompt_chars';
	const AI_MAX_PROMPT_CHARS_DEFAULT  = 8000;
	const AI_MAX_PROMPT_CHARS_MIN      = 1000;
	const AI_MAX_PROMPT_CHARS_MAX      = 50000;
	const OPTION_FLUSH_REWRITE         = 'kayzart_flush_rewrite';
	const REMOVE_OPENAI_KEY_ACTION     = 'kayzart_remove_openai_key';
	const REMOVE_OPENAI_KEY_NONCE      = 'kayzart_remove_openai_key';
	const OPTION_CONNECTOR_NOTICE      = 'kayzart_connector_migration_notice_shown';
	const OPTION_DORMANT_KEY_NOTICE    = 'kayzart_dormant_openai_key_notice_shown';
	const TRANSIENT_AI_BACKEND         = 'kayzart_ai_backend';
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
		add_action( 'admin_action_' . self::RETURN_POST_ACTION, array( __CLASS__, 'action_return_to_wordpress' ) );
		add_action( 'admin_action_' . self::DUPLICATE_POST_ACTION, array( __CLASS__, 'action_duplicate_post' ) );
		add_action( 'admin_action_' . self::REMOVE_OPENAI_KEY_ACTION, array( __CLASS__, 'action_remove_openai_key' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_duplicated_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_connector_migration_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_dormant_openai_key_notice' ) );
		add_action( 'add_option_' . self::OPTION_OPENAI_API_KEY, array( __CLASS__, 'flush_cached_ai_backend' ) );
		add_action( 'update_option_' . self::OPTION_OPENAI_API_KEY, array( __CLASS__, 'flush_cached_ai_backend' ) );
		add_action( 'delete_option_' . self::OPTION_OPENAI_API_KEY, array( __CLASS__, 'flush_cached_ai_backend' ) );
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
		self::create_new_landing_page_post( Post_Type::POST_TYPE, self::read_requested_setup_mode(), self::read_requested_post_title(), '', 'blank' );
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
		$start_mode = self::read_requested_start_mode();
		$prompt     = 'ai' === $start_mode ? self::read_requested_initial_ai_prompt() : '';
		if ( 'ai' === $start_mode ) {
			if ( ! current_user_can( Ai_Setup::CAPABILITY ) || ! Ai_Availability::is_available() ) {
				wp_die( esc_html__( 'AI editing must be configured before starting with AI.', 'kayzart-live-code-editor' ) );
			}
			if ( '' === $prompt ) {
				wp_die( esc_html__( 'Enter an AI instruction or choose Start blank.', 'kayzart-live-code-editor' ) );
			}
		}
		self::create_new_landing_page_post( $post_type, self::read_requested_setup_mode(), self::read_requested_post_title(), $prompt, $start_mode );
	}

	/** Read the requested creation path. */
	private static function read_requested_start_mode(): string {

		$mode = sanitize_key( self::read_request_value( 'start_mode' ) );
		if ( in_array( $mode, array( 'ai', 'blank' ), true ) ) {
			return $mode;
		}
		// Backward compatibility for old create links and integrations: an
		// instruction implies AI; otherwise opening a normal blank editor remains
		// valid and does not suddenly require provider configuration.
		return '' !== trim( self::read_request_value( 'initial_ai_prompt' ) ) ? 'ai' : 'blank';
	}

	/** Delete only the database-stored OpenAI credential. */
	public static function action_remove_openai_key(): void {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}
		check_admin_referer( self::REMOVE_OPENAI_KEY_NONCE );
		delete_option( self::OPTION_OPENAI_API_KEY );
		wp_safe_redirect( add_query_arg( 'kayzart_openai_key_removed', '1', self::get_settings_url() ) );
		exit;
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
		$prompt = wp_unslash( (string) $_POST['initial_ai_prompt'] );
		if ( ! mb_check_encoding( $prompt, 'UTF-8' ) ) {
			$prompt = mb_convert_encoding( $prompt, 'UTF-8', 'UTF-8' );
		}
		$prompt = trim( wp_check_invalid_utf8( $prompt ) );
		if ( mb_strlen( $prompt, 'UTF-8' ) > self::get_ai_max_prompt_chars() ) {
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
	 * Return a converted post to its native WordPress editor.
	 */
	public static function action_return_to_wordpress(): void {

		self::verify_action_nonce( self::RETURN_POST_NONCE_ACTION );

		$post_id = absint( self::read_request_value( 'post_id' ) );
		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || Post_Type::POST_TYPE === $post->post_type || ! Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			wp_die( esc_html__( 'This post is not a converted Kayzart post.', 'kayzart-live-code-editor' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		$job_uuid = ( new Ai_Job_Store() )->cancel_active_for_post( $post_id );
		if ( $job_uuid ) {
			Ai_Worker::unschedule_job( $job_uuid );
		}
		delete_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY );
		Post_Type::disable_for_post( $post_id );

		$edit_url = get_edit_post_link( $post_id, 'raw' );
		if ( ! is_string( $edit_url ) || '' === $edit_url ) {
			$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}
		wp_safe_redirect( $edit_url );
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
		self::INITIAL_AI_REQUEST_META_KEY,
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

	/** Tell upgraded sites once that WordPress Connectors now take precedence. */
	public static function maybe_render_connector_migration_notice(): void {
		global $wp_version;
		if ( ! current_user_can( 'manage_options' ) || version_compare( (string) $wp_version, '7.0', '<' ) ) {
			return;
		}
		if ( get_option( self::OPTION_CONNECTOR_NOTICE, false ) ) {
			return;
		}
		// Cheap check first: only a key in this database can be migrated away from.
		if ( 'database' !== Ai_OpenAI_Key::source() ) {
			return;
		}
		if ( Ai_Client_Factory::OPENAI !== self::get_cached_ai_backend() ) {
			return;
		}
		update_option( self::OPTION_CONNECTOR_NOTICE, '1', false );
		printf(
			'<div class="notice notice-info is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Kayzart is continuing to use your saved OpenAI key. WordPress 7.0 can use a shared AI provider through Connectors, which will take precedence once configured.', 'kayzart-live-code-editor' ),
			esc_url( admin_url( 'options-connectors.php' ) ),
			esc_html__( 'Open Connectors', 'kayzart-live-code-editor' )
		);
	}

	/**
	 * Tell sites once that a stored OpenAI key is no longer being used.
	 *
	 * The migration notice above only fires while the direct backend is still
	 * active, so without this a key left in the database after a Connector is
	 * configured would sit there unused and unmentioned forever.
	 */
	public static function maybe_render_dormant_openai_key_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( self::OPTION_DORMANT_KEY_NOTICE, false ) ) {
			return;
		}
		// Cheap checks first: only a key in this database can be removed here, and
		// only a WordPress 7.0 Connector can displace it.
		global $wp_version;
		if ( 'database' !== Ai_OpenAI_Key::source() || version_compare( (string) $wp_version, '7.0', '<' ) ) {
			return;
		}
		if ( Ai_Client_Factory::WORDPRESS !== self::get_cached_ai_backend() ) {
			return;
		}
		update_option( self::OPTION_DORMANT_KEY_NOTICE, '1', false );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'Kayzart is now using a WordPress Connector, so the OpenAI API key saved in this site\'s database is no longer used. Removing it keeps an unused credential off your site.', 'kayzart-live-code-editor' ),
			esc_url( self::get_settings_url() ),
			esc_html__( 'Review the saved key', 'kayzart-live-code-editor' )
		);
	}

	/**
	 * Resolve the active AI backend, cached between admin requests.
	 *
	 * Backend resolution probes the configured provider. Both notices above run on
	 * every admin screen and stay silent in states that can persist indefinitely,
	 * so the probe is bounded rather than repeated on every page load.
	 *
	 * @return string One of the Ai_Client_Factory backend constants.
	 */
	private static function get_cached_ai_backend(): string {
		$cached = get_transient( self::TRANSIENT_AI_BACKEND );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$backend = Ai_Client_Factory::resolve_backend();
		set_transient( self::TRANSIENT_AI_BACKEND, $backend, HOUR_IN_SECONDS );

		return $backend;
	}

	/** Drop the cached backend when the direct credential changes. */
	public static function flush_cached_ai_backend(): void {
		delete_transient( self::TRANSIENT_AI_BACKEND );
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
	 * @param string $ai_prompt  Optional initial AI instruction.
	 * @param string $start_mode Initial editor entry mode.
	 */
	private static function create_new_landing_page_post( string $post_type, string $mode = '', string $title = '', string $ai_prompt = '', string $start_mode = 'blank' ): void {

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

		$entry_mode = 'ai' === $start_mode && '' !== $ai_prompt ? 'ai' : 'blank';
		wp_safe_redirect(
			add_query_arg(
				'kayzart_entry',
				$entry_mode,
				Post_Type::get_editor_url( (int) $post_id )
			)
		);
		exit;
	}

	/** Resolve the one-time code visibility fallback used before a user preference exists. */
	public static function get_legacy_editor_layout_fallback(): string {

		$stored = get_option( self::OPTION_DEFAULT_EDITOR_LAYOUT, null );
		if ( is_string( $stored ) && in_array( $stored, array( 'code_visible', 'code_hidden' ), true ) ) {
			return $stored;
		}

		return 'code_hidden';
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
	 * Whether a request ID matches the pending initial request for its owner.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $request_id Request ID.
	 * @param int    $user_id    Request owner.
	 */
	public static function matches_initial_ai_request( int $post_id, string $request_id, int $user_id ): bool {
		$request = get_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY, true );
		return is_array( $request )
		&& (string) ( $request['requestId'] ?? '' ) === $request_id
		&& (int) ( $request['userId'] ?? 0 ) === $user_id;
	}

	/**
	 * Consume a pending initial request after its AI job has been accepted.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $request_id Request ID.
	 * @param int    $user_id    Request owner.
	 */
	public static function consume_initial_ai_request( int $post_id, string $request_id, int $user_id ): void {
		if ( self::matches_initial_ai_request( $post_id, $request_id, $user_id ) ) {
			delete_post_meta( $post_id, self::INITIAL_AI_REQUEST_META_KEY );
		}
	}

	/**
	 * Turn off WordPress emoji replacement on the editor screen.
	 *
	 * The wp-emoji script watches the whole document and rewrites emoji text into <img>
	 * elements. Inside CodeMirror that rewrite corrupts the rendered text, so
	 * CodeMirror syncs its document to the damaged DOM and silently drops the
	 * character. While the editor is locked for a running AI job it cannot
	 * accept that change, so it reverts the DOM instead, wp-emoji rewrites it
	 * again, and the two loop forever in microtasks. That starves the task
	 * queue and freezes the whole browser tab.
	 */
	private static function disable_emoji_replacement(): void {
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		add_filter( 'emoji_svg_url', '__return_false' );
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
	 * Build a nonce-protected URL for returning a post to WordPress editing.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_return_post_action_url( int $post_id ): string {

		return add_query_arg(
			array(
				'action'   => self::RETURN_POST_ACTION,
				'post_id'  => $post_id,
				'_wpnonce' => wp_create_nonce( self::RETURN_POST_NONCE_ACTION ),
			),
			admin_url( 'admin.php' )
		);
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
			Post_Type::menu_icon(),
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
			self::OPTION_ENABLED_POST_TYPES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled_post_types' ),
				'default'           => array( Post_Type::PAGE_TYPE ),
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_OPENAI_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Ai_OpenAI_Key::class, 'sanitize' ),
				'default'           => '',
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

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_AI_MAX_TURNS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ai_max_turns' ),
				'default'           => self::AI_MAX_TURNS_DEFAULT,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_AI_MAX_PROMPT_CHARS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_ai_max_prompt_chars' ),
				'default'           => self::AI_MAX_PROMPT_CHARS_DEFAULT,
			)
		);

		add_settings_section(
			'kayzart_ai',
			__( 'AI editing', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_section' ),
			self::SETTINGS_SLUG
		);

		// The setting itself stays registered unconditionally so sanitization,
		// removal and uninstall behave the same however the field is presented.
		if ( self::should_show_direct_openai_field() ) {
			add_settings_field(
				self::OPTION_OPENAI_API_KEY,
				__( 'OpenAI API key', 'kayzart-live-code-editor' ),
				array( __CLASS__, 'render_openai_api_key_field' ),
				self::SETTINGS_SLUG,
				'kayzart_ai'
			);
		}

		add_settings_field(
			self::OPTION_AI_DEFAULT_MODEL,
			__( 'Default AI model', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_default_model_field' ),
			self::SETTINGS_SLUG,
			'kayzart_ai'
		);

		add_settings_field(
			self::OPTION_AI_MAX_TURNS,
			__( 'Maximum AI turns', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_max_turns_field' ),
			self::SETTINGS_SLUG,
			'kayzart_ai'
		);

		add_settings_field(
			self::OPTION_AI_MAX_PROMPT_CHARS,
			__( 'Maximum instruction length', 'kayzart-live-code-editor' ),
			array( __CLASS__, 'render_ai_max_prompt_chars_field' ),
			self::SETTINGS_SLUG,
			'kayzart_ai'
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

	/** Check whether the current request targets the Kayzart settings screen. */
	private static function is_settings_page_request(): bool {
		return self::SETTINGS_SLUG === self::read_request_value( 'page' );
	}

	/**
	 * Check whether the direct OpenAI credential field belongs on the settings screen.
	 *
	 * WordPress 7.0 configures AI providers through Connectors, so the direct field
	 * is no longer the way in. It is kept only where it is still the site's sole
	 * route to AI editing, or where a key is already stored and the site owner needs
	 * a way to review and remove it.
	 *
	 * @return bool
	 */
	public static function should_show_direct_openai_field(): bool {
		// register_settings() runs on every admin_init, and get_status() probes the
		// configured provider, so the decision is only made where the field renders.
		if ( ! self::is_settings_page_request() ) {
			return true;
		}

		// The selected backend, not raw SDK presence, decides this: a site below
		// WordPress 7.0 can load the AI Client and still be served by the direct
		// backend, and hiding the field there would lock AI editing out entirely.
		$status = Ai_Availability::get_status();
		$show   = Ai_Client_Factory::WORDPRESS !== $status['backend']
			|| 'none' !== $status['direct_key_source'];

		/**
		 * Filter whether the direct OpenAI API key field is offered.
		 *
		 * @param bool                $show   Whether to render the field.
		 * @param array<string,mixed> $status Current AI availability status.
		 */
		return (bool) apply_filters( 'kayzart_ai_show_direct_key_field', $show, $status );
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
	 * An omitted field preserves the stored preference.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_ai_default_model( $value ): string {
		if ( null === $value ) {
			$stored = get_option( self::OPTION_AI_DEFAULT_MODEL, '' );
			return is_string( $stored ) ? trim( $stored ) : '';
		}

		$model = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $model ) {
			return '';
		}
		return self::validate_ai_default_model( $model, Ai_Models::available_for_text() );
	}

	/**
	 * Sanitize the maximum number of model turns allowed for one AI edit.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_ai_max_turns( $value ): int {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return self::AI_MAX_TURNS_DEFAULT;
		}

		return max( self::AI_MAX_TURNS_MIN, min( self::AI_MAX_TURNS_MAX, (int) $value ) );
	}

	/**
	 * Get the configured maximum number of model turns for a new AI edit.
	 *
	 * @return int
	 */
	public static function get_ai_max_turns(): int {
		return self::sanitize_ai_max_turns( get_option( self::OPTION_AI_MAX_TURNS, self::AI_MAX_TURNS_DEFAULT ) );
	}

	/**
	 * Sanitize the maximum number of characters allowed in an AI instruction.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_ai_max_prompt_chars( $value ): int {
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) || ! is_numeric( $value ) ) {
			return self::AI_MAX_PROMPT_CHARS_DEFAULT;
		}

		return max( self::AI_MAX_PROMPT_CHARS_MIN, min( self::AI_MAX_PROMPT_CHARS_MAX, (int) $value ) );
	}

	/**
	 * Get the stored maximum number of characters, before any filter is applied.
	 *
	 * The settings field renders this rather than the effective limit. The field
	 * is bound to the option, so showing a filtered value would both put a number
	 * outside min/max into the input and write that number back to the option the
	 * next time any setting on the page is saved.
	 *
	 * @return int
	 */
	public static function get_stored_ai_max_prompt_chars(): int {
		return self::sanitize_ai_max_prompt_chars( get_option( self::OPTION_AI_MAX_PROMPT_CHARS, self::AI_MAX_PROMPT_CHARS_DEFAULT ) );
	}

	/**
	 * Get the effective maximum number of characters for an AI instruction.
	 *
	 * Counted in characters rather than bytes so the limit is the same in every
	 * language. The filter is the escape hatch for sites that need a value
	 * outside the range offered on the settings screen.
	 *
	 * @return int
	 */
	public static function get_ai_max_prompt_chars(): int {
		$value = self::get_stored_ai_max_prompt_chars();

		/**
		 * Filter the maximum number of characters allowed in an AI instruction.
		 *
		 * @param int $value Maximum characters, already clamped to the settings range.
		 */
		$filtered = (int) apply_filters( 'kayzart_ai_max_prompt_chars', $value );

		return $filtered > 0 ? $filtered : $value;
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

		echo '<p class="description">' . esc_html__( 'Existing posts start using Kayzart only when you choose Start editing with Kayzart, or create one with Add with Kayzart.', 'kayzart-live-code-editor' ) . '</p>';
	}

	/**
	 * Render AI editing section description.
	 */
	public static function render_ai_section(): void {

		echo '<p>' . esc_html__( 'WordPress 7.0 and later use a configured Connector first. On older versions, or when no Connector is ready, Kayzart can connect directly to OpenAI.', 'kayzart-live-code-editor' ) . '</p>';
		self::render_ai_status_section();
	}

	/** Render a write-only direct OpenAI credential field. */
	public static function render_openai_api_key_field(): void {

		$source   = Ai_OpenAI_Key::source();
		$external = in_array( $source, array( 'environment', 'constant' ), true );
		if ( $external ) {
			printf(
				'<input type="password" class="regular-text" value="" placeholder="%s" disabled="disabled" autocomplete="new-password" />',
				esc_attr__( 'Configured outside WordPress', 'kayzart-live-code-editor' )
			);
			printf( '<p class="description">%s</p>', esc_html( 'environment' === $source ? __( 'The KAYZART_OPENAI_API_KEY environment variable is in use.', 'kayzart-live-code-editor' ) : __( 'The KAYZART_OPENAI_API_KEY PHP constant is in use.', 'kayzart-live-code-editor' ) ) );
			return;
		}

		$configured = 'database' === $source;
		$status     = Ai_Availability::get_status();

		// Only a Connector that is actually serving requests makes this credential
		// inert. A configured Connector below WordPress 7.0 still loses to the
		// direct backend, and calling the key unused there would be wrong.
		$connector_active = Ai_Client_Factory::WORDPRESS === $status['backend'];

		if ( $connector_active && $configured ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This key is not in use. Kayzart is running AI edits through a WordPress Connector, and removing the saved key keeps an unused credential off your site.', 'kayzart-live-code-editor' ) . '</p></div>';
		}

		if ( $connector_active ) {
			echo '<details' . ( $configured ? ' open' : '' ) . '>';
			echo '<summary>' . esc_html(
				$configured
					? __( 'Saved OpenAI API key', 'kayzart-live-code-editor' )
					: __( 'Advanced: connect directly to OpenAI instead', 'kayzart-live-code-editor' )
			) . '</summary>';
		}

		printf(
			'<input type="password" class="regular-text" name="%1$s" value="" placeholder="%2$s" autocomplete="new-password" spellcheck="false" />',
			esc_attr( self::OPTION_OPENAI_API_KEY ),
			esc_attr( $configured ? __( 'Configured — enter a new key to replace it', 'kayzart-live-code-editor' ) : __( 'sk-…', 'kayzart-live-code-editor' ) )
		);
		echo '<p class="description">' . esc_html__( 'Used for direct OpenAI access. The key is stored on this site and is never sent to the browser.', 'kayzart-live-code-editor' ) . '</p>';
		if ( $connector_active ) {
			echo '<p class="description">' . esc_html__( 'A configured Connector always takes precedence over this key.', 'kayzart-live-code-editor' ) . '</p>';
		}
		if ( $configured ) {
			$url = wp_nonce_url( admin_url( 'admin.php?action=' . self::REMOVE_OPENAI_KEY_ACTION ), self::REMOVE_OPENAI_KEY_NONCE );
			echo '<p><a class="button button-secondary" href="' . esc_url( $url ) . '">' . esc_html__( 'Remove saved API key', 'kayzart-live-code-editor' ) . '</a></p>';
		}

		if ( $connector_active ) {
		}
	}
	/**
	 * Render the default AI model select field.
	 */
	public static function render_ai_default_model_field(): void {

		$stored = get_option( self::OPTION_AI_DEFAULT_MODEL, '' );
		$model  = is_string( $stored ) ? trim( $stored ) : '';
		$status = Ai_Availability::get_status();
		if ( Ai_Client_Factory::OPENAI === $status['backend'] ) {
			echo '<input type="hidden" name="' . esc_attr( self::OPTION_AI_DEFAULT_MODEL ) . '" value="' . esc_attr( $model ) . '" />';
			echo '<code>' . esc_html( Ai_Client_OpenAI::MODEL ) . '</code>';
			echo '<p class="description">' . esc_html__( 'Direct OpenAI access uses this fixed model.', 'kayzart-live-code-editor' ) . '</p>';
			return;
		}
		$models = Ai_Models::available_for_text();
		$value  = '' === $model ? '' : self::validate_ai_default_model( $model, $models );

		echo '<select name="' . esc_attr( self::OPTION_AI_DEFAULT_MODEL ) . '">';
		echo '<option value="" ' . selected( '', $value, false ) . '>' . esc_html__( 'Auto', 'kayzart-live-code-editor' ) . '</option>';
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
	 * Render the maximum AI turns input field.
	 */
	public static function render_ai_max_turns_field(): void {
		$value = self::get_ai_max_turns();

		echo '<input type="number" class="small-text" name="' . esc_attr( self::OPTION_AI_MAX_TURNS ) . '" value="' . esc_attr( $value ) . '" min="' . esc_attr( self::AI_MAX_TURNS_MIN ) . '" max="' . esc_attr( self::AI_MAX_TURNS_MAX ) . '" step="1" />';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
				/* translators: 1: minimum number of AI turns, 2: maximum number of AI turns. */
					__( 'Choose between %1$d and %2$d model turns per AI edit. Higher values can increase processing time and AI usage.', 'kayzart-live-code-editor' ),
					self::AI_MAX_TURNS_MIN,
					self::AI_MAX_TURNS_MAX
				)
			)
		);
	}

	/**
	 * Render the maximum AI instruction length field.
	 */
	public static function render_ai_max_prompt_chars_field(): void {
		$stored    = self::get_stored_ai_max_prompt_chars();
		$effective = self::get_ai_max_prompt_chars();

		echo '<input type="number" class="small-text" name="' . esc_attr( self::OPTION_AI_MAX_PROMPT_CHARS ) . '" value="' . esc_attr( $stored ) . '" min="' . esc_attr( self::AI_MAX_PROMPT_CHARS_MIN ) . '" max="' . esc_attr( self::AI_MAX_PROMPT_CHARS_MAX ) . '" step="1" />';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
				/* translators: 1: minimum number of characters, 2: maximum number of characters. */
					__( 'Choose between %1$d and %2$d characters per AI instruction. The limit is counted in characters, so it is the same in every language. Longer instructions increase AI usage.', 'kayzart-live-code-editor' ),
					self::AI_MAX_PROMPT_CHARS_MIN,
					self::AI_MAX_PROMPT_CHARS_MAX
				)
			)
		);

		// Without this, a filtered site shows one number here and enforces another.
		if ( $effective !== $stored ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
					/* translators: %d: number of characters currently enforced. */
						__( 'A filter currently overrides this setting. The limit in use is %d characters.', 'kayzart-live-code-editor' ),
						$effective
					)
				)
			);
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

		global $wp_version;

		// Ai_Availability::get_status() probes the configured provider, so keep
		// this on the settings screen only and never on routine admin screens.
		$status = Ai_Availability::get_status();

		// The AI Client can be loaded below WordPress 7.0, where the Connector
		// backend is still rejected on version and the Connectors screen does not
		// exist, so pointing there would be advice the site owner cannot act on.
		$connector_supported = ! empty( $status['sdk_present'] )
			&& version_compare( (string) $wp_version, '7.0', '>=' );

		$checks = array(
			'provider_configured' => array(
				__( 'AI connection configured', 'kayzart-live-code-editor' ),
				$connector_supported
					? __( 'Configure a WordPress Connector to give Kayzart an AI provider.', 'kayzart-live-code-editor' )
					: __( 'Enter an OpenAI API key below.', 'kayzart-live-code-editor' ),
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
			$backend_label = Ai_Client_Factory::WORDPRESS === $status['backend'] ? __( 'WordPress AI Client / Connectors', 'kayzart-live-code-editor' ) : __( 'Direct OpenAI connection', 'kayzart-live-code-editor' );
			printf( '<p>%s <strong>%s</strong></p>', esc_html__( 'AI editing is ready. Active backend:', 'kayzart-live-code-editor' ), esc_html( $backend_label ) );
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
		if ( version_compare( (string) $wp_version, '7.0', '>=' ) && current_user_can( 'manage_options' ) ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Open WordPress Connectors', 'kayzart-live-code-editor' ) . '</a></p>';
		}
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

		$can_use_ai      = current_user_can( Ai_Setup::CAPABILITY );
		$ai_status       = $can_use_ai ? Ai_Availability::get_status() : array( 'available' => false );
		$ai_is_available = $can_use_ai && ! empty( $ai_status['available'] );
		if ( ! $ai_is_available ) {
			echo '<div class="kayzart-ai-setup-card"><strong>' . esc_html__( 'Set up AI editing', 'kayzart-live-code-editor' ) . '</strong>';
			if ( ! $can_use_ai ) {
				echo '<p>' . esc_html__( 'You can start blank and use the HTML/CSS/JS editor. Ask an administrator if you also need AI editing.', 'kayzart-live-code-editor' ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'You can continue with a blank page now, or configure AI before creating the page.', 'kayzart-live-code-editor' ) . '</p>';
				global $wp_version;
				if ( version_compare( (string) $wp_version, '7.0', '>=' ) && current_user_can( 'manage_options' ) ) {
					echo '<a class="button button-primary" href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'Open Connectors', 'kayzart-live-code-editor' ) . '</a> ';
				}
				if ( current_user_can( 'manage_options' ) ) {
					echo '<a class="button" href="' . esc_url( self::get_settings_url() ) . '">' . esc_html__( 'Configure OpenAI in Kayzart', 'kayzart-live-code-editor' ) . '</a>';
				}
			}
			echo '</div>';
		}
		echo '<section class="kayzart-create-section kayzart-create-section--ai" aria-labelledby="kayzart-create-ai-title">';
		echo '<div class="kayzart-create-section__heading">';
		echo '<span class="kayzart-create-section__step kayzart-create-section__step--ai" aria-hidden="true">&#10022;</span>';
		echo '<div><h2 id="kayzart-create-ai-title">' . esc_html( $ai_is_available ? __( 'Describe your landing page', 'kayzart-live-code-editor' ) : __( 'Page details', 'kayzart-live-code-editor' ) ) . '</h2>';
		echo '<p>' . esc_html( $ai_is_available ? __( 'Tell Kayzart what you want to build. AI will create the first draft when the editor opens.', 'kayzart-live-code-editor' ) : __( 'Name the page, then open the standard HTML/CSS/JS editor.', 'kayzart-live-code-editor' ) ) . '</p></div>';
		echo '</div>';
		echo '<div class="kayzart-create-field">';
		echo '<label for="kayzart-create-title">' . esc_html__( 'Title', 'kayzart-live-code-editor' ) . '</label>';
		echo '<input id="kayzart-create-title" type="text" name="post_title" value="" placeholder="' . esc_attr__( 'Landing page title', 'kayzart-live-code-editor' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Optional. You can rename the page later.', 'kayzart-live-code-editor' ) . '</p>';
		echo '</div>';
		if ( $ai_is_available ) {
			echo '<div class="kayzart-create-field">';
			echo '<label class="screen-reader-text" for="kayzart-initial-ai-prompt">' . esc_html__( 'AI instruction', 'kayzart-live-code-editor' ) . '</label>';
			echo '<div class="kayzart-ai-prompt-control">';
			echo '<textarea id="kayzart-initial-ai-prompt" name="initial_ai_prompt" rows="7"' . disabled( $ai_is_available, false, false ) . ' aria-describedby="kayzart-initial-ai-prompt-description kayzart-initial-ai-prompt-count" placeholder="' . esc_attr__( 'Example: Create a landing page for a new service with a hero section, features, pricing, and a contact form.', 'kayzart-live-code-editor' ) . '"></textarea>';
			echo '</div>';
			echo '<div class="kayzart-create-field__meta">';
			if ( ! $can_use_ai ) {
				echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'You do not have permission to use AI editing.', 'kayzart-live-code-editor' ) . '</p>';
			} elseif ( ! $ai_is_available ) {
				echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'AI editing must be configured before you can start with a request.', 'kayzart-live-code-editor' ) . '</p>';
			} else {
				echo '<p id="kayzart-initial-ai-prompt-description" class="description">' . esc_html__( 'Required to generate with AI. It will be sent automatically when the editor opens.', 'kayzart-live-code-editor' ) . '</p>';
			}
			echo '<p id="kayzart-initial-ai-prompt-count" class="kayzart-create-counter" aria-live="polite">0 / ' . esc_html( (string) self::get_ai_max_prompt_chars() ) . ' ' . esc_html__( 'characters', 'kayzart-live-code-editor' ) . '</p>';
			echo '</div>';
			echo '</div>';
		}
		echo '</section>';

		echo '<section class="kayzart-create-section kayzart-create-section--settings" aria-labelledby="kayzart-create-basics-title">';
		echo '<div class="kayzart-create-section__heading kayzart-create-section__heading--compact">';
		echo '<div><h2 id="kayzart-create-basics-title">' . esc_html__( 'Page settings', 'kayzart-live-code-editor' ) . '</h2></div>';
		echo '</div>';
		echo '<div class="kayzart-create-settings">';
		echo '<fieldset class="kayzart-create-fieldset">';
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
		echo '<fieldset class="kayzart-create-fieldset"><legend>' . esc_html__( 'Mode', 'kayzart-live-code-editor' ) . '</legend>';
		echo '<div class="kayzart-create-options kayzart-create-options--mode">';
		echo '<label class="kayzart-create-option kayzart-create-option--mode"><input type="radio" name="mode" value="tailwind" checked="checked" /><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><span class="kayzart-create-option__title"><strong>' . esc_html__( 'TailwindCSS', 'kayzart-live-code-editor' ) . '</strong><span class="kayzart-create-badge">' . esc_html__( 'Recommended', 'kayzart-live-code-editor' ) . '</span></span><span class="kayzart-create-option__description">' . esc_html__( 'Create the page with Tailwind CSS utility classes. Recommended because AI can understand and edit the code more easily.', 'kayzart-live-code-editor' ) . '</span></span></label>';
		echo '<label class="kayzart-create-option kayzart-create-option--mode"><input type="radio" name="mode" value="normal" /><span class="kayzart-create-option__control" aria-hidden="true"></span><span class="kayzart-create-option__body"><span class="kayzart-create-option__title"><strong>' . esc_html__( 'Normal HTML/CSS', 'kayzart-live-code-editor' ) . '</strong></span><span class="kayzart-create-option__description">' . esc_html__( 'Create the page with standard HTML and CSS.', 'kayzart-live-code-editor' ) . '</span></span></label>';
		echo '</div>';
		echo '</fieldset>';
		echo '</div>';
		echo '</section>';
		echo '<footer class="kayzart-create-form__footer">';
		echo '<div><strong>' . esc_html__( 'Ready to create?', 'kayzart-live-code-editor' ) . '</strong><span>' . esc_html__( 'The editor will open after the page is created.', 'kayzart-live-code-editor' ) . '</span>';
		if ( $ai_is_available ) {
			echo '<span id="kayzart-create-blank-hint" hidden="hidden">' . esc_html__( 'Clear the AI instruction to start with a blank page.', 'kayzart-live-code-editor' ) . '</span>';
		}
		echo '</div>';
		echo '<div class="kayzart-create-actions">';
		if ( $ai_is_available ) {
			echo '<button id="kayzart-create-blank" class="button button-large" type="submit" name="start_mode" value="blank" data-loading-label="' . esc_attr__( 'Creating…', 'kayzart-live-code-editor' ) . '">' . esc_html__( 'Start with a blank page', 'kayzart-live-code-editor' ) . '</button>';
			echo '<button id="kayzart-generate-ai" class="button button-primary button-large" type="submit" name="start_mode" value="ai" data-loading-label="' . esc_attr__( 'Creating…', 'kayzart-live-code-editor' ) . '" disabled="disabled">' . esc_html__( 'Generate with AI', 'kayzart-live-code-editor' ) . '</button>';
		} else {
			echo '<button id="kayzart-create-blank" class="button button-primary button-large" type="submit" name="start_mode" value="blank" data-loading-label="' . esc_attr__( 'Creating…', 'kayzart-live-code-editor' ) . '">' . esc_html__( 'Create blank page', 'kayzart-live-code-editor' ) . '</button>';
		}
		echo '</div>';
		echo '</footer>';
		echo '</form>';
		Feedback::render_invite_card( $selected );
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
		echo '<h1>' . esc_html__( 'Start editing with Kayzart', 'kayzart-live-code-editor' ) . '</h1>';
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
					__( 'The content editing for "%s" will move to Kayzart. Its existing content is kept as the initial HTML. After conversion, edit the content in Kayzart; the WordPress editor will show a Kayzart management card instead of the content editor.', 'kayzart-live-code-editor' ),
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
		submit_button( __( 'Convert and edit with Kayzart', 'kayzart-live-code-editor' ) );
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

		self::disable_emoji_replacement();

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

		$permalink = $post_id ? get_permalink( $post_id ) : '';
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			$permalink = home_url( '/' );
		}
		$preview_url        = add_query_arg( 'preview', 'true', $permalink );
		$iframe_preview_url = $post_id ? Preview::get_preview_url( $post_id ) : $preview_url;
		$ai_status          = Ai_Availability::get_status();
		$initial_ai_request = self::get_initial_ai_request( $post_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The editor route nonce was validated by get_valid_editor_post_id().
		$initial_entry_mode = isset( $_GET['kayzart_entry'] ) ? sanitize_key( wp_unslash( (string) $_GET['kayzart_entry'] ) ) : '';
		if ( ! in_array( $initial_entry_mode, array( 'ai', 'blank' ), true ) ) {
			$initial_entry_mode = '';
		}
		$data = array(
			'post_id'                      => $post_id,
			'initialHtml'                  => $html,
			'initialCustomHead'            => $custom_head,
			'initialCss'                   => $css,
			'initialCssByMode'             => array(
				'normal'   => $normal_css,
				'tailwind' => $tailwind_css,
			),
			'initialEditorMode'            => $editor_mode,
			'initialJs'                    => $js,
			'initialJsMode'                => $js_mode,
			'canEditJs'                    => current_user_can( 'unfiltered_html' ),
			'documentHtmlAttributes'       => get_language_attributes(),
			'previewUrl'                   => $preview_url,
			'iframePreviewUrl'             => $iframe_preview_url,
			'restUrl'                      => rest_url( 'kayzart/v1/save' ),
			'restCompileUrl'               => rest_url( 'kayzart/v1/compile-tailwind' ),
			'revisionsRestUrl'             => rest_url( 'kayzart/v1/revisions' ),
			'setupRestUrl'                 => rest_url( 'kayzart/v1/setup' ),
			'backUrl'                      => $back_url,
			'listUrl'                      => $list_url,
			'listLabel'                    => $list_label,
			'settingsRestUrl'              => rest_url( 'kayzart/v1/settings' ),
			'settingsData'                 => Rest::build_settings_payload( $post_id ),
			'legacyCodeVisibilityFallback' => self::get_legacy_editor_layout_fallback(),
			'initialEntryMode'             => $initial_entry_mode,
			'layoutStorageNamespace'       => sprintf(
				'kayzart.editorLayout.v1.site.%d.user.%d',
				get_current_blog_id(),
				get_current_user_id()
			),
			'tailwindEnabled'              => 'tailwind' === $editor_mode,
			'setupRequired'                => get_post_meta( $post_id, '_kayzart_setup_required', true ) === '1',
			'restNonce'                    => wp_create_nonce( 'wp_rest' ),
			'revisionsSupported'           => Snapshot::is_supported(),
			'wpVersion'                    => (string) $GLOBALS['wp_version'],
			'canUpdateCore'                => current_user_can( 'update_core' ),
			'updateCoreUrl'                => current_user_can( 'update_core' ) ? admin_url( 'update-core.php' ) : '',
			'adminTitleSeparators'         => array_values( self::ADMIN_TITLE_SEPARATORS ),
			'ai'                           => array(
				'available'           => $ai_status['available'],
				'setupState'          => $ai_status['setup_state'],
				'backend'             => $ai_status['backend'],
				'featureEnabled'      => $ai_status['feature_enabled'],
				'sdkPresent'          => $ai_status['sdk_present'],
				'providerConfigured'  => $ai_status['provider_configured'],
				'connectorConfigured' => $ai_status['connector_configured'],
				'directKeyConfigured' => $ai_status['direct_key_configured'],
				'directKeySource'     => $ai_status['direct_key_source'],
				'schedulerPresent'    => $ai_status['scheduler_present'],
				'mbstringPresent'     => $ai_status['mbstring_present'],
				'domPresent'          => $ai_status['dom_present'],
				'canEdit'             => current_user_can( Ai_Setup::CAPABILITY ),
				'jobsUrl'             => rest_url( 'kayzart/v1/ai/jobs' ),
				'jobsBaseUrl'         => rest_url( 'kayzart/v1/ai/jobs/' ),
				'timelineUrl'         => rest_url( 'kayzart/v1/ai/timeline' ),
				'timelineBaseUrl'     => rest_url( 'kayzart/v1/ai/timeline/' ),
				'connectorsUrl'       => admin_url( 'options-connectors.php' ),
				'settingsUrl'         => self::get_settings_url(),
				'canManageConnectors' => current_user_can( 'manage_options' ),
				'canManageSettings'   => current_user_can( 'manage_options' ),
				'maxPromptChars'      => self::get_ai_max_prompt_chars(),
				'initialRequest'      => $initial_ai_request,
			),
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
					'maxPromptChars' => self::get_ai_max_prompt_chars(),
					'charsLabel'     => __( 'characters', 'kayzart-live-code-editor' ),
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
