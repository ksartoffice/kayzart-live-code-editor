<?php
/**
 * One-time upgrade routines for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles lightweight data migrations.
 */
class Upgrader {
	const OPTION_TAILWIND_REMOVED_MIGRATED = 'kayzart_tailwind_removed_migrated';

	/**
	 * Register upgrade hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'maybe_migrate_tailwind_posts' ), 30 );
	}

	/**
	 * Convert legacy Tailwind posts to normal CSS once.
	 */
	public static function maybe_migrate_tailwind_posts(): void {
		if ( '1' === get_option( self::OPTION_TAILWIND_REMOVED_MIGRATED, '0' ) ) {
			return;
		}

		$post_ids = get_posts(
			array(
				'post_type'              => array( Post_Type::POST_TYPE, Post_Type::PAGE_TYPE ),
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => '_kayzart_tailwind',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_kayzart_tailwind_locked',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_kayzart_generated_css',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
				continue;
			}

			$is_tailwind   = '1' === get_post_meta( $post_id, '_kayzart_tailwind', true );
			$generated_css = (string) get_post_meta( $post_id, '_kayzart_generated_css', true );
			if ( $is_tailwind && '' !== $generated_css ) {
				update_post_meta( $post_id, '_kayzart_css', wp_slash( $generated_css ) );
			}

			delete_post_meta( $post_id, '_kayzart_tailwind' );
			delete_post_meta( $post_id, '_kayzart_tailwind_locked' );
			delete_post_meta( $post_id, '_kayzart_generated_css' );
		}

		update_option( self::OPTION_TAILWIND_REMOVED_MIGRATED, '1' );
	}
}
