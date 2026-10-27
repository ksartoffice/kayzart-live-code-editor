<?php
/**
 * Install as a mu-plugin ONLY on a disposable scheduler E2E site.
 *
 * @package KayzArt
 */

if ( '1' !== getenv( 'KAYZART_SCHEDULER_E2E' ) ) {
	return;
}

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_http_request', static function () {
	return new WP_Error( 'scheduler_e2e_no_http', 'External HTTP is disabled on this disposable site.' );
} );
add_filter( 'action_scheduler_store_class', static function () {
	return 'ActionScheduler_DBStore';
}, PHP_INT_MAX );

// Seed data as the former dependency did, only in the explicit CLI preparation.
if ( defined( 'WP_CLI' ) && WP_CLI && '1' === getenv( 'KAYZART_SCHEDULER_E2E_SEED_MODERN' ) ) {
	require_once WP_PLUGIN_DIR . '/kayzart-live-code-editor/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Simulate another plugin registering its copy on either side of Kayzart.
$scheduler_order = isset( $_COOKIE['kayzart_scheduler_other_order'] ) ? sanitize_key( wp_unslash( $_COOKIE['kayzart_scheduler_other_order'] ) ) : '';
$scheduler_other = static function () {
	require_once WP_PLUGIN_DIR . '/kayzart-live-code-editor/vendor/kayzart/action-scheduler-legacy/action-scheduler.php';
};
if ( 'before' === $scheduler_order ) {
	$scheduler_other();
} elseif ( 'after' === $scheduler_order ) {
	add_action( 'plugins_loaded', $scheduler_other, -1 );
}

add_action( 'admin_footer', static function () {
	if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'ActionScheduler', false ) ) {
		return;
	}
	$source = wp_normalize_path( ( new ReflectionClass( 'ActionScheduler' ) )->getFileName() );
	$state  = array(
		'wpVersion' => $GLOBALS['wp_version'],
		'php'       => PHP_VERSION,
		'version'   => ActionScheduler_Versions::instance()->latest_version(),
		'bundle'    => false !== strpos( $source, '/kayzart/action-scheduler-legacy/' ) ? 'legacy' : 'modern',
		'store'     => get_class( ActionScheduler::store() ),
	);
	echo '<script>window.KAYZART_SCHEDULER_E2E = ' . wp_json_encode( $state ) . ';</script>';
} );
