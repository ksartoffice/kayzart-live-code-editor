<?php
/**
 * Plugin Name: Codellia
 * Plugin URI: https://wordpress.org/plugins/codellia/
 * Description: Live HTML/CSS/JS editor with real-time preview and Tailwind CSS support for WordPress.
 * Version: 1.0.1
 * Requires at least: 6.6
 * Tested up to: 6.9
 * Requires PHP: 8.2
 * Author: Codellia
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: codellia
 * Domain Path: /languages
 *
 * @package Codellia
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'CODELLIA_VERSION', '1.0.1' );
define( 'CODELLIA_PATH', plugin_dir_path( __FILE__ ) );
define( 'CODELLIA_URL', plugin_dir_url( __FILE__ ) );

$codellia_autoload = CODELLIA_PATH . 'vendor/autoload.php';
if ( file_exists( $codellia_autoload ) ) {
	require_once $codellia_autoload;
}

require_once CODELLIA_PATH . 'includes/class-codellia-post-type.php';
require_once CODELLIA_PATH . 'includes/class-codellia-admin.php';
require_once CODELLIA_PATH . 'includes/class-codellia-editor-bridge.php';
require_once CODELLIA_PATH . 'includes/class-codellia-limits.php';
require_once CODELLIA_PATH . 'includes/class-codellia-external-scripts.php';
require_once CODELLIA_PATH . 'includes/class-codellia-external-styles.php';
require_once CODELLIA_PATH . 'includes/rest/class-codellia-rest-save.php';
require_once CODELLIA_PATH . 'includes/rest/class-codellia-rest-setup.php';
require_once CODELLIA_PATH . 'includes/rest/class-codellia-rest-import.php';
require_once CODELLIA_PATH . 'includes/rest/class-codellia-rest-settings.php';
require_once CODELLIA_PATH . 'includes/rest/class-codellia-rest-preview.php';
require_once CODELLIA_PATH . 'includes/class-codellia-rest.php';
require_once CODELLIA_PATH . 'includes/class-codellia-preview.php';
require_once CODELLIA_PATH . 'includes/class-codellia-frontend.php';

add_action(
	'plugins_loaded',
	function () {
		// Custom post type used exclusively by Codellia.
		\Codellia\Post_Type::init();

		// Admin UI.
		\Codellia\Admin::init();
		\Codellia\Editor_Bridge::init();

		// REST endpoints.
		\Codellia\Rest::init();

		// Preview mode for iframe.
		\Codellia\Preview::init();

		// Frontend rendering (public view).
		\Codellia\Frontend::init();
	}
);

/**
 * Plugin activation hook.
 */
function codellia_activate() {
	\Codellia\Post_Type::activation();
}
register_activation_hook( __FILE__, 'codellia_activate' );

/**
 * Plugin deactivation hook.
 */
function codellia_deactivate() {
	\Codellia\Post_Type::deactivation();
}
register_deactivation_hook( __FILE__, 'codellia_deactivate' );
