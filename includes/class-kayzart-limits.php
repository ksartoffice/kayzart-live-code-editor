<?php
/**
 * Shared numeric limits for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized limits used across REST and admin UI.
 */
class Limits {
	public const MAX_EXTERNAL_SCRIPTS    = 10;
	public const MAX_EXTERNAL_STYLES     = 10;
	public const MAX_TAILWIND_HTML_BYTES = 262144;
	public const MAX_TAILWIND_CSS_BYTES  = 262144;
	public const MAX_RENDER_SHORTCODES   = 100;
}
