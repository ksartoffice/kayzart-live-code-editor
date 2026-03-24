<?php
/**
 * Front-end rendering for KayzArt posts and shortcodes.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles front-end rendering, assets, and shortcodes.
 */
class Frontend {
	/**
	 * Shortcode instance counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $shortcode_instance = 0;

	/**
	 * Tracks which posts have already enqueued inline assets.
	 *
	 * @var array<int,bool>
	 */
	private static array $shortcode_assets_loaded = array();

	/**
	 * Tracks external script handles by URL for deduping.
	 *
	 * @var array<string,string>
	 */
	private static array $external_script_handles = array();

	/**
	 * Tracks whether the shadow runtime has been enqueued.
	 *
	 * @var bool
	 */
	private static bool $shadow_runtime_enqueued = false;

	/**
	 * Tracks shadow style render calls to generate unique handles per output.
	 *
	 * @var int
	 */
	private static int $shadow_style_render_count = 0;
	private const TEMPLATE_MODE_META_KEY          = '_kayzart_template_mode';
	private const TEMPLATE_MODE_VALUES            = array( 'default', 'standalone', 'theme' );
	private const DEFAULT_TEMPLATE_MODE_VALUES    = array( 'standalone', 'theme' );
	private const JS_MODE_META_KEY                = '_kayzart_js_mode';
	private const JS_MODE_VALUES                  = array( 'classic', 'module' );
	private const SHORTCODE_RENDER_MAX_PASSES     = 2;
	/**
	 * Register front-end hooks.
	 */
	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'maybe_disable_autop' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_single_page' ) );
		add_action( 'wp_head', array( __CLASS__, 'maybe_add_noindex' ), 1 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_single_page_from_query' ) );
		// Enqueue late so KayzArt styles can override theme styles on the front-end.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_css' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_js' ) );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'template_include', array( __CLASS__, 'maybe_override_template' ), 20 );
		add_shortcode( 'kayzart', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Check whether single page view is disabled for a post.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return bool
	 */
	private static function is_single_page_disabled( int $post_id ): bool {
		return ! Post_Type::is_single_page_enabled( $post_id );
	}

	/**
	 * Redirect single page requests when disabled.
	 */
	public static function maybe_redirect_single_page(): void {
		if ( is_admin() || get_query_var( 'kayzart_preview' ) ) {
			return;
		}

		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! self::is_single_page_disabled( $post_id ) ) {
			return;
		}

		$target = apply_filters( 'kayzart_single_page_redirect', home_url( '/' ), $post_id );
		if ( '404' === $target ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_404_template();
			exit;
		}

		if ( is_string( $target ) && '' !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * Output noindex meta when single page is disabled.
	 */
	public static function maybe_add_noindex(): void {
		if ( is_admin() || get_query_var( 'kayzart_preview' ) ) {
			return;
		}

		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! self::is_single_page_disabled( $post_id ) ) {
			return;
		}

		echo '<meta name="robots" content="noindex">' . PHP_EOL;
	}

	/**
	 * Exclude single-page-disabled posts from search and archives.
	 *
	 * @param \WP_Query $query Query instance.
	 */
	public static function exclude_single_page_from_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}

		$post_type     = $query->get( 'post_type' );
		$should_filter = false;

		if ( $query->is_search() ) {
			$should_filter = true;
		} elseif ( 'any' === $post_type ) {
			$should_filter = true;
		} elseif ( is_array( $post_type ) ) {
			$should_filter = in_array( Post_Type::POST_TYPE, $post_type, true );
		} elseif ( is_string( $post_type ) ) {
			$should_filter = Post_Type::POST_TYPE === $post_type;
		}

		if ( ! $should_filter ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}

		$meta_query[] = array(
			'relation' => 'OR',
			array(
				'key'     => '_kayzart_single_page_enabled',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_kayzart_single_page_enabled',
				'value'   => '1',
				'compare' => '=',
			),
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Prevent WordPress auto-formatting from injecting <p> tags on the front-end.
	 */
	public static function maybe_disable_autop(): void {
		if ( is_admin() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return;
		}

		if ( has_filter( 'the_content', 'wpautop' ) ) {
			remove_filter( 'the_content', 'wpautop' );
		}
		if ( has_filter( 'the_content', 'shortcode_unautop' ) ) {
			remove_filter( 'the_content', 'shortcode_unautop' );
		}
	}

	/**
	 * Check whether Shadow DOM rendering is enabled for a post.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return bool
	 */
	private static function is_shadow_dom_enabled( int $post_id ): bool {
		return '1' === get_post_meta( $post_id, '_kayzart_shadow_dom', true );
	}

	/**
	 * Resolve JavaScript execution mode for a post.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function get_js_mode_for_post( int $post_id ): string {
		$mode = strtolower( trim( (string) get_post_meta( $post_id, self::JS_MODE_META_KEY, true ) ) );
		if ( 'module' === $mode ) {
			return 'module';
		}
		if ( 'classic' === $mode || 'auto' === $mode ) {
			return 'classic';
		}
		return 'classic';
	}

	/**
	 * Normalize template mode string.
	 *
	 * @param mixed $value Template mode value.
	 * @return string
	 */
	private static function normalize_template_mode( $value ): string {
		$template_mode = is_string( $value ) ? $value : '';
		return in_array( $template_mode, self::TEMPLATE_MODE_VALUES, true ) ? $template_mode : 'default';
	}

	/**
	 * Resolve default template mode from options.
	 *
	 * @return string
	 */
	private static function resolve_default_template_mode(): string {
		$template_mode = get_option( Admin::OPTION_DEFAULT_TEMPLATE_MODE, 'theme' );
		$template_mode = Admin::sanitize_default_template_mode( $template_mode );
		return in_array( $template_mode, self::DEFAULT_TEMPLATE_MODE_VALUES, true ) ? $template_mode : 'theme';
	}

	/**
	 * Resolve template mode for the current request.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function resolve_template_mode( int $post_id ): string {
		$template_mode = self::normalize_template_mode( get_post_meta( $post_id, self::TEMPLATE_MODE_META_KEY, true ) );

		if ( get_query_var( 'kayzart_preview' ) ) {
			$override = self::normalize_template_mode( get_query_var( 'kayzart_template_mode' ) );
			if ( 'default' !== $override ) {
				$template_mode = $override;
			}
		}

		if ( 'default' === $template_mode ) {
			$template_mode = self::resolve_default_template_mode();
		}

		return $template_mode;
	}

	/**
	 * Override single template based on KayzArt template mode.
	 *
	 * @param string $template Template path.
	 * @return string
	 */
	public static function maybe_override_template( string $template ): string {
		if ( is_admin() ) {
			return $template;
		}

		if ( ! is_singular( Post_Type::POST_TYPE ) ) {
			return $template;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $template;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return $template;
		}

		$template_mode = self::resolve_template_mode( $post_id );
		if ( 'theme' === $template_mode ) {
			return $template;
		}

		$path = '';
		if ( 'standalone' === $template_mode ) {
			$path = KAYZART_PATH . 'templates/single-kayzart-standalone.php';
		}

		if ( $path && file_exists( $path ) ) {
			return $path;
		}

		return $template;
	}

	/**
	 * Resolve CSS for a post, handling Tailwind compilation where needed.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function get_css_for_post( int $post_id ): string {

		$is_tailwind   = '1' === get_post_meta( $post_id, '_kayzart_tailwind', true );
		$stored_css    = (string) get_post_meta( $post_id, '_kayzart_css', true );
		$generated_css = (string) get_post_meta( $post_id, '_kayzart_generated_css', true );
		$css           = $is_tailwind ? $generated_css : $stored_css;

		if ( $is_tailwind ) {
			$css = Rest_Save::append_tailwind_shadow_fallbacks( $css );
		}

		$has_unescaped_arbitrary = ! $is_tailwind
			&& '' !== $stored_css
			&& false !== strpos( $stored_css, '-[' )
			&& false === strpos( $stored_css, '-\\[' );
		$should_compile          = ! $is_tailwind && $has_unescaped_arbitrary;

		if ( $should_compile ) {
			$post = get_post( $post_id );
			if ( $post instanceof \WP_Post ) {
				try {
					$css = tw::generate(
						array(
							'content' => (string) $post->post_content,
							'css'     => '@import "tailwindcss";',
						)
					);
				} catch ( \Throwable $e ) {
					$css = $stored_css;
				}
			}
		}

		return $css;
	}

	/**
	 * Sanitize CSS before inline style output.
	 *
	 * @param string $css CSS output.
	 * @return string
	 */
	private static function sanitize_inline_style_css( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		$css = wp_strip_all_tags( $css, false );
		return self::escape_style_tag( $css );
	}

	/**
	 * Escape closing style tags to prevent tag injection.
	 *
	 * @param string $css CSS output.
	 * @return string
	 */
	private static function escape_style_tag( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		return str_ireplace( '</style', '&lt;/style', $css );
	}

	/**
	 * Filter KayzArt post content for Shadow DOM preview.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( string $content ): string {
		if ( is_admin() ) {
			return $content;
		}
		if ( get_query_var( 'kayzart_preview' ) ) {
			return $content;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return $content;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return $content;
		}

		if ( ! self::is_shadow_dom_enabled( $post_id ) ) {
			$script_html = self::build_inline_script_payload( $post_id );
			return $content . $script_html;
		}

		$style_html  = self::render_shadow_styles_html( $post_id );
		$script_html = self::build_inline_script_payload( $post_id );
		return '<kayzart-output data-post-id="' . esc_attr( $post_id ) . '"><template shadowrootmode="open">' . $style_html . $content . '</template>' . $script_html . '</kayzart-output>';
	}

	/**
	 * Render the [kayzart] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {

		$atts    = shortcode_atts(
			array(
				'post_id' => 0,
			),
			(array) $atts,
			'kayzart'
		);
		$post_id = absint( $atts['post_id'] ?? 0 );
		if ( ! $post_id ) {
			return '';
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return '';
		}

		if ( '1' !== get_post_meta( $post_id, '_kayzart_shortcode_enabled', true ) ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		if ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) {
			return '';
		}
		if ( post_password_required( $post ) ) {
			return '';
		}

		$content = (string) $post->post_content;
		$content = self::render_allowed_embed_shortcodes( $content );
		if ( self::is_shadow_dom_enabled( $post_id ) ) {
			++self::$shortcode_instance;
			$instance    = self::$shortcode_instance;
			$style_html  = self::render_shadow_styles_html( $post_id, $instance );
			$script_html = self::build_inline_script_payload( $post_id, $instance );
			self::enqueue_shortcode_scripts( $post_id );
			return '<kayzart-output data-post-id="' . esc_attr( $post_id ) . '"><template shadowrootmode="open">' . $style_html . $content . '</template>' . $script_html . '</kayzart-output>';
		}

		$style_html = self::prepare_non_shadow_shortcode_assets( $post_id );
		return $style_html . $content . self::build_inline_script_payload( $post_id );
	}

	/**
	 * Resolve shortcode allowlist configured in admin settings.
	 *
	 * @return array<int,string>
	 */
	private static function get_shortcode_allowlist(): array {

		$raw = get_option( Admin::OPTION_SHORTCODE_ALLOWLIST, '' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$entries    = explode( "\n", $normalized );
		$unique     = array();

		foreach ( $entries as $entry ) {
			$tag = sanitize_key( trim( $entry ) );
			if ( '' === $tag ) {
					continue;
			}
			$unique[ $tag ] = true;
		}

		return array_keys( $unique );
	}

	/**
	 * Render only allowlisted shortcodes for [kayzart] embed content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private static function render_allowed_embed_shortcodes( string $content ): string {

		if ( '' === $content ) {
			return '';
		}

		$allowlist = self::get_shortcode_allowlist();
		if ( empty( $allowlist ) ) {
			return $content;
		}

		$allowed_tags = array_fill_keys( $allowlist, true );

		$rendered = $content;
		$filter   = static function ( $output, $tag, $attr, $m ) use ( $allowed_tags ) {
			unset( $attr );
			if ( isset( $allowed_tags[ $tag ] ) ) {
				return $output;
			}

			return isset( $m[0] ) ? (string) $m[0] : $output;
		};
		add_filter( 'pre_do_shortcode_tag', $filter, 10, 4 );

		try {
			for ( $pass = 0; $pass < self::SHORTCODE_RENDER_MAX_PASSES; $pass++ ) {
				$previous = $rendered;
				$rendered = do_shortcode( $previous );
				if ( $rendered === $previous ) {
					break;
				}
			}
		} finally {
			remove_filter( 'pre_do_shortcode_tag', $filter, 10 );
		}

		return $rendered;
	}
	/**
	 * Build stylesheet HTML for Shadow DOM rendering via WordPress style APIs.
	 *
	 * @param int $post_id  KayzArt post ID.
	 * @param int $instance Instance number.
	 * @return string
	 */
	private static function render_shadow_styles_html( int $post_id, int $instance = 0 ): string {
		$css             = self::get_css_for_post( $post_id );
		$external_styles = External_Styles::get_external_styles( $post_id );
		if ( '' === $css && empty( $external_styles ) ) {
			return '';
		}

		++self::$shadow_style_render_count;
		$suffix       = 0 < $instance ? '-' . $post_id . '-' . $instance : '-' . $post_id;
		$handle_scope = $suffix . '-' . self::$shadow_style_render_count;

		$dependency = '';
		$handles    = array();
		foreach ( $external_styles as $index => $style_url ) {
			$ext_handle = 'kayzart-shadow-ext-style' . $handle_scope . '-' . $index;
			$ext_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_style_is( $ext_handle, 'registered' ) ) {
				wp_register_style( $ext_handle, $style_url, $ext_deps, KAYZART_VERSION );
			}
			wp_enqueue_style( $ext_handle );
			$handles[]  = $ext_handle;
			$dependency = $ext_handle;
		}

		if ( '' !== $css ) {
			$inline_handle = 'kayzart-shadow-style' . $handle_scope;
			$inline_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_style_is( $inline_handle, 'registered' ) ) {
				wp_register_style( $inline_handle, false, $inline_deps, KAYZART_VERSION );
			}
			wp_enqueue_style( $inline_handle );
			wp_add_inline_style( $inline_handle, self::sanitize_inline_style_css( $css ) );
			$handles[] = $inline_handle;
		}

		return self::render_styles_html_from_handles( $handles );
	}

	/**
	 * Build stylesheet HTML for non-shadow shortcode rendering.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function render_non_shadow_shortcode_styles_html( int $post_id ): string {
		$css             = self::get_css_for_post( $post_id );
		$external_styles = External_Styles::get_external_styles( $post_id );
		if ( '' === $css && empty( $external_styles ) ) {
			return '';
		}

		$dependency = '';
		$handles    = array();
		foreach ( $external_styles as $index => $style_url ) {
			$ext_handle = 'kayzart-shortcode-ext-style-' . $post_id . '-' . $index;
			$ext_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_style_is( $ext_handle, 'registered' ) ) {
				wp_register_style( $ext_handle, $style_url, $ext_deps, KAYZART_VERSION );
			}
			wp_enqueue_style( $ext_handle );
			$handles[]  = $ext_handle;
			$dependency = $ext_handle;
		}

		if ( '' !== $css ) {
			$inline_handle = 'kayzart-shortcode-style-' . $post_id;
			$inline_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_style_is( $inline_handle, 'registered' ) ) {
				wp_register_style( $inline_handle, false, $inline_deps, KAYZART_VERSION );
			}
			wp_enqueue_style( $inline_handle );
			wp_add_inline_style( $inline_handle, self::sanitize_inline_style_css( $css ) );
			$handles[] = $inline_handle;
		}

		return self::render_styles_html_from_handles( $handles );
	}

	/**
	 * Render selected enqueued style handles into HTML.
	 *
	 * @param array<int,string> $handles Style handles.
	 * @return string
	 */
	private static function render_styles_html_from_handles( array $handles ): string {
		if ( empty( $handles ) ) {
			return '';
		}

		ob_start();
		wp_print_styles( $handles );
		return (string) ob_get_clean();
	}

	/**
	 * Build inline script payload for runtime execution.
	 *
	 * @param int $post_id  KayzArt post ID.
	 * @param int $instance Instance number.
	 * @return string
	 */
	private static function build_inline_script_payload( int $post_id, int $instance = 0 ): string {
		$js = (string) get_post_meta( $post_id, '_kayzart_js', true );
		if ( '' === $js ) {
			return '';
		}

		$external_scripts = External_Scripts::get_external_scripts( $post_id );
		$wait_attr        = empty( $external_scripts ) ? '' : ' data-kayzart-js-wait="load"';
		$mode_attr        = ' data-kayzart-js-mode="' . esc_attr( self::get_js_mode_for_post( $post_id ) ) . '"';
		$suffix           = 0 < $instance ? '-' . $post_id . '-' . $instance : '-' . $post_id;
		$encoded          = rawurlencode( $js );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		return '<script type="application/json" id="kayzart-script-data' . esc_attr( $suffix ) . '" data-kayzart-js="1"' . $mode_attr . $wait_attr . '>' . esc_html( $encoded ) . '</script>';
	}

	/**
	 * Enqueue non-shadow shortcode assets once per post and return style HTML.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function prepare_non_shadow_shortcode_assets( int $post_id ): string {
		if ( isset( self::$shortcode_assets_loaded[ $post_id ] ) ) {
			return '';
		}
		self::$shortcode_assets_loaded[ $post_id ] = true;

		$style_html = self::render_non_shadow_shortcode_styles_html( $post_id );
		self::enqueue_non_shadow_shortcode_scripts( $post_id );
		return $style_html;
	}

	/**
	 * Enqueue scripts for non-shadow shortcode rendering.
	 *
	 * @param int $post_id KayzArt post ID.
	 */
	private static function enqueue_non_shadow_shortcode_scripts( int $post_id ): void {
		$js               = (string) get_post_meta( $post_id, '_kayzart_js', true );
		$external_scripts = External_Scripts::get_external_scripts( $post_id );
		if ( '' === $js && empty( $external_scripts ) ) {
			return;
		}

		self::enqueue_external_scripts( $post_id );
		if ( '' !== $js ) {
			self::enqueue_shadow_runtime();
		}
	}

	/**
	 * Enqueue external scripts for shadow-dom shortcode rendering.
	 *
	 * @param int $post_id KayzArt post ID.
	 */
	private static function enqueue_shortcode_scripts( int $post_id ): void {
		if ( isset( self::$shortcode_assets_loaded[ $post_id ] ) ) {
			return;
		}
		self::$shortcode_assets_loaded[ $post_id ] = true;

		$js = (string) get_post_meta( $post_id, '_kayzart_js', true );
		if ( '' !== $js ) {
			self::enqueue_shadow_runtime();
		}

		$external_scripts = External_Scripts::get_external_scripts( $post_id );
		if ( empty( $external_scripts ) ) {
			return;
		}

		self::enqueue_external_scripts( $post_id );
	}

	/**
	 * Enqueue external script URLs once per page.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string Last dependency handle.
	 */
	private static function enqueue_external_scripts( int $post_id ): string {
		$external_scripts = External_Scripts::get_external_scripts( $post_id );
		if ( empty( $external_scripts ) ) {
			return '';
		}

		$dependency = '';
		foreach ( $external_scripts as $index => $script_url ) {
			if ( isset( self::$external_script_handles[ $script_url ] ) ) {
				$dependency = self::$external_script_handles[ $script_url ];
				continue;
			}
			$ext_handle = 'kayzart-ext-' . $post_id . '-' . $index;
			$ext_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_script_is( $ext_handle, 'registered' ) ) {
				wp_register_script( $ext_handle, $script_url, $ext_deps, KAYZART_VERSION, true );
			}
			wp_enqueue_script( $ext_handle );
			self::$external_script_handles[ $script_url ] = $ext_handle;
			$dependency                                   = $ext_handle;
		}

		return $dependency;
	}

