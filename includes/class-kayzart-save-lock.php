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
 *
 * PHP cannot renew a lease while a request is busy, so the lease never
 * expires while its owner is alive in practice instead:
 * - every lock a request holds is released at shutdown, which also runs
 *   after a fatal error or the execution time limit, so only a killed
 *   process leaves a row behind;
 * - that makes a long STALE_SECONDS safe;
 * - the save handler calls confirm() right before writing, which renews
 *   the lease and refuses the write if the lock was taken over anyway.
 *
 * The lock must never stop people from saving. Only "another request holds
 * it" makes a save wait or fail. When the database refuses the lock
 * statements themselves, the save proceeds without a lock, as every save
 * did before the lock existed, and the failure is logged. Sites can also
 * turn the lock off with the kayzart_save_lock_enabled filter.
 */
final class Save_Lock {

	/** Seconds after which a lock left by a killed request is ignored. */
	const STALE_SECONDS = 600;

	/** Seconds to wait for another save of the same page to finish. */
	const WAIT_SECONDS = 10;

	/** Row value recorded for a hold that has no lock row (lock off or unavailable). */
	const NO_ROW = '';

	/**
	 * Locks held by this request, as post ID => nesting depth and row value.
	 *
	 * @var array<int,array{depth:int,value:string}>
	 */
	private static $held = array();

	/**
	 * Whether the shutdown release is registered for this request.
	 *
	 * @var bool
	 */
	private static $shutdown_registered = false;

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
		/**
		 * Filters whether saves of a page are serialized with a lock.
		 *
		 * @param bool $enabled Whether to use the lock. Default true.
		 * @param int  $post_id Post ID.
		 */
		if ( ! apply_filters( 'kayzart_save_lock_enabled', true, $post_id ) ) {
			return self::hold_without_row( $post_id );
		}

		global $wpdb;
		$name   = self::option_name( $post_id );
		$misses = 0;
		/**
		 * Filters how long a save waits for another save of the same page.
		 *
		 * @param int $seconds Seconds to wait. Default 10.
		 */
		$deadline = microtime( true ) + max( 0, (int) apply_filters( 'kayzart_save_lock_wait', self::WAIT_SECONDS ) );
		do {
			$value    = time() . ':' . wp_generate_password( 20, false, false );
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", $name, $value ) );
			if ( false === $inserted ) {
				return self::fail_open( $post_id, 'insert', (string) $wpdb->last_error );
			}
			if ( $inserted ) {
				self::$held[ $post_id ] = array(
					'depth' => 1,
					'value' => $value,
				);
				if ( ! self::$shutdown_registered ) {
					register_shutdown_function( array( __CLASS__, 'release_all' ) );
					self::$shutdown_registered = true;
				}
				return true;
			}
			$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null === $current ) {
				if ( '' !== (string) $wpdb->last_error ) {
					return self::fail_open( $post_id, 'read', (string) $wpdb->last_error );
				}
				// Its owner released it between the two statements: try again at once.
				// An insert that keeps being ignored with no row to blame is a broken
				// database, not a busy page.
				++$misses;
				if ( $misses > 3 ) {
					return self::fail_open( $post_id, 'insert ignored', '' );
				}
				continue;
			}
			$current = (string) $current;
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
		if ( self::NO_ROW === $value ) {
			return;
		}

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
	 * Renew the lease and confirm this request still owns the lock.
	 *
	 * Call right before writing. The single UPDATE both proves ownership
	 * (it matches only this request's row value) and restarts the lease.
	 *
	 * @param int $post_id Post ID.
	 * @return bool False when this request does not hold the lock any more.
	 */
	public static function confirm( int $post_id ): bool {
		if ( ! isset( self::$held[ $post_id ] ) ) {
			return false;
		}
		global $wpdb;
		$old = self::$held[ $post_id ]['value'];
		if ( self::NO_ROW === $old ) {
			return true;
		}
		$token = substr( $old, (int) strpos( $old, ':' ) + 1 );
		$value = time() . ':' . $token;
		if ( $value === $old ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::option_name( $post_id ), $old ) );
		}
		$updated = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $value ),
			array(
				'option_name'  => self::option_name( $post_id ),
				'option_value' => $old,
			)
		);
		if ( false === $updated ) {
			// The database refused the statement: do not block the save over it.
			self::report( 'renew', (string) $wpdb->last_error );
			return true;
		}
		if ( 1 !== $updated ) {
			return false;
		}
		self::$held[ $post_id ]['value'] = $value;
		return true;
	}

	/**
	 * Hold a page without a lock row.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Always true.
	 */
	private static function hold_without_row( int $post_id ): bool {
		self::$held[ $post_id ] = array(
			'depth' => 1,
			'value' => self::NO_ROW,
		);
		return true;
	}

	/**
	 * Let a save proceed without a lock after a database failure.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $step    Statement that failed.
	 * @param string $error   Database error.
	 * @return bool Always true.
	 */
	private static function fail_open( int $post_id, string $step, string $error ): bool {
		self::report( $step, $error );
		return self::hold_without_row( $post_id );
	}

	/**
	 * Log a lock failure so it can be diagnosed.
	 *
	 * @param string $step  Statement that failed.
	 * @param string $error Database error.
	 */
	private static function report( string $step, string $error ): void {
		error_log( sprintf( 'Kayzart save lock unavailable (%s), saving without it. %s', $step, $error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Release every lock this request still holds. Runs at shutdown.
	 */
	public static function release_all(): void {
		foreach ( array_keys( self::$held ) as $post_id ) {
			self::$held[ $post_id ]['depth'] = 1;
			self::release( (int) $post_id );
		}
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
