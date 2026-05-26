<?php
/**
 * External script helpers for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and sanitizes external script URLs.
 */
class External_Scripts {
	private const ALLOWED_ATTRS = array( 'type', 'async', 'defer', 'nomodule', 'integrity', 'crossorigin', 'referrerpolicy', 'fetchpriority' );
	private const BOOLEAN_ATTRS = array( 'async', 'defer', 'nomodule' );
	/**
	 * Fetch external scripts list for a KayzArt post.
	 *
	 * @param int      $post_id KayzArt post ID.
	 * @param int|null $max     Optional max items.
	 * @return array
	 */
	public static function get_external_scripts( int $post_id, ?int $max = null ): array {

		$raw  = get_post_meta( $post_id, '_kayzart_external_scripts', true );
		$list = array();
		if ( is_array( $raw ) ) {
			$list = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$list = $decoded;
			}
		}

		if ( null === $max ) {
			$max = Limits::MAX_EXTERNAL_SCRIPTS;
		}

		return self::sanitize_list( $list, $max );
	}
	/**
	 * Validate a list of external script URLs.
	 *
	 * @param array       $raw   Raw list of URLs.
	 * @param int|null    $max   Optional max items.
	 * @param string|null $error Error message output.
	 * @return array|null
	 */
	public static function validate_list( array $raw, ?int $max = null, ?string &$error = null ): ?array {
		$sanitized = array();
		foreach ( array_values( $raw ) as $entry ) {
			$resource = self::normalize_entry( $entry );
			if ( null === $resource ) {
				$error = __( 'Invalid externalScripts value.', 'kayzart-live-code-editor' );
				return null;
			}
			if ( '' === $resource['url'] ) {
				continue;
			}
			$clean_url = self::sanitize_url( $resource['url'] );
			if ( ! $clean_url ) {
				$error = __( 'External scripts must be valid https:// URLs.', 'kayzart-live-code-editor' );
				return null;
			}
			$sanitized[] = array(
				'url'   => $clean_url,
				'attrs' => self::sanitize_attrs( $resource['attrs'] ),
			);
		}

		$sanitized = self::unique_resources( $sanitized );
		if ( null !== $max && $max < count( $sanitized ) ) {
			$error = __( 'External scripts exceed the maximum allowed.', 'kayzart-live-code-editor' );
			return null;
		}

		return $sanitized;
	}

	/**
	 * Sanitize a list of external script URLs.
	 *
	 * @param array    $raw Raw list of URLs.
	 * @param int|null $max Optional max items.
	 * @return array
	 */
	public static function sanitize_list( array $raw, ?int $max = null ): array {
		$sanitized = array();
		foreach ( array_values( $raw ) as $entry ) {
			$resource = self::normalize_entry( $entry );
			if ( null === $resource ) {
				continue;
			}
			if ( '' === $resource['url'] ) {
				continue;
			}
			$clean_url = self::sanitize_url( $resource['url'] );
			if ( $clean_url ) {
				$sanitized[] = array(
					'url'   => $clean_url,
					'attrs' => self::sanitize_attrs( $resource['attrs'] ),
				);
			}
		}

		$sanitized = self::unique_resources( $sanitized );
		if ( null !== $max && $max < count( $sanitized ) ) {
			$sanitized = array_slice( $sanitized, 0, $max );
		}

		return $sanitized;
	}

	private static function normalize_entry( $entry ): ?array {
		if ( is_string( $entry ) ) {
			return array(
				'url'   => trim( $entry ),
				'attrs' => array(),
			);
		}
		if ( ! is_array( $entry ) || ! isset( $entry['url'] ) || ! is_string( $entry['url'] ) ) {
			return null;
		}
		return array(
			'url'   => trim( $entry['url'] ),
			'attrs' => isset( $entry['attrs'] ) && is_array( $entry['attrs'] ) ? $entry['attrs'] : array(),
		);
	}

	private static function sanitize_attrs( array $raw ): array {
		$attrs = array();
		foreach ( $raw as $key => $value ) {
			$name = strtolower( trim( (string) $key ) );
			if ( '' === $name || 0 === strpos( $name, 'on' ) || ! in_array( $name, self::ALLOWED_ATTRS, true ) ) {
				continue;
			}
			if ( in_array( $name, self::BOOLEAN_ATTRS, true ) && true === $value ) {
				$attrs[ $name ] = true;
				continue;
			}
			if ( ! is_string( $value ) ) {
				continue;
			}
			$value = trim( $value );
			if ( '' === $value || 0 === stripos( $value, 'javascript:' ) ) {
				continue;
			}
			$attrs[ $name ] = sanitize_text_field( $value );
		}
		return $attrs;
	}

	private static function unique_resources( array $resources ): array {
		$seen   = array();
		$unique = array();
		foreach ( $resources as $resource ) {
			if ( isset( $seen[ $resource['url'] ] ) ) {
				continue;
			}
			$seen[ $resource['url'] ] = true;
			$unique[]                 = $resource;
		}
		return $unique;
	}

	/**
	 * Sanitize and validate a single external URL.
	 *
	 * @param string $url URL to sanitize.
	 * @return string|null
	 */
	private static function sanitize_url( string $url ): ?string {
		$url = trim( $url );
		if ( '' === $url ) {
			return null;
		}

		$validated = wp_http_validate_url( $url );
		if ( ! $validated ) {
			return null;
		}

		$parts  = wp_parse_url( $validated );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		if ( 'https' !== $scheme ) {
			return null;
		}

		return esc_url_raw( $validated );
	}
}
