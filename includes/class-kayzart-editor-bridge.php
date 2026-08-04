<?php
/**
 * Bridge the default editor screen to the KayzArt editor.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the default editor experience for KayzArt posts.
 */
class Editor_Bridge {
	const SCRIPT_HANDLE = 'kayzart-editor-bridge';
	const STYLE_HANDLE  = 'kayzart-editor-bridge';

	/**
	 * Register hooks for the editor bridge.
	 */
	public static function init(): void {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_assets' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_classic_assets' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_content_guards' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'preserve_classic_editor_content' ), 20, 4 );
	}

	/**
	 * Enqueue assets for the block editor.
	 */
	public static function enqueue_block_assets(): void {
		$screen = get_current_screen();
		if ( ! self::is_kayzart_screen( $screen ) ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Enqueue assets for the classic editor.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public static function enqueue_classic_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! self::is_kayzart_screen( $screen ) ) {
			return;
		}

		if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Set up the enqueue data for both editors.
	 */
	private static function enqueue_assets(): void {
		$post_id = self::resolve_post_id();
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		wp_register_script(
			self::SCRIPT_HANDLE,
			KAYZART_URL . 'assets/admin/editor-bridge.js',
			array( 'wp-i18n', 'wp-dom-ready', 'wp-data' ),
			KAYZART_VERSION,
			true
		);

		wp_register_style(
			self::STYLE_HANDLE,
			KAYZART_URL . 'assets/admin/editor-bridge.css',
			array(),
			KAYZART_VERSION
		);

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::STYLE_HANDLE );

		$post_type = $post ? $post->post_type : get_post_type( $post_id );
		if ( ! is_string( $post_type ) || '' === $post_type ) {
			$post_type = Post_Type::POST_TYPE;
		}

		$is_managed = $post instanceof \WP_Post
			&& ( Post_Type::POST_TYPE === $post->post_type || Post_Type::is_kayzart_enabled_post( (int) $post->ID ) );
		$view_url   = $post instanceof \WP_Post ? get_preview_post_link( $post ) : '';
		if ( ! is_string( $view_url ) ) {
			$view_url = '';
		}

		$data = array(
			'postId'     => $post_id,
			'postType'   => $post_type,
			'actionUrl'  => Admin::get_action_redirect_url(),
			'previewUrl' => $is_managed ? Preview::get_preview_url( $post_id, 'wordpress_editor' ) : '',
			'viewUrl'    => $view_url,
			'enabled'    => $is_managed,
			'isManaged'  => $is_managed,
			'canConvert' => false,
			'labels'     => array(
				'edit'        => __( 'Edit with Kayzart', 'kayzart-live-code-editor' ),
				'eyebrow'     => __( 'Managed by Kayzart', 'kayzart-live-code-editor' ),
				'description' => __( 'Edit the page content in Kayzart. You can continue to change WordPress page settings here.', 'kayzart-live-code-editor' ),
				'titleLabel'  => __( 'Page title', 'kayzart-live-code-editor' ),
				'view'        => __( 'View page', 'kayzart-live-code-editor' ),
				'loading'     => __( 'Loading preview…', 'kayzart-live-code-editor' ),
				'loadFailed'  => __( 'The preview is taking longer than expected to load.', 'kayzart-live-code-editor' ),
				'reload'      => __( 'Reload preview', 'kayzart-live-code-editor' ),
			),
		);
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			$json = '{}';
		}

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.KAYZART_EDITOR = ' . $json . ';',
			'before'
		);

		wp_set_script_translations(
			self::SCRIPT_HANDLE,
			'kayzart-live-code-editor',
			KAYZART_PATH . 'languages'
		);
	}

	/**
	 * Register guards for core REST updates to editor-enabled post types.
	 */
	public static function register_rest_content_guards(): void {
		foreach ( Post_Type::get_enabled_post_types() as $post_type ) {
			add_filter( 'rest_pre_insert_' . $post_type, array( __CLASS__, 'preserve_managed_content' ), 20, 2 );
		}
	}

	/**
	 * Keep KayzArt-owned HTML unchanged when Gutenberg saves page settings.
	 *
	 * KayzArt's own REST endpoint writes with wp_update_post() directly, so it
	 * does not pass through this core REST controller filter.
	 *
	 * @param mixed            $prepared_post Prepared post object or error.
	 * @param \WP_REST_Request $request REST request.
	 * @return mixed
	 */
	public static function preserve_managed_content( $prepared_post, \WP_REST_Request $request ) {
		if ( is_wp_error( $prepared_post ) || ! is_object( $prepared_post ) || ! isset( $prepared_post->post_content ) ) {
			return $prepared_post;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		if ( ! $post_id || ! Post_Type::is_kayzart_post( $post_id ) ) {
			return $prepared_post;
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			$prepared_post->post_content = (string) $post->post_content;
		}

		return $prepared_post;
	}

	/**
	 * Keep KayzArt-owned HTML unchanged when the Classic Editor saves settings.
	 *
	 * The editpost action is dispatched by wp-admin/post.php after WordPress has
	 * verified the update nonce. Other wp_update_post() callers, including the
	 * KayzArt REST endpoint, do not use this action and remain unaffected.
	 *
	 * @param array $data                Sanitized post data.
	 * @param array $postarr             Sanitized post input.
	 * @param array $unsanitized_postarr Unsanitized post input.
	 * @param bool  $update              Whether this is an existing post update.
	 * @return array
	 */
	public static function preserve_classic_editor_content( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		unset( $unsanitized_postarr );

		// WordPress verifies the editpost nonce before applying this filter.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( ! is_admin() || ! $update || 'editpost' !== $action ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! Post_Type::is_kayzart_post( $post_id ) ) {
			return $data;
		}

		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			$data['post_content'] = (string) $post->post_content;
		}

		return $data;
	}

	/**
	 * Resolve the current post ID for editor screens.
	 *
	 * @return int
	 */
	private static function resolve_post_id(): int {
		$post = get_post();

		if ( ! $post || ! Post_Type::is_editor_enabled_post( $post ) || ! self::is_managed_post( $post ) ) {
			return 0;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * Check if the screen can show the KayzArt editor bridge.
	 *
	 * @param \WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	private static function is_kayzart_screen( $screen ): bool {
		if ( ! $screen ) {
			return false;
		}

		$post = get_post();

		return $post instanceof \WP_Post
			&& $screen->post_type === $post->post_type
			&& Post_Type::is_editor_enabled_post( $post )
			&& self::is_managed_post( $post )
			&& current_user_can( 'edit_post', $post->ID );
	}

	/**
	 * Check whether a post is already managed by KayzArt.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private static function is_managed_post( \WP_Post $post ): bool {
		return Post_Type::POST_TYPE === $post->post_type || Post_Type::is_kayzart_enabled_post( (int) $post->ID );
	}
}
