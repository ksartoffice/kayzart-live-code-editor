<?php
/**
 * Tailwind candidate extraction, validation, and compilation.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compiles compact Tailwind candidate sets instead of complete HTML documents.
 */
class Tailwind_Compiler {

	public const CANDIDATES_META_KEY = '_kayzart_tailwind_candidates';

	/**
	 * Normalize and validate a candidate payload.
	 *
	 * @param mixed $input Candidate payload.
	 * @return array<int,string>|\WP_Error
	 */
	public static function normalize_candidates( $input ) {
		if ( ! is_array( $input ) ) {
			return self::error( 'kayzart_tailwind_candidates_invalid', __( 'Invalid Tailwind candidate payload.', 'kayzart-live-code-editor' ) );
		}

		if ( count( $input ) > Limits::MAX_TAILWIND_CANDIDATES ) {
			return self::error( 'kayzart_tailwind_candidates_too_many', __( 'Tailwind candidate count exceeds the maximum.', 'kayzart-live-code-editor' ) );
		}

		$candidates  = array();
		$seen        = array();
		$total_bytes = 0;

		foreach ( $input as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate || preg_match( '/[\s"\']/', $candidate ) ) {
				return self::error( 'kayzart_tailwind_candidate_invalid', __( 'Tailwind candidate contains an invalid value.', 'kayzart-live-code-editor' ) );
			}

			$bytes = strlen( $candidate );
			if ( $bytes > Limits::MAX_TAILWIND_CANDIDATE_ITEM_BYTES ) {
				return self::error( 'kayzart_tailwind_candidate_too_large', __( 'A Tailwind candidate exceeds the maximum size.', 'kayzart-live-code-editor' ) );
			}

			$total_bytes += $bytes;
			if ( $total_bytes > Limits::MAX_TAILWIND_CANDIDATE_BYTES ) {
				return self::error( 'kayzart_tailwind_candidates_too_large', __( 'Tailwind candidates exceed the maximum size.', 'kayzart-live-code-editor' ) );
			}

			if ( isset( $seen[ $candidate ] ) ) {
				continue;
			}
			$seen[ $candidate ] = true;
			$candidates[]       = $candidate;
		}

		return $candidates;
	}

	/**
	 * Extract candidates without retaining every matching attribute in memory.
	 *
	 * This intentionally mirrors TailwindPHP 1.3.2.2 extraction semantics.
	 *
	 * @param string $html HTML source.
	 * @return array<int,string>|\WP_Error
	 */
	public static function extract_candidates( string $html ) {
		$candidates  = array();
		$seen        = array();
		$total_bytes = 0;
		$patterns    = array(
			'/class\s*=\s*["\']([^"\']+)["\']/',
			'/className\s*=\s*["\']([^"\']+)["\']/',
		);

		foreach ( $patterns as $pattern ) {
			$offset      = 0;
			$html_length = strlen( $html );
			while ( $offset < $html_length && 1 === preg_match( $pattern, $html, $matches, PREG_OFFSET_CAPTURE, $offset ) ) {
				$full_match = $matches[0][0];
				$match_at   = $matches[0][1];
				$offset     = $match_at + max( 1, strlen( $full_match ) );
				$tokens     = preg_split( '/\s+/', $matches[1][0] );
				if ( false === $tokens ) {
					continue;
				}

				foreach ( $tokens as $candidate ) {
					$candidate = trim( $candidate );
					if ( '' === $candidate || isset( $seen[ $candidate ] ) ) {
						continue;
					}
					$validation = self::append_candidate( $candidate, $candidates, $seen, $total_bytes );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}
				}
			}
		}

		return $candidates;
	}

	/**
	 * Compile a validated candidate set.
	 *
	 * @param mixed  $input Candidate payload.
	 * @param string $css   Tailwind CSS source.
	 * @return string|\WP_Error
	 */
	public static function generate( $input, string $css ) {
		$candidates = self::normalize_candidates( $input );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		if ( strlen( $css ) > Limits::MAX_TAILWIND_CSS_BYTES ) {
			return self::error( 'kayzart_tailwind_css_too_large', __( 'Tailwind CSS input exceeds the maximum size.', 'kayzart-live-code-editor' ) );
		}

		$content = '<div class="' . implode( ' ', $candidates ) . '"></div>';
		try {
			$generated_css = tw::generate(
				array(
					'content' => $content,
					'css'     => $css,
				)
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'kayzart_tailwind_compile_failed',
				sprintf(
					/* translators: %s: error message. */
					__( 'Tailwind compile failed: %s', 'kayzart-live-code-editor' ),
					$e->getMessage()
				),
				array( 'status' => 500 )
			);
		}

		if ( strlen( $generated_css ) > Limits::MAX_TAILWIND_GENERATED_CSS_BYTES ) {
			return self::error( 'kayzart_tailwind_generated_css_too_large', __( 'Generated Tailwind CSS exceeds the maximum size.', 'kayzart-live-code-editor' ) );
		}

		return $generated_css;
	}

	/**
	 * Decode a stored candidate list.
	 *
	 * @param mixed $value Stored JSON value.
	 * @return array<int,string>|\WP_Error
	 */
	public static function decode_candidates( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return self::error( 'kayzart_tailwind_candidates_missing', __( 'Tailwind candidates are not available.', 'kayzart-live-code-editor' ) );
		}
		$decoded = json_decode( $value, true );
		return self::normalize_candidates( $decoded );
	}

	/**
	 * Encode candidates for post meta storage.
	 *
	 * @param array<int,string> $candidates Candidate list.
	 * @return string
	 */
	public static function encode_candidates( array $candidates ): string {
		$encoded = wp_json_encode( array_values( $candidates ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $encoded ? '[]' : $encoded;
	}

	/**
	 * Add an extracted candidate while enforcing compiler safety limits.
	 *
	 * @param string             $candidate   Candidate value.
	 * @param array<int,string>  $candidates  Candidate list.
	 * @param array<string,bool> $seen        Candidate set.
	 * @param int                $total_bytes Aggregate byte count.
	 * @return true|\WP_Error
	 */
	private static function append_candidate( string $candidate, array &$candidates, array &$seen, int &$total_bytes ) {
		$bytes = strlen( $candidate );
		if ( $bytes > Limits::MAX_TAILWIND_CANDIDATE_ITEM_BYTES ) {
			return self::error( 'kayzart_tailwind_candidate_too_large', __( 'A Tailwind candidate exceeds the maximum size.', 'kayzart-live-code-editor' ) );
		}
		if ( count( $candidates ) >= Limits::MAX_TAILWIND_CANDIDATES ) {
			return self::error( 'kayzart_tailwind_candidates_too_many', __( 'Tailwind candidate count exceeds the maximum.', 'kayzart-live-code-editor' ) );
		}
		$total_bytes += $bytes;
		if ( $total_bytes > Limits::MAX_TAILWIND_CANDIDATE_BYTES ) {
			return self::error( 'kayzart_tailwind_candidates_too_large', __( 'Tailwind candidates exceed the maximum size.', 'kayzart-live-code-editor' ) );
		}
		$seen[ $candidate ] = true;
		$candidates[]       = $candidate;
		return true;
	}

	/**
	 * Create a validation error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
