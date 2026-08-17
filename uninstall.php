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
delete_option( 'kayzart_connector_migration_notice_shown' );
delete_option( 'kayzart_dormant_openai_key_notice_shown' );
delete_transient( 'kayzart_ai_backend' );
