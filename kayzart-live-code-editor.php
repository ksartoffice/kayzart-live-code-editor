<?php
/**
 * Plugin Name: KayzArt Live Code Editor
 * Plugin URI: https://wordpress.org/plugins/kayzart-live-code-editor/
 * Description: Live HTML/CSS/JS editor with real-time preview and Tailwind CSS support for WordPress.
 * Version: 1.1.3
 * Requires at least: 6.6
 * Tested up to: 6.9
 * Requires PHP: 8.2
 * Author: KayzArt
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kayzart-live-code-editor
 * Domain Path: /languages
 *
 * @package KayzArt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'KAYZART_VERSION', '1.1.3' );
define( 'KAYZART_PATH', plugin_dir_path( __FILE__ ) );
define( 'KAYZART_URL', plugin_dir_url( __FILE__ ) );

$kayzart_autoload = KAYZART_PATH . 'vendor/autoload.php';
if ( file_exists( $kayzart_autoload ) ) {
	require_once $kayzart_autoload;
}

require_once KAYZART_PATH . 'includes/class-kayzart-post-type.php';
require_once KAYZART_PATH . 'includes/class-kayzart-admin.php';
require_once KAYZART_PATH . 'includes/class-kayzart-editor-bridge.php';
require_once KAYZART_PATH . 'includes/class-kayzart-limits.php';
require_once KAYZART_PATH . 'includes/class-kayzart-external-scripts.php';
require_once KAYZART_PATH . 'includes/class-kayzart-external-styles.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-save.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-setup.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-import.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-settings.php';
require_once KAYZART_PATH . 'includes/class-kayzart-rest.php';
require_once KAYZART_PATH . 'includes/class-kayzart-preview.php';
require_once KAYZART_PATH . 'includes/class-kayzart-frontend.php';

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain(
			'kayzart-live-code-editor',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		// Custom post type used exclusively by KayzArt.
		\KayzArt\Post_Type::init();

		// Admin UI.
		\KayzArt\Admin::init();
		\KayzArt\Editor_Bridge::init();

		// REST endpoints.
		\KayzArt\Rest::init();

		// Preview mode for iframe.
		\KayzArt\Preview::init();

		// Frontend rendering (public view).
		\KayzArt\Frontend::init();
	}
);

/**
 * Plugin activation hook.
 */
function kayzart_activate() {
	\KayzArt\Post_Type::activation();
}
register_activation_hook( __FILE__, 'kayzart_activate' );

/**
 * Plugin deactivation hook.
 */
function kayzart_deactivate() {
	\KayzArt\Post_Type::deactivation();
}
register_deactivation_hook( __FILE__, 'kayzart_deactivate' );
