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
		add_action( 'save_post_' . Post_Type::POST_TYPE, array( __CLASS__, 'maybe_mark_setup_required' ), 10, 3 );
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

		$data = array(
			'postId'    => self::resolve_post_id(),
			'postType'  => Post_Type::POST_TYPE,
			'actionUrl' => Admin::get_action_redirect_url(),
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
	 * Mark new KayzArt posts as requiring setup.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @param bool     $update Whether this is an existing post.
	 */
	public static function maybe_mark_setup_required( int $post_id, \WP_Post $post, bool $update ): void {
		if ( $update ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( Post_Type::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( get_post_meta( $post_id, '_kayzart_setup_required', true ) === '1' ) {
			return;
		}

		update_post_meta( $post_id, '_kayzart_setup_required', '1' );
	}

	/**
	 * Resolve the current post ID for editor screens.
	 *
	 * @return int
	 */
	private static function resolve_post_id(): int {
		$post = get_post();
		if ( ! $post || Post_Type::POST_TYPE !== $post->post_type ) {
			return 0;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return 0;
		}

		return (int) $post->ID;
	}

	/**
	 * Check if the screen is for the KayzArt CPT.
	 *
	 * @param \WP_Screen|null $screen Current screen.
	 * @return bool
	 */
	private static function is_kayzart_screen( $screen ): bool {
		return $screen && Post_Type::POST_TYPE === $screen->post_type;
	}
}
