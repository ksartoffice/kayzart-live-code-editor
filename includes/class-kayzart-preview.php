<?php
/**
 * Front-end preview handling for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles preview rendering and asset setup.
 */
class Preview {
	/**
	 * Current preview post ID.
	 *
	 * @var int|null
	 */
	private static ?int $post_id = null;

	/**
	 * Whether the current request is a preview.
	 *
	 * @var bool
	 */
	private static bool $is_preview = false;
	private const MARKER_ATTR       = 'data-kayzart-marker';
	private const MARKER_POST_ATTR  = 'data-kayzart-post-id';
	private const MARKER_START      = 'start';
	private const MARKER_END        = 'end';
	/**
	 * Register preview hooks.
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_preview' ) );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register query vars used by preview.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = 'kayzart_preview';
		$vars[] = 'kayzart_template_mode';
		$vars[] = 'post_id';
		$vars[] = 'token';
		return $vars;
	}

	/**
	 * Check whether the current request is a preview.
	 *
	 * @return bool
	 */
	private static function is_preview_request(): bool {
		return (bool) get_query_var( 'kayzart_preview' );
	}

	/**
	 * Handle preview request setup.
	 */
	public static function maybe_handle_preview(): void {
		if ( ! self::is_preview_request() ) {
			return;
		}

		$post_id = absint( get_query_var( 'post_id' ) );
		$token   = (string) get_query_var( 'token' );

		if ( ! $post_id ) {
			wp_die( esc_html__( 'post_id is required.', 'kayzart-live-code-editor' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ) );
		}

		if ( ! wp_verify_nonce( $token, 'kayzart_preview_' . $post_id ) ) {
			wp_die( esc_html__( 'Invalid preview token.', 'kayzart-live-code-editor' ) );
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			wp_die( esc_html__( 'Invalid post type.', 'kayzart-live-code-editor' ) );
		}

		if ( ! get_post( $post_id ) ) {
			wp_die( esc_html__( 'Post not found.', 'kayzart-live-code-editor' ) );
		}

		self::$post_id    = $post_id;
		self::$is_preview = true;
		if ( false === has_filter( 'wp_headers', array( __CLASS__, 'filter_preview_headers' ) ) ) {
			add_filter( 'wp_headers', array( __CLASS__, 'filter_preview_headers' ) );
		}
		add_filter( 'show_admin_bar', '__return_false' );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'disable_admin_bar_assets' ), 100 );
		remove_action( 'wp_head', '_admin_bar_bump_cb' );
		// Disable auto formatting so markers are not wrapped in <p> tags.
		if ( has_filter( 'the_content', 'wpautop' ) ) {
			remove_filter( 'the_content', 'wpautop' );
		}
		if ( has_filter( 'the_content', 'shortcode_unautop' ) ) {
			remove_filter( 'the_content', 'shortcode_unautop' );
		}

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
	}

	/**
	 * Inject security headers for preview requests.
	 *
	 * @param array $headers Existing headers.
	 * @return array
	 */
	public static function filter_preview_headers( array $headers ): array {

		if ( ! self::$is_preview ) {
			return $headers;
		}

		$admin_origin = self::build_admin_origin();
		$sources      = array( "'self'" );
		if ( '' !== $admin_origin ) {
			$sources[] = $admin_origin;
		}
		$sources = array_values( array_unique( $sources ) );

		$headers['Content-Security-Policy'] = 'frame-ancestors ' . implode( ' ', $sources );
		return $headers;
	}
	/**
	 * Remove admin bar assets and bump styles on preview requests.
	 */
	public static function disable_admin_bar_assets(): void {
		if ( ! self::$is_preview ) {
			return;
		}

		wp_dequeue_style( 'admin-bar' );
		wp_deregister_style( 'admin-bar' );
		wp_dequeue_script( 'admin-bar' );
		remove_action( 'wp_head', '_admin_bar_bump_cb' );
	}

	/**
	 * Wrap preview content in marker elements.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( string $content ): string {

		if ( ! self::$is_preview ) {
				return $content;
		}

		if ( ! self::is_root_the_content_call() ) {
			return $content;
		}

		if ( ! self::is_main_content_context() ) {
			return $content;
		}

		$current_post_id = self::resolve_current_post_id();
		if ( ! $current_post_id || self::$post_id !== $current_post_id ) {
			return $content;
		}

		if ( self::has_marker_elements( $content, self::$post_id ) ) {
			return $content;
		}

		return self::build_marker_element( self::MARKER_START, self::$post_id ) . $content . self::build_marker_element( self::MARKER_END, self::$post_id );
	}

	/**
	 * Check whether current filter context is the root the_content call.
	 *
	 * @return bool
	 */
	private static function is_root_the_content_call(): bool {

		global $wp_current_filter;
		if ( ! is_array( $wp_current_filter ) ) {
			return true;
		}

		$depth = 0;
		foreach ( $wp_current_filter as $hook_name ) {
			if ( 'the_content' === (string) $hook_name ) {
				++$depth;
			}
		}

		// Direct method calls in tests may have depth 0.
		return $depth <= 1;
	}
	/**
	 * Check whether current the_content call is for the main loop content.
	 *
	 * @return bool
	 */
	private static function is_main_content_context(): bool {
		if ( ! in_the_loop() ) {
			return false;
		}

		if ( ! is_main_query() ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the current post ID from content filter context.
	 *
	 * @return int
	 */
	private static function resolve_current_post_id(): int {
		$post_id = get_the_ID();
		if ( $post_id ) {
			return (int) $post_id;
		}

		global $post;
		if ( $post instanceof \WP_Post ) {
			return (int) $post->ID;
		}

		return 0;
	}

	/**
	 * Check whether content already contains preview marker elements.
	 *
	 * @param string $content Post content.
	 * @param int    $post_id Preview post ID.
	 * @return bool
	 */
	private static function has_marker_elements( string $content, int $post_id ): bool {

		$start_marker = self::build_marker_element( self::MARKER_START, $post_id );
		$end_marker   = self::build_marker_element( self::MARKER_END, $post_id );

		return false !== strpos( $content, $start_marker ) && false !== strpos( $content, $end_marker );
	}

	/**
	 * Build marker element HTML.
	 *
	 * @param string $type    Marker type.
	 * @param int    $post_id Preview post ID.
	 * @return string
	 */
	private static function build_marker_element( string $type, int $post_id ): string {

		return sprintf(
			'<span %s="%s" %s="%s" aria-hidden="true" hidden></span>',
			esc_attr( self::MARKER_ATTR ),
			esc_attr( $type ),
			esc_attr( self::MARKER_POST_ATTR ),
			esc_attr( (string) $post_id )
		);
	}
	/**
	 * Enqueue preview assets and payload.
	 */
	public static function enqueue_assets(): void {
		if ( ! self::$is_preview ) {
			return;
		}

		wp_enqueue_script(
			'kayzart-preview',
			KAYZART_URL . 'includes/preview.js',
			array(),
			self::preview_script_version(),
			true
		);
		$admin_origin           = self::build_admin_origin();
		$highlight_meta         = get_post_meta( self::$post_id, '_kayzart_live_highlight', true );
		$live_highlight_enabled = '' === $highlight_meta ? true : rest_sanitize_boolean( $highlight_meta );
		$payload                = array(
			'allowedOrigin'        => $admin_origin,
			'post_id'              => self::$post_id,
			'liveHighlightEnabled' => $live_highlight_enabled,
			'markers'              => array(
				'attr'     => self::MARKER_ATTR,
				'postAttr' => self::MARKER_POST_ATTR,
				'start'    => self::MARKER_START,
				'end'      => self::MARKER_END,
			),
			'restNonce'            => wp_create_nonce( 'wp_rest' ),
		);
		wp_add_inline_script(
			'kayzart-preview',
			'window.KAYZART_PREVIEW = ' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';',
			'before'
		);
	}

	/**
	 * Build admin origin for preview postMessage validation.
	 *
	 * @return string
	 */
	private static function build_admin_origin(): string {

		$admin_origin = self::build_origin_from_url( admin_url() );
		if ( '' !== $admin_origin ) {
			return $admin_origin;
		}
		return self::build_origin_from_url( home_url() );
	}

	/**
	 * Build a strict origin (scheme://host[:port]) from a URL.
	 *
	 * @param string $url URL to parse.
	 * @return string
	 */
	private static function build_origin_from_url( string $url ): string {

		$parts  = wp_parse_url( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		if ( '' === $scheme || '' === $host ) {
			return '';
		}
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$origin = $scheme . '://' . $host;
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (string) $parts['port'];
		}
		return $origin;
	}
	/**
	 * Resolve preview script version for cache busting in development.
	 *
	 * @return string
	 */
	private static function preview_script_version(): string {

		$path  = KAYZART_PATH . 'includes/preview.js';
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;
		if ( false === $mtime ) {
			return KAYZART_VERSION;
		}
		return (string) $mtime;
	}
}
