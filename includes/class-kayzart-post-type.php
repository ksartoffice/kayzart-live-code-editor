<?php
/**
 * Custom post type registration for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the KayzArt custom post type.
 */
class Post_Type {
	const POST_TYPE    = 'kayzart';
	const SLUG         = 'kayzart';
	const PAGE_TYPE    = 'page';
	const ENABLED_META = '_kayzart_enabled';

	/**
	 * Register hooks for the post type.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'add_post_states' ), 10, 2 );
		add_filter( 'get_edit_post_link', array( __CLASS__, 'filter_edit_post_link' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_kayzart_row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'add_kayzart_row_action' ), 10, 2 );
	}

	/**
	 * Activation handler.
	 */
	public static function activation(): void {
		self::register();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation handler.
	 */
	public static function deactivation(): void {
		flush_rewrite_rules();
	}

	/**
	 * Register the custom post type.
	 */
	public static function register(): void {
		$slug   = self::get_slug();
		$labels = array(
			'name'               => _x( '旧KayzArt', 'post type general name', 'kayzart-live-code-editor' ),
			'singular_name'      => _x( '旧KayzArt', 'post type singular name', 'kayzart-live-code-editor' ),
			'add_new'            => _x( 'Add New', 'kayzart', 'kayzart-live-code-editor' ),
			'add_new_item'       => __( 'Add New', 'kayzart-live-code-editor' ),
			'edit_item'          => __( 'Edit KayzArt', 'kayzart-live-code-editor' ),
			'new_item'           => __( 'New KayzArt', 'kayzart-live-code-editor' ),
			'view_item'          => __( 'View on front end', 'kayzart-live-code-editor' ),
			'view_items'         => __( 'View on front end', 'kayzart-live-code-editor' ),
			'search_items'       => __( 'Search KayzArt', 'kayzart-live-code-editor' ),
			'not_found'          => __( 'No KayzArt found', 'kayzart-live-code-editor' ),
			'not_found_in_trash' => __( 'No KayzArt found in Trash', 'kayzart-live-code-editor' ),
			'all_items'          => __( '旧KayzArt一覧', 'kayzart-live-code-editor' ),
			'archives'           => __( 'KayzArt Archives', 'kayzart-live-code-editor' ),
		);

		$args = array(
			'label'               => __( '旧KayzArt', 'kayzart-live-code-editor' ),
			'labels'              => $labels,
			'public'              => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => self::has_legacy_posts(),
			'show_in_nav_menus'   => true,
			'show_in_admin_bar'   => false,
			'has_archive'         => true,
			'rewrite'             => array(
				'slug'       => $slug,
				'with_front' => false,
			),
			'supports'            => array( 'title', 'editor', 'author', 'thumbnail' ),
			'show_in_rest'        => true,
			'capabilities'        => array(
				'create_posts' => 'do_not_allow',
			),
			'map_meta_cap'        => true,
			'menu_position'       => 21,
			'menu_icon'           => 'dashicons-editor-code',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Resolve the current slug for the KayzArt post type.
	 *
	 * @return string
	 */
	public static function get_slug(): string {
		$value = get_option( Admin::OPTION_POST_SLUG, self::SLUG );
		$slug  = sanitize_title( (string) $value );

		return '' !== $slug ? $slug : self::SLUG;
	}

	/**
	 * Check whether legacy KayzArt CPT posts exist.
	 *
	 * @return bool
	 */
	public static function has_legacy_posts(): bool {
		global $wpdb;

		$post_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> %s LIMIT 1",
				self::POST_TYPE,
				'auto-draft'
			)
		);

		return (int) $post_id > 0;
	}

	/**
	 * Check whether a post is a KayzArt post.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 * @return bool
	 */
	public static function is_kayzart_post( $post ): bool {
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}

		if ( self::POST_TYPE === $post->post_type ) {
			return true;
		}

		return self::PAGE_TYPE === $post->post_type && self::is_kayzart_page( (int) $post->ID );
	}

	/**
	 * Check whether a page was created as a KayzArt page.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_kayzart_page( int $post_id ): bool {
		return '1' === get_post_meta( $post_id, self::ENABLED_META, true );
	}

	/**
	 * Build the editor URL for a KayzArt post.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	public static function get_editor_url( int $post_id ): string {
		return add_query_arg(
			array(
				'page'     => Admin::MENU_SLUG,
				'post_id'  => $post_id,
				'_wpnonce' => wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add KayzArt labels in post lists.
	 *
	 * @param array    $states Post states.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public static function add_post_states( array $states, \WP_Post $post ): array {
		if ( ! self::is_kayzart_post( $post ) ) {
			return $states;
		}

		if ( self::PAGE_TYPE === $post->post_type ) {
			$states['kayzart_lp'] = __( 'Landing page', 'kayzart-live-code-editor' );
		}

		return $states;
	}

	/**
	 * Add a KayzArt editor link to post row actions.
	 *
	 * @param array    $actions Row actions.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public static function add_kayzart_row_action( array $actions, \WP_Post $post ): array {
		if ( ! self::is_kayzart_post( $post ) ) {
			return $actions;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$actions['kayzart_edit'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::get_editor_url( $post->ID ) ),
			esc_html__( 'Edit landing page', 'kayzart-live-code-editor' )
		);

		return $actions;
	}

	/**
	 * Override the edit link to point to the KayzArt editor on the front end.
	 *
	 * @param string $link Default edit link.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_edit_post_link( string $link, int $post_id ): string {
		if ( is_admin() ) {
			return $link;
		}

		if ( ! self::is_kayzart_post( $post_id ) ) {
			return $link;
		}

		return self::get_editor_url( $post_id );
	}
}
