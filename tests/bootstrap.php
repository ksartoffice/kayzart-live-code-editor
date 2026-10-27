<?php
/**
 * PHPUnit bootstrap for KayzArt.
 *
 * @package KayzArt
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = dirname( __DIR__ ) . '/.wordpress-tests-lib';
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WP tests not found. Run bin/install-wp-tests.sh first.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin for the test suite.
 */
function kayzart_manually_load_plugin() {
	require dirname( __DIR__ ) . '/kayzart-live-code-editor.php';
}
tests_add_filter( 'muplugins_loaded', 'kayzart_manually_load_plugin' );

// The migration store can hide pre-4.0 unique-insert behavior. CI also runs the
// AI integration tests against the production DBStore on both bundled versions.
if ( 'db' === getenv( 'KAYZART_TEST_AS_STORE' ) ) {
	tests_add_filter( 'action_scheduler_store_class', static function () {
		return 'ActionScheduler_DBStore';
	}, PHP_INT_MAX );
}

require $_tests_dir . '/includes/bootstrap.php';


