<?php
/**
 * Plugin Name: KayzArt Landing Pages — Paste & Edit AI-Generated HTML
 * Plugin URI: https://wordpress.org/plugins/kayzart-live-code-editor/
 * Description: The place to paste landing pages your AI wrote. Edit HTML, CSS & JavaScript live and publish — without fighting your theme.
 * Version: 2.0.3
 * Requires at least: 5.9
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: K's Art Office
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
define( 'KAYZART_VERSION', '2.0.3' );
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
require_once KAYZART_PATH . 'includes/class-kayzart-html-document.php';
require_once KAYZART_PATH . 'includes/class-kayzart-custom-head.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-save.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-setup.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-settings.php';
require_once KAYZART_PATH . 'includes/class-kayzart-rest.php';
require_once KAYZART_PATH . 'includes/class-kayzart-preview.php';
require_once KAYZART_PATH . 'includes/class-kayzart-frontend.php';

if ( ! function_exists( 'kayzart_is_standalone_mode' ) ) {
	/**
	 * Check whether the current KayzArt request resolves to standalone mode.
	 *
	 * @param int|null $post_id KayzArt post ID. Defaults to queried object ID.
	 * @return bool
	 */
	function kayzart_is_standalone_mode( ?int $post_id = null ): bool {
		return \KayzArt\Frontend::is_standalone_mode( $post_id );
	}
}

add_action(
	'plugins_loaded',
	function () {
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
