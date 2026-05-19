<?php
/**
 * Front-end rendering for KayzArt posts.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles front-end rendering and assets.
 */
class Frontend {
	/**
	 * Tracks external script handles by URL for deduping.
	 *
	 * @var array<string,string>
	 */
	private static array $external_script_handles = array();

	/**
	 * Tracks whether the runtime has been enqueued.
	 *
	 * @var bool
	 */
	private static bool $runtime_enqueued      = false;
	private const TEMPLATE_MODE_META_KEY       = '_kayzart_template_mode';
	private const TEMPLATE_MODE_VALUES         = array( 'default', 'standalone', 'theme' );
	private const DEFAULT_TEMPLATE_MODE_VALUES = array( 'standalone', 'theme' );
	private const JS_MODE_META_KEY             = '_kayzart_js_mode';
	private const WP_GLOBAL_STYLES_HANDLE      = 'global-styles';
	/**
	 * Register front-end hooks.
	 */
	public static function init(): void {
		add_action( 'wp', array( __CLASS__, 'maybe_disable_autop' ) );
		// Enqueue late so KayzArt styles can override theme styles on the front-end.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_css' ), 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_js' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_theme_assets_for_standalone' ), 9999 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'template_include', array( __CLASS__, 'maybe_override_template' ), 20 );
		add_shortcode( 'kayzart', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Check whether the current request is a singular KayzArt-managed post.
	 *
	 * @return bool
	 */
	private static function is_kayzart_singular(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post_id = get_queried_object_id();
		return $post_id > 0 && Post_Type::is_kayzart_post( $post_id );
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
		$template_mode = get_option( Admin::OPTION_DEFAULT_TEMPLATE_MODE, 'standalone' );
		$template_mode = Admin::sanitize_default_template_mode( $template_mode );
		return in_array( $template_mode, self::DEFAULT_TEMPLATE_MODE_VALUES, true ) ? $template_mode : 'standalone';
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
	 * Check whether the current KayzArt request resolves to standalone mode.
	 *
	 * @param int|null $post_id KayzArt post ID. Defaults to queried object ID.
	 * @return bool
	 */
	public static function is_standalone_mode( ?int $post_id = null ): bool {
		if ( is_admin() ) {
			return false;
		}

		if ( null === $post_id ) {
			if ( ! self::is_kayzart_singular() ) {
				return false;
			}

			$post_id = get_queried_object_id();
		}

		if ( ! $post_id ) {
			return false;
		}

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return false;
		}

		return 'standalone' === self::resolve_template_mode( $post_id );
	}

	/**
	 * Remove active theme assets from standalone mode while leaving plugin assets intact.
	 */
	public static function dequeue_theme_assets_for_standalone(): void {
		if ( ! self::is_standalone_mode() ) {
			return;
		}

		if ( ! apply_filters( 'kayzart_standalone_dequeue_theme_assets', true ) ) {
			return;
		}

		if ( apply_filters( 'kayzart_standalone_dequeue_theme_styles', true ) ) {
			self::dequeue_theme_styles_for_standalone();
			self::dequeue_core_global_styles_for_standalone();
		}

		if ( apply_filters( 'kayzart_standalone_dequeue_theme_scripts', true ) ) {
			self::dequeue_theme_scripts_for_standalone();
		}
	}

	/**
	 * Remove active theme styles from the current queue.
	 */
	private static function dequeue_theme_styles_for_standalone(): void {
		$wp_styles = wp_styles();
		if ( ! $wp_styles ) {
			return;
		}

		foreach ( (array) $wp_styles->queue as $handle ) {
			$style = $wp_styles->registered[ $handle ] ?? null;
			if ( ! $style || ! self::is_theme_asset_src( $style->src ) ) {
				continue;
			}

			wp_dequeue_style( $handle );
		}
	}

	/**
	 * Remove WordPress core global styles from standalone mode.
	 */
	private static function dequeue_core_global_styles_for_standalone(): void {
		wp_dequeue_style( self::WP_GLOBAL_STYLES_HANDLE );
	}

	/**
	 * Remove active theme scripts from the current queue.
	 */
	private static function dequeue_theme_scripts_for_standalone(): void {
		$wp_scripts = wp_scripts();
		if ( ! $wp_scripts ) {
			return;
		}

		foreach ( (array) $wp_scripts->queue as $handle ) {
			$script = $wp_scripts->registered[ $handle ] ?? null;
			if ( ! $script || ! self::is_theme_asset_src( $script->src ) ) {
				continue;
			}

			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Check whether an asset source points inside the active parent or child theme.
	 *
	 * @param mixed $src Asset source.
	 * @return bool
	 */
	private static function is_theme_asset_src( $src ): bool {
		if ( ! is_string( $src ) || '' === $src ) {
			return false;
		}

		$theme_urls = array_unique(
			array_filter(
				array(
					get_template_directory_uri(),
					get_stylesheet_directory_uri(),
				)
			)
		);

		foreach ( $theme_urls as $theme_url ) {
			if ( self::asset_src_matches_base_url( $src, $theme_url ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an asset source is within a base URL.
	 *
	 * @param string $src      Asset source.
	 * @param string $base_url Base URL.
	 * @return bool
	 */
	private static function asset_src_matches_base_url( string $src, string $base_url ): bool {
		$src_without_query  = strtok( $src, '?#' );
		$base_without_query = strtok( $base_url, '?#' );

		if ( is_string( $src_without_query ) && is_string( $base_without_query ) ) {
			$base = trailingslashit( $base_without_query );
			if ( str_starts_with( $src_without_query, $base ) ) {
				return true;
			}
		}

		$src_path  = wp_parse_url( $src, PHP_URL_PATH );
		$base_path = wp_parse_url( $base_url, PHP_URL_PATH );
		if ( ! is_string( $src_path ) || ! is_string( $base_path ) ) {
			return false;
		}

		return str_starts_with( $src_path, trailingslashit( $base_path ) );
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

		if ( ! self::is_kayzart_singular() ) {
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
	 * Resolve CSS for a post.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return string
	 */
	private static function get_css_for_post( int $post_id ): string {
		return (string) get_post_meta( $post_id, '_kayzart_css', true );
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
	 * Append inline JavaScript payload for KayzArt post content.
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

		$script_html = self::build_inline_script_payload( $post_id );
		return $content . $script_html;
	}

	/**
	 * Keep the legacy [kayzart] shortcode from leaking as plain text.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function shortcode( $atts = array() ): string {
		unset( $atts );
		return '';
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
	 * Enqueue runtime script for executing inline JS payloads.
	 */
	private static function enqueue_runtime(): void {
		if ( self::$runtime_enqueued ) {
			return;
		}
		self::$runtime_enqueued = true;

		$handle = 'kayzart-runtime';
		if ( ! wp_script_is( $handle, 'registered' ) ) {
			wp_register_script(
				$handle,
				KAYZART_URL . 'includes/runtime.js',
				array(),
				KAYZART_VERSION,
				true
			);
		}
		wp_enqueue_script( $handle );
	}

	/**
	 * Enqueue CSS assets for front-end rendering.
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
	 * Enqueue JS assets for front-end rendering.
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
			self::enqueue_runtime();
		}
	}
}
