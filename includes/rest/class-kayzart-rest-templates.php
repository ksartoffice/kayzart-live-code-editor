<?php
/**
 * REST handler for Kayzart template catalog.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for template catalog workflows.
 */
class Rest_Templates {
	private const DEFAULT_CATALOG_URL = 'https://templates.kayzart.com/v1/catalog.json';
	private const CACHE_TTL           = HOUR_IN_SECONDS;

	/**
	 * Fetch and return the remote template catalog.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_catalog( \WP_REST_Request $request ): \WP_REST_Response {
		unset( $request );

		$catalog_url = self::get_catalog_url();
		$cache_key   = self::get_cache_key( $catalog_url );
		$cached      = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return new \WP_REST_Response( $cached, 200 );
		}

		$response = wp_remote_get(
			$catalog_url,
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::error_response( __( 'Failed to load templates.', 'kayzart-live-code-editor' ), 502 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			return self::error_response( __( 'Failed to load templates.', 'kayzart-live-code-editor' ), 502 );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return self::error_response( __( 'Template catalog is invalid.', 'kayzart-live-code-editor' ), 502 );
		}

		$catalog = self::sanitize_catalog( $data );
		set_transient( $cache_key, $catalog, self::CACHE_TTL );

		return new \WP_REST_Response( $catalog, 200 );
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
