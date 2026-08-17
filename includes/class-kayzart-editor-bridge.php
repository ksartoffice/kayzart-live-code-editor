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
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect_classic_editor_to_kayzart' ), 20, 2 );
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
		$can_return = $post instanceof \WP_Post
			&& Post_Type::POST_TYPE !== $post->post_type
			&& Post_Type::is_kayzart_enabled_post( (int) $post->ID );
		$view_url   = $post instanceof \WP_Post ? get_preview_post_link( $post ) : '';
		if ( ! is_string( $view_url ) ) {
			$view_url = '';
		}

		$data = array(
			'postId'        => $post_id,
			'postType'      => $post_type,
			'supportsTitle' => post_type_supports( $post_type, 'title' ),
			'actionUrl'     => Admin::get_action_redirect_url(),
			'returnUrl'     => $can_return ? Admin::get_return_post_action_url( (int) $post->ID ) : '',
			'viewUrl'       => $view_url,
			'enabled'       => $is_managed,
			'isManaged'     => $is_managed,
			'canConvert'    => false,
			'labels'        => array(
				'edit'          => __( 'Edit with Kayzart', 'kayzart-live-code-editor' ),
				'eyebrow'       => __( 'Managed by Kayzart', 'kayzart-live-code-editor' ),
				'description'   => __( 'Edit the page content in Kayzart. You can continue to change WordPress page settings here.', 'kayzart-live-code-editor' ),
				'titleLabel'    => __( 'Page title', 'kayzart-live-code-editor' ),
				'view'          => __( 'View page', 'kayzart-live-code-editor' ),
				'return'        => __( 'Return to WordPress editor', 'kayzart-live-code-editor' ),
				'returning'     => __( 'Returning…', 'kayzart-live-code-editor' ),
				'returnConfirm' => __( 'Return this page to the WordPress editor? The current HTML will be kept, but Kayzart CSS and JavaScript will no longer be applied.', 'kayzart-live-code-editor' ),
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
	 * Register guards for core REST updates to all REST-enabled post types.
	 */
	public static function register_rest_content_guards(): void {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );
		foreach ( $post_types as $post_type ) {
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
	 * @param array $unsanitized_postarr Unsanitized post input. Added by WordPress 5.4.1.
	 * @param bool  $update              Whether this is an existing post update. Added by WordPress 6.0.
	 * @return array
	 */
	public static function preserve_classic_editor_content( array $data, array $postarr, array $unsanitized_postarr = array(), bool $update = false ): array {
		unset( $unsanitized_postarr );
		if ( func_num_args() < 4 ) {
			$update = ! empty( $postarr['ID'] );
		}

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
			$data['post_content'] = wp_slash( (string) $post->post_content );

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WordPress verifies editpost before this filter.
			$open_requested   = isset( $_POST['kayzart_open_after_save'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['kayzart_open_after_save'] ) );
			$return_requested = isset( $_POST['kayzart_return_after_save'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['kayzart_return_after_save'] ) );
			$preserved_status = isset( $_POST['kayzart_preserve_post_status'] ) ? sanitize_key( wp_unslash( $_POST['kayzart_preserve_post_status'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing
			$standard_statuses = array( 'auto-draft', 'draft', 'pending', 'publish', 'private', 'future' );
			if ( ( $open_requested || $return_requested ) && $preserved_status && ! in_array( $preserved_status, $standard_statuses, true ) && get_post_status_object( $preserved_status ) ) {
				$data['post_status'] = $preserved_status;
			}
		}

		return $data;
	}

	/**
	 * Redirect a requested Classic Editor save to the KayzArt editor.
	 *
	 * @param string $location Default post-save redirect URL.
	 * @param int    $post_id  Saved post ID.
	 * @return string
	 */
	public static function redirect_classic_editor_to_kayzart( string $location, int $post_id ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified below before using the request.
		$redirect_requested = isset( $_POST['kayzart_open_after_save'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['kayzart_open_after_save'] ) );
		$return_requested   = isset( $_POST['kayzart_return_after_save'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['kayzart_return_after_save'] ) );
		$action             = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$nonce              = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$post_id = absint( $post_id );
		if (
			( ! $redirect_requested && ! $return_requested )
			|| 'editpost' !== $action
			|| ! $post_id
			|| ! wp_verify_nonce( $nonce, 'update-post_' . $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
			|| ! Post_Type::is_editor_enabled_post( $post_id )
			|| ! Post_Type::is_kayzart_post( $post_id )
		) {
			return $location;
		}

		if ( $return_requested && Post_Type::POST_TYPE !== get_post_type( $post_id ) && Post_Type::is_kayzart_enabled_post( $post_id ) ) {
			return Admin::get_return_post_action_url( $post_id );
		}

		return add_query_arg( 'post_id', $post_id, Admin::get_action_redirect_url() );
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
