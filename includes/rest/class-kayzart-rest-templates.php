<?php
/**
 * REST handler for Kayzart template catalog.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for template catalog workflows.
 */
class Rest_Templates {
	private const DEFAULT_CATALOG_URL = 'https://templates.kayzart.com/v1/catalog.json';
	private const CACHE_TTL           = HOUR_IN_SECONDS;
	private const THEME_GROUPS        = array( 'colors', 'radius', 'spacing' );

	/**
	 * Fetch and return the remote template catalog.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_catalog( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$catalog = self::load_catalog();
		if ( is_wp_error( $catalog ) ) {
			return self::error_response( $catalog->get_error_message(), 502 );
		}

		return new \WP_REST_Response( $catalog, 200 );
	}

	/**
	 * Apply a free template to the current setup post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function apply_template( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id     = absint( $request->get_param( 'post_id' ) );
		$template_id = sanitize_key( (string) $request->get_param( 'template_id' ) );

		if ( '' === $template_id ) {
			return self::error_response( __( 'Invalid template.', 'kayzart-live-code-editor' ), 400 );
		}

		$setup_required = '1' === get_post_meta( $post_id, '_kayzart_setup_required', true );
		$tailwind_locked = '1' === get_post_meta( $post_id, '_kayzart_tailwind_locked', true );
		if ( ! $setup_required || $tailwind_locked ) {
			return self::error_response( __( 'Templates can only be applied during setup.', 'kayzart-live-code-editor' ), 400 );
		}

		$catalog = self::load_catalog();
		if ( is_wp_error( $catalog ) ) {
			return self::error_response( $catalog->get_error_message(), 502 );
		}

		$summary = self::find_applicable_template( $catalog, $template_id );
		if ( null === $summary ) {
			return self::error_response( __( 'Template is not available.', 'kayzart-live-code-editor' ), 403 );
		}

		$detail = self::load_template_detail( $template_id );
		if ( is_wp_error( $detail ) ) {
			return self::error_response( $detail->get_error_message(), 502 );
		}

		$prepared = self::prepare_template_detail( $detail, $summary );
		if ( is_wp_error( $prepared ) ) {
			return self::error_response( $prepared->get_error_message(), 400 );
		}

		$html = $prepared['html'];
		$css  = self::build_theme_css( $prepared['theme'] );

		try {
			$compiled_css = tw::generate(
				array(
					'content' => $html,
					'css'     => $css,
				)
			);
		} catch ( \Throwable $e ) {
			return self::error_response(
				sprintf(
					/* translators: %s: error message. */
					__( 'Tailwind compile failed: %s', 'kayzart-live-code-editor' ),
					$e->getMessage()
				),
				500
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $html ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return self::error_response( $result->get_error_message(), 400 );
		}

		update_post_meta( $post_id, '_kayzart_css', wp_slash( $css ) );
		update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $compiled_css ) );
		update_post_meta( $post_id, '_kayzart_tailwind', '1' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		delete_post_meta( $post_id, '_kayzart_setup_required' );

		return new \WP_REST_Response(
			array(
				'ok'              => true,
				'tailwindEnabled' => true,
				'html'            => $html,
				'css'             => $css,
			),
			200
		);
	}

	/**
	 * Sanitize a decoded catalog response.
	 *
	 * @param array $data Decoded JSON response.
	 * @return array
	 */
	public static function sanitize_catalog( array $data ): array {
		$templates = isset( $data['templates'] ) && is_array( $data['templates'] ) ? $data['templates'] : array();
		$sanitized = array();

		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}

			$item = self::sanitize_template_summary( $template );
			if ( null !== $item ) {
				$sanitized[] = $item;
			}
		}

		return array(
			'ok'        => true,
			'templates' => $sanitized,
		);
	}

	/**
	 * Load the remote catalog with transient caching.
	 *
	 * @return array|\WP_Error
	 */
	private static function load_catalog() {
		$catalog_url = self::get_catalog_url();
		$cache_key   = self::get_cache_key( $catalog_url );
		$cached      = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$data = self::remote_get_json( $catalog_url, __( 'Failed to load templates.', 'kayzart-live-code-editor' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$catalog = self::sanitize_catalog( $data );
		set_transient( $cache_key, $catalog, self::CACHE_TTL );

		return $catalog;
	}

	/**
	 * Load template detail from the same origin as the catalog.
	 *
	 * @param string $template_id Template ID.
	 * @return array|\WP_Error
	 */
	private static function load_template_detail( string $template_id ) {
		$url = self::build_template_detail_url( $template_id );
		if ( '' === $url ) {
			return new \WP_Error( 'kayzart_template_detail_url_invalid', __( 'Template detail URL is invalid.', 'kayzart-live-code-editor' ) );
		}

		return self::remote_get_json( $url, __( 'Failed to load template.', 'kayzart-live-code-editor' ) );
	}

	/**
	 * GET JSON from a remote URL.
	 *
	 * @param string $url           Remote URL.
	 * @param string $error_message Error message.
	 * @return array|\WP_Error
	 */
	private static function remote_get_json( string $url, string $error_message ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'kayzart_template_remote_failed', $error_message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return new \WP_Error( 'kayzart_template_remote_status', $error_message );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'kayzart_template_remote_invalid_json', __( 'Template response is invalid.', 'kayzart-live-code-editor' ) );
		}

		return $data;
	}

	/**
	 * Get the remote catalog URL.
	 *
	 * @return string
	 */
	private static function get_catalog_url(): string {
		$url = (string) apply_filters( 'kayzart_template_catalog_url', self::DEFAULT_CATALOG_URL );
		$url = esc_url_raw( $url );

		return '' !== $url ? $url : self::DEFAULT_CATALOG_URL;
	}

	/**
	 * Build a transient cache key for a catalog URL.
	 *
	 * @param string $catalog_url Catalog URL.
	 * @return string
	 */
	private static function get_cache_key( string $catalog_url ): string {
		return 'kayzart_template_catalog_' . md5( $catalog_url );
	}

	/**
	 * Build a free template detail URL.
	 *
	 * @param string $template_id Template ID.
	 * @return string
	 */
	private static function build_template_detail_url( string $template_id ): string {
		$catalog_url = self::get_catalog_url();
		$parts       = wp_parse_url( $catalog_url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$base = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$base .= ':' . (int) $parts['port'];
		}

		return esc_url_raw( $base . '/v1/free/' . rawurlencode( $template_id ) . '.json' );
	}

	/**
	 * Find a free and available template summary.
	 *
	 * @param array  $catalog     Sanitized catalog.
	 * @param string $template_id Template ID.
	 * @return array|null
	 */
	private static function find_applicable_template( array $catalog, string $template_id ): ?array {
		$templates = isset( $catalog['templates'] ) && is_array( $catalog['templates'] ) ? $catalog['templates'] : array();

		foreach ( $templates as $template ) {
			if (
				is_array( $template )
				&& $template_id === ( $template['id'] ?? '' )
				&& 'free' === ( $template['tier'] ?? '' )
				&& true === ( $template['available'] ?? false )
			) {
				return $template;
			}
		}

		return null;
	}

	/**
	 * Validate and prepare a template detail response.
	 *
	 * @param array $detail  Raw detail response.
	 * @param array $summary Sanitized catalog summary.
	 * @return array|\WP_Error
	 */
	private static function prepare_template_detail( array $detail, array $summary ) {
		$id = isset( $detail['id'] ) ? sanitize_key( (string) $detail['id'] ) : '';
		if ( '' === $id || $id !== ( $summary['id'] ?? '' ) ) {
			return new \WP_Error( 'kayzart_template_id_mismatch', __( 'Template response is invalid.', 'kayzart-live-code-editor' ) );
		}

		$market = isset( $detail['market'] ) ? sanitize_key( (string) $detail['market'] ) : '';
		if ( $market !== ( $summary['market'] ?? '' ) ) {
			return new \WP_Error( 'kayzart_template_market_mismatch', __( 'Template response is invalid.', 'kayzart-live-code-editor' ) );
		}

		$html = isset( $detail['html'] ) ? (string) $detail['html'] : '';
		if ( '' === trim( $html ) ) {
			return new \WP_Error( 'kayzart_template_html_empty', __( 'Template HTML is empty.', 'kayzart-live-code-editor' ) );
		}

		$html_validation = self::validate_free_template_html( $html );
		if ( is_wp_error( $html_validation ) ) {
			return $html_validation;
		}

		$theme = self::sanitize_theme_tokens( $detail['theme'] ?? array() );
		if ( is_wp_error( $theme ) ) {
			return $theme;
		}

		$checksum = isset( $detail['checksum'] ) ? sanitize_text_field( (string) $detail['checksum'] ) : '';
		if ( '' !== $checksum ) {
			// Verify against the original published payload. The publishing pipeline
			// computes sha256 over a stable (recursively key-sorted) JSON encoding of
			// { html: html.trim(), theme }, so the checksum must be checked against the
			// raw detail values, not the sanitized theme.
			$canonical = wp_json_encode(
				self::stable_sort(
					array(
						'html'  => trim( (string) ( $detail['html'] ?? '' ) ),
						'theme' => isset( $detail['theme'] ) ? $detail['theme'] : array(),
					)
				),
				JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
			if ( false === $canonical ) {
				return new \WP_Error( 'kayzart_template_checksum_invalid', __( 'Template checksum is invalid.', 'kayzart-live-code-editor' ) );
			}
			$expected = 'sha256:' . hash( 'sha256', $canonical );
			if ( ! hash_equals( $expected, $checksum ) ) {
				return new \WP_Error( 'kayzart_template_checksum_mismatch', __( 'Template checksum is invalid.', 'kayzart-live-code-editor' ) );
			}
		}

		return array(
			'html'  => $html,
			'theme' => $theme,
		);
	}

	/**
	 * Recursively key-sort a value to mirror the publishing pipeline's stable JSON.
	 *
	 * Associative arrays (and empty arrays) are emitted as JSON objects with their
	 * keys sorted; lists keep their order. Combined with
	 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE this matches JavaScript's
	 * stable JSON.stringify output used to generate template checksums.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function stable_sort( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array() === $value ) {
			return new \stdClass();
		}

		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( $is_list ) {
			return array_map( array( __CLASS__, 'stable_sort' ), $value );
		}

		ksort( $value );
		$result = array();
		foreach ( $value as $key => $item ) {
			$result[ $key ] = self::stable_sort( $item );
		}

		return (object) $result;
	}

	/**
	 * Validate free template HTML.
	 *
	 * @param string $html Template HTML.
	 * @return true|\WP_Error
	 */
	private static function validate_free_template_html( string $html ) {
		$forbidden_tags = array( 'script', 'link', 'iframe', 'style', 'object', 'embed', 'base', 'meta', 'noscript' );
		foreach ( $forbidden_tags as $tag ) {
			if ( preg_match( '/<' . preg_quote( $tag, '/' ) . '\b/i', $html ) ) {
				return new \WP_Error( 'kayzart_template_forbidden_html', __( 'Template HTML contains forbidden markup.', 'kayzart-live-code-editor' ) );
			}
		}

		if ( preg_match( '/\s(?:on[a-z0-9_-]*|style|src|srcset|poster)\s*=/i', $html ) ) {
			return new \WP_Error( 'kayzart_template_forbidden_attribute', __( 'Template HTML contains forbidden attributes.', 'kayzart-live-code-editor' ) );
		}

		if ( preg_match( '/\saction\s*=/i', $html ) ) {
			return new \WP_Error( 'kayzart_template_forbidden_action', __( 'Template HTML contains forbidden form attributes.', 'kayzart-live-code-editor' ) );
		}

		if ( preg_match( '/\shref\s*=\s*([\'"])(.*?)\1/i', $html, $matches ) ) {
			$href = isset( $matches[2] ) ? trim( $matches[2] ) : '';
			if ( '' !== $href && '#' !== $href && 0 !== strpos( $href, '#' ) ) {
				return new \WP_Error( 'kayzart_template_forbidden_url', __( 'Template HTML contains forbidden URLs.', 'kayzart-live-code-editor' ) );
			}
		}

		if ( preg_match( '/(?:https?:|javascript:|data:|\/\/)/i', $html ) ) {
			return new \WP_Error( 'kayzart_template_forbidden_url', __( 'Template HTML contains forbidden URLs.', 'kayzart-live-code-editor' ) );
		}

		return true;
	}

	/**
	 * Sanitize theme tokens.
	 *
	 * @param mixed $theme Raw theme tokens.
	 * @return array|\WP_Error
	 */
	private static function sanitize_theme_tokens( $theme ) {
		if ( null === $theme || '' === $theme ) {
			return array();
		}

		if ( ! is_array( $theme ) ) {
			return new \WP_Error( 'kayzart_template_theme_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
		}

		$sanitized = array();
		foreach ( $theme as $group => $tokens ) {
			$group = sanitize_key( (string) $group );
			if ( ! in_array( $group, self::THEME_GROUPS, true ) ) {
				return new \WP_Error( 'kayzart_template_theme_group_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
			}
			if ( ! is_array( $tokens ) ) {
				return new \WP_Error( 'kayzart_template_theme_tokens_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
			}

			foreach ( $tokens as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( '' === $key || ! is_string( $value ) ) {
					return new \WP_Error( 'kayzart_template_theme_value_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
				}

				$value = trim( $value );
				if ( 'colors' === $group ) {
					if ( ! preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ) {
						return new \WP_Error( 'kayzart_template_theme_color_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
					}
				} elseif ( ! preg_match( '/^(?:0|[0-9]+(?:\.[0-9]+)?)(?:px|rem|%)$/', $value ) ) {
					return new \WP_Error( 'kayzart_template_theme_size_invalid', __( 'Template theme is invalid.', 'kayzart-live-code-editor' ) );
				}

				if ( ! isset( $sanitized[ $group ] ) ) {
					$sanitized[ $group ] = array();
				}
				$sanitized[ $group ][ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Build Tailwind CSS input from sanitized theme tokens.
	 *
	 * @param array $theme Sanitized theme tokens.
	 * @return string
	 */
	private static function build_theme_css( array $theme ): string {
		$lines = array();
		foreach ( self::THEME_GROUPS as $group ) {
			if ( empty( $theme[ $group ] ) || ! is_array( $theme[ $group ] ) ) {
				continue;
			}
			foreach ( $theme[ $group ] as $key => $value ) {
				$prefix  = 'colors' === $group ? 'color' : rtrim( $group, 's' );
				$lines[] = '  --' . $prefix . '-' . $key . ': ' . $value . ';';
			}
		}

		if ( empty( $lines ) ) {
			return '@import "tailwindcss";';
		}

		return '@import "tailwindcss";' . "\n\n@theme {\n" . implode( "\n", $lines ) . "\n}";
	}

	/**
	 * Sanitize one template summary.
	 *
	 * @param array $template Raw template summary.
	 * @return array|null
	 */
	private static function sanitize_template_summary( array $template ): ?array {
		$id = isset( $template['id'] ) ? sanitize_key( (string) $template['id'] ) : '';
		if ( '' === $id ) {
			return null;
		}

		$market = isset( $template['market'] ) ? sanitize_key( (string) $template['market'] ) : '';
		if ( ! in_array( $market, array( 'jp', 'en' ), true ) ) {
			return null;
		}

		$tier = isset( $template['tier'] ) ? sanitize_key( (string) $template['tier'] ) : '';
		if ( ! in_array( $tier, array( 'free', 'pro' ), true ) ) {
			return null;
		}

		$thumbnail_url = isset( $template['thumbnailUrl'] ) ? esc_url_raw( (string) $template['thumbnailUrl'] ) : '';
		if ( '' === $thumbnail_url || ! self::is_http_url( $thumbnail_url ) ) {
			return null;
		}

		$title       = isset( $template['title'] ) ? sanitize_text_field( (string) $template['title'] ) : '';
		$category    = isset( $template['category'] ) ? sanitize_key( (string) $template['category'] ) : '';
		$version     = isset( $template['version'] ) ? sanitize_text_field( (string) $template['version'] ) : '';
		$description = isset( $template['description'] ) ? sanitize_text_field( (string) $template['description'] ) : '';

		if ( '' === $title || '' === $category || '' === $version ) {
			return null;
		}

		return array(
			'id'               => $id,
			'title'            => $title,
			'description'      => $description,
			'category'         => $category,
			'market'           => $market,
			'tier'             => $tier,
			'thumbnailUrl'     => $thumbnail_url,
			'requiresTailwind' => true,
			'available'        => isset( $template['available'] ) ? (bool) $template['available'] : 'free' === $tier,
			'version'          => $version,
		);
	}

	/**
	 * Check whether a URL is HTTP(S).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_http_url( string $url ): bool {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $message,
			),
			$status
		);
	}
}
