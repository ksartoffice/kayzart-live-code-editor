<?php
/**
 * Uninstall handler for KayzArt.
 *
 * @package KayzArt
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// User-created content and KayzArt-managed posts are intentionally preserved.