	/**
	 * Enqueue runtime script for executing Shadow DOM inline JS payloads.
	 */
	private static function enqueue_shadow_runtime(): void {
		if ( self::$shadow_runtime_enqueued ) {
			return;
		}
		self::$shadow_runtime_enqueued = true;

		$handle = 'kayzart-shadow-runtime';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				KAYZART_URL . 'includes/shadow-runtime.js',
				array(),
				KAYZART_VERSION,
				true
			);
		}
		wp_enqueue_script( $handle );
	}

	/**
	 * Enqueue CSS assets for non-shadow front-end rendering.
	 */
	public static function enqueue_css(): void {
		if ( is_admin() ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return;
		}

		if ( self::is_shadow_dom_enabled( $post_id ) ) {
			return;
		}

		$css             = self::get_css_for_post( $post_id );
		$external_styles = External_Styles::get_external_styles( $post_id );
		if ( '' === $css && empty( $external_styles ) ) {
			return;
		}

		$dependency = '';
		foreach ( $external_styles as $index => $style_url ) {
			$ext_handle = 'kayzart-ext-style-' . $post_id . '-' . $index;
			$ext_deps   = $dependency ? array( $dependency ) : array();
			if ( ! wp_style_is( $ext_handle, 'registered' ) ) {
				wp_register_style( $ext_handle, $style_url, $ext_deps, KAYZART_VERSION );
			}
			wp_enqueue_style( $ext_handle );
			$dependency = $ext_handle;
		}

		if ( '' === $css ) {
			return;
		}

		$handle = 'kayzart';
		$deps   = $dependency ? array( $dependency ) : array();

		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, $deps, KAYZART_VERSION );
		}

		wp_enqueue_style( $handle );
		wp_add_inline_style( $handle, self::sanitize_inline_style_css( $css ) );
	}

	/**
	 * Enqueue JS assets for non-shadow front-end rendering.
	 */
	public static function enqueue_js(): void {
		if ( is_admin() ) {
			return;
		}
		if ( get_query_var( 'kayzart_preview' ) ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return;
		}

		$js               = (string) get_post_meta( $post_id, '_kayzart_js', true );
		$external_scripts = External_Scripts::get_external_scripts( $post_id );
		if ( '' === $js && empty( $external_scripts ) ) {
			return;
		}

		self::enqueue_external_scripts( $post_id );
		if ( '' !== $js ) {
			self::enqueue_shadow_runtime();
		}
	}
}
