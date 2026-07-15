<?php
/**
 * Installs and upgrades the local AI job infrastructure.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI database schema and capability setup.
 */
class Ai_Setup {
	const CAPABILITY        = 'kayzart_ai_edit';
	const DB_VERSION        = '2';
	const DB_VERSION_OPTION = 'kayzart_ai_db_version';
	const JOBS_TABLE_SUFFIX = 'kayzart_ai_jobs';

	/**
	 * Install the current schema and role capability on plugin activation.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Upgrade existing sites only when the schema version changes.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Return the site-specific AI jobs table name.
	 *
	 * @return string
	 */
	public static function get_jobs_table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::JOBS_TABLE_SUFFIX;
	}

	/**
	 * Create or update the database schema and grant the default capability.
	 */
	private static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::get_jobs_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			job_uuid char(36) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			request_id varchar(64) NOT NULL,
			status varchar(20) NOT NULL,
			cancel_requested tinyint(1) unsigned NOT NULL DEFAULT 0,
			payload_json longtext NOT NULL,
			snapshot_json longtext NULL,
			events_json longtext NOT NULL,
			usage_json longtext NULL,
			error longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			started_at datetime NULL,
			finished_at datetime NULL,
			deadline_at datetime NOT NULL,
			lock_key varchar(64) NULL,
			PRIMARY KEY  (job_uuid),
			UNIQUE KEY user_request (user_id, request_id),
			UNIQUE KEY active_post (lock_key),
			KEY post_status (post_id, status),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";

		dbDelta( $sql );
		$installed_table = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);
		if ( $table_name !== $installed_table ) {
			return;
		}

		self::grant_default_capability();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Grant AI editing to administrators by default.
	 */
	private static function grant_default_capability(): void {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}
}
