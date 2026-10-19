<?php
/**
 * Per-page lock that serializes saves.
 *
 * @package KayzArt
 */

namespace KayzArt;

/*
 * The lock row must be created with one atomic statement so two requests can
 * never both believe they hold it, which add_option() cannot guarantee: it
 * upserts. This is the same INSERT IGNORE technique WP_Upgrader::create_lock()
 * uses, and a cached read would defeat it.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serializes every save of one page across requests.
 *
 * An agent update checks the baseHash it was given and then saves. Without a
 * lock, an editor save landing between those two steps would be overwritten
 * by the agent's older snapshot. Both the editor save handler and agent
 * updates take this lock, so a check and the write that follows it are never
 * interleaved with another save of the same page.
 *
 * The lock is re-entrant within a request, so an agent update can hold it
 * while calling the editor save handler that takes it too.
 *
 * The row stores "<timestamp>:<owner token>". A request releases only the
 * row carrying its own token, so a save that outlived STALE_SECONDS and had
 * its lock taken over cannot unlock the request that replaced it.
 */
final class Save_Lock {

	/** Seconds after which a lock left by a crashed request is ignored. */
	const STALE_SECONDS = 60;

	/** Seconds to wait for another save of the same page to finish. */
	const WAIT_SECONDS = 10;

	/**
	 * Locks held by this request, as post ID => nesting depth and row value.
	 *
	 * @var array<int,array{depth:int,value:string}>
	 */
	private static $held = array();

	/**
	 * Acquire the lock for a page, waiting briefly if another save holds it.
	 *
	 * @param int $post_id Post ID.
	 * @return bool False when the page stayed locked for the whole wait.
	 */
	public static function acquire( int $post_id ): bool {
		if ( isset( self::$held[ $post_id ] ) ) {
			++self::$held[ $post_id ]['depth'];
			return true;
		}

		global $wpdb;
		$name = self::option_name( $post_id );
		/**
		 * Filters how long a save waits for another save of the same page.
		 *
		 * @param int $seconds Seconds to wait. Default 10.
		 */
		$deadline = microtime( true ) + max( 0, (int) apply_filters( 'kayzart_save_lock_wait', self::WAIT_SECONDS ) );
		do {
			$value    = time() . ':' . wp_generate_password( 20, false, false );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, $value ) );
			if ( $inserted ) {
				self::$held[ $post_id ] = array(
					'depth' => 1,
					'value' => $value,
				);
				return true;
			}
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			$since   = (int) strtok( $current, ':' );
			if ( $since > 0 && $since < time() - self::STALE_SECONDS ) {
				// Delete only the stale row, so a lock another request just took survives.
				$wpdb->delete(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $current,
					)
				);
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			usleep( 100000 );
		} while ( true );

		return false;
	}

	/**
	 * Release a lock taken by this request.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function release( int $post_id ): void {
		if ( ! isset( self::$held[ $post_id ] ) ) {
			return;
		}
		--self::$held[ $post_id ]['depth'];
		if ( self::$held[ $post_id ]['depth'] > 0 ) {
			return;
		}
		$value = self::$held[ $post_id ]['value'];
		unset( self::$held[ $post_id ] );

		global $wpdb;
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::option_name( $post_id ),
				'option_value' => $value,
			)
		);
	}

	/**
	 * Option row used as the lock.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function option_name( int $post_id ): string {
		return 'kayzart_save_lock_' . $post_id;
	}
}
