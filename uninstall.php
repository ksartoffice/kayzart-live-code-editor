<?php
/**
 * Uninstall handler for KayzArt.
 *
 * @package KayzArt
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$kayzart_delete_data = get_option( 'kayzart_delete_on_uninstall', '0' );
if ( '1' !== $kayzart_delete_data ) {
	return;
}

$posts = get_posts(
	array(
		'post_type'              => 'kayzart',
		'post_status'            => 'any',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_term_cache' => false,
		'update_post_meta_cache' => false,
	)
);

foreach ( $posts as $post_id ) {
	wp_delete_post( $post_id, true );
}

delete_option( 'kayzart_delete_on_uninstall' );
delete_option( 'kayzart_post_slug' );
delete_option( 'kayzart_shortcode_allowlist' );
delete_option( 'kayzart_flush_rewrite' );
