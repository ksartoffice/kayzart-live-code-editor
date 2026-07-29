<?php
/**
 * CSS mode persistence helpers.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the active CSS field and the per-mode buffers in sync.
 */
class Css_Mode {
	private const TAILWIND_DEFAULT_CSS = "@import \"tailwindcss\";\n\n@theme {\n  /* ... */\n}\n";

	/**
	 * Create editable Tailwind source from an existing Normal CSS buffer.
	 *
	 * @param string $normal_css Existing Normal CSS.
	 * @return string
	 */
	public static function create_initial_tailwind_source( string $normal_css ): string {
		if ( preg_match( '/@import\s+(?:url\(\s*)?["\']tailwindcss["\']/', $normal_css ) ) {
			return $normal_css;
		}

		return '' !== trim( $normal_css )
			? self::TAILWIND_DEFAULT_CSS . "\n" . ltrim( $normal_css )
			: self::TAILWIND_DEFAULT_CSS;
	}

	/**
	 * Initialize the persisted CSS buffers for a selected setup mode.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    'normal' or 'tailwind'.
	 */
	public static function initialize_post_mode( int $post_id, string $mode ): void {
		$active_css   = (string) get_post_meta( $post_id, '_kayzart_css', true );
		$has_normal   = metadata_exists( 'post', $post_id, '_kayzart_normal_css' );
		$has_tailwind = metadata_exists( 'post', $post_id, '_kayzart_tailwind_css' );
		$normal_css   = $has_normal
			? (string) get_post_meta( $post_id, '_kayzart_normal_css', true )
			: $active_css;

		if ( ! $has_normal ) {
			update_post_meta( $post_id, '_kayzart_normal_css', wp_slash( $normal_css ) );
		}

		if ( 'tailwind' === $mode ) {
			$tailwind_css = $has_tailwind
				? (string) get_post_meta( $post_id, '_kayzart_tailwind_css', true )
				: self::create_initial_tailwind_source( $normal_css );
			if ( ! $has_tailwind ) {
				update_post_meta( $post_id, '_kayzart_tailwind_css', wp_slash( $tailwind_css ) );
			}
			update_post_meta( $post_id, '_kayzart_css', wp_slash( $tailwind_css ) );
			update_post_meta( $post_id, '_kayzart_tailwind', '1' );
			return;
		}

		update_post_meta( $post_id, '_kayzart_css', wp_slash( $normal_css ) );
		update_post_meta( $post_id, '_kayzart_tailwind', '0' );
	}
}
