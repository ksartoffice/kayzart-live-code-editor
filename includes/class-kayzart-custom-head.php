<?php
/**
 * Custom head metadata handling for KayzArt posts.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sanitizes and renders custom head snippets.
 */
class Custom_Head {
	public const META_KEY     = '_kayzart_custom_head';
	public const START_MARKER = 'kayzart-custom-head-start';
	public const END_MARKER   = 'kayzart-custom-head-end';

	/**
	 * Remove tags that must be owned by WordPress/the document shell.
	 *
	 * @param string $html Raw custom head HTML.
	 * @return array{html:string,removed:array<int,string>}
	 */
	public static function sanitize( string $html ): array {
		if ( '' === $html ) {
			return array(
				'html'    => '',
				'removed' => array(),
			);
		}

		$removed = array();
		$html    = preg_replace_callback(
			'/<title\b[^>]*>[\s\S]*?<\/title\s*>/i',
			static function () use ( &$removed ): string {
				$removed[] = 'title';
				return '';
			},
			$html
		);
		$html    = is_string( $html ) ? $html : '';

		$html = preg_replace_callback(
			'/<base\b[^>]*\/?>/i',
			static function () use ( &$removed ): string {
				$removed[] = 'base';
				return '';
			},
			$html
		);
		$html = is_string( $html ) ? $html : '';

		$html = preg_replace_callback(
			'/<meta\b[^>]*>/i',
			static function ( array $matches ) use ( &$removed ): string {
				$tag = $matches[0];
				if ( preg_match( '/\scharset\s*=/i', $tag ) ) {
					$removed[] = 'meta charset';
					return '';
				}
				if ( preg_match( '/\sname\s*=\s*([\'"])viewport\1/i', $tag ) || preg_match( '/\sname\s*=\s*viewport(?:\s|\/?>)/i', $tag ) ) {
					$removed[] = 'meta viewport';
					return '';
				}
				return $tag;
			},
			$html
		);
		$html = is_string( $html ) ? $html : '';

		return array(
			'html'    => trim( $html ),
			'removed' => array_values( array_unique( $removed ) ),
		);
	}

	/**
	 * Save sanitized custom head metadata.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $html    Raw custom head HTML.
	 * @return array{html:string,removed:array<int,string>}
	 */
	public static function save( int $post_id, string $html ): array {
		$sanitized = self::sanitize( $html );
		if ( '' === $sanitized['html'] ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, wp_slash( $sanitized['html'] ) );
		}
		return $sanitized;
	}

	/**
	 * Get sanitized custom head HTML for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_for_post( int $post_id ): string {
		$stored    = (string) get_post_meta( $post_id, self::META_KEY, true );
		$sanitized = self::sanitize( $stored );
		return $sanitized['html'];
	}

	/**
	 * Render the current KayzArt post's custom head snippet.
	 */
	public static function render_current_post_head(): void {
		if ( is_admin() ) {
			return;
		}

		if ( get_query_var( 'kayzart_preview' ) ) {
			return;
		}

		$post_id = self::resolve_current_post_id();
		if ( ! $post_id || ! Post_Type::is_kayzart_post( $post_id ) ) {
			return;
		}

		$html = self::get_for_post( $post_id );
		if ( '' === $html ) {
			return;
		}

		echo "\n<!-- " . esc_html( self::START_MARKER ) . " -->\n";
		echo $html . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- User-authored head HTML is intentionally preserved after forbidden tag removal.
		echo '<!-- ' . esc_html( self::END_MARKER ) . " -->\n";
	}

	/**
	 * Resolve the current KayzArt post ID, including preview requests.
	 *
	 * @return int
	 */
	private static function resolve_current_post_id(): int {
		$preview_post_id = absint( get_query_var( 'post_id' ) );
		if ( get_query_var( 'kayzart_preview' ) && $preview_post_id ) {
			return $preview_post_id;
		}

		$post_id = get_queried_object_id();
		if ( $post_id ) {
			return (int) $post_id;
		}

		global $post;
		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}
}
