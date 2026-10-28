<?php
/**
 * Bundled scheduler selection and job registration compatibility.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Loads one bundled version; Action Scheduler still arbitrates other plugins. */
class Ai_Scheduler {

	/**
	 * Get the entry point compatible with this WordPress version.
	 *
	 * @param string $wp_version WordPress version.
	 * @return string Absolute bundled entry point.
	 */
	public static function bundled_file( string $wp_version ): string {
		$package = version_compare( $wp_version, '6.8', '<' )
			? 'kayzart/action-scheduler-legacy'
			: 'woocommerce/action-scheduler';
		return KAYZART_PATH . 'vendor/' . $package . '/action-scheduler.php';
	}

	/** Register before plugins_loaded so the normal highest-version selection runs. */
	public static function load(): void {
		global $wp_version;
		$file = self::bundled_file( (string) $wp_version );
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Whether the site's selected scheduler includes arguments in unique inserts.
	 *
	 * Before 4.0, distinct jobs sharing our hook and group can suppress each other.
	 * REST request IDs and worker claims remain responsible for job idempotency.
	 *
	 * @return bool
	 */
	public static function supports_unique_job_actions(): bool {
		return class_exists( '\\ActionScheduler_Versions', false )
			&& version_compare( \ActionScheduler_Versions::instance()->latest_version(), '4.0.0', '>=' );
	}
}
