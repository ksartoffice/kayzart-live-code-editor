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
delete_option( 'kayzart_openai_api_key' );
delete_option( 'kayzart_installed_version' );
