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
	public const MAX_TAILWIND_CSS_BYTES            = 262144;
	public const MAX_TAILWIND_CANDIDATES           = 5000;
	public const MAX_TAILWIND_CANDIDATE_BYTES      = 262144;
	public const MAX_TAILWIND_CANDIDATE_ITEM_BYTES = 4096;
	public const MAX_TAILWIND_GENERATED_CSS_BYTES  = 2097152;
	public const MAX_RENDER_SHORTCODES             = 100;
}
