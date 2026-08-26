<?php
/**
 * Plugin Name: Kayzart — AI Landing Page Builder with Real HTML
 * Plugin URI: https://wordpress.org/plugins/kayzart-live-code-editor/
 * Description: Build landing pages with AI inside WordPress — bring your own AI key. Real HTML/CSS you can edit live. No drag-and-drop, no builder lock-in.
 * Version: 3.0.0
 * Requires at least: 5.9
 * Tested up to: 7.1
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
define( 'KAYZART_VERSION', '3.0.0' );
define( 'KAYZART_PATH', plugin_dir_path( __FILE__ ) );
define( 'KAYZART_URL', plugin_dir_url( __FILE__ ) );

$kayzart_autoload = KAYZART_PATH . 'vendor/autoload.php';
if ( file_exists( $kayzart_autoload ) ) {
	require_once $kayzart_autoload;
}

$kayzart_action_scheduler = KAYZART_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $kayzart_action_scheduler ) ) {
	require_once $kayzart_action_scheduler;
}

require_once KAYZART_PATH . 'includes/class-kayzart-post-type.php';
require_once KAYZART_PATH . 'includes/class-kayzart-css-mode.php';
require_once KAYZART_PATH . 'includes/class-kayzart-admin.php';
require_once KAYZART_PATH . 'includes/class-kayzart-feedback.php';
require_once KAYZART_PATH . 'includes/class-kayzart-editor-bridge.php';
require_once KAYZART_PATH . 'includes/class-kayzart-limits.php';
require_once KAYZART_PATH . 'includes/class-kayzart-tailwind-compiler.php';
require_once KAYZART_PATH . 'includes/class-kayzart-html-document.php';
require_once KAYZART_PATH . 'includes/class-kayzart-custom-head.php';
require_once KAYZART_PATH . 'includes/class-kayzart-snapshot.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-tool-error.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-output-policy.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-css-syntax.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-css-imports.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-tools.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-tool-schema.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-fonts.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-prompt.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-message.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-client-exception.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-client-interface.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-openai-key.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-setup.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-availability.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-models.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-client-wp.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-client-openai.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-client-factory.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-agent-error.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-agent-canceled.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-agent.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-job-store.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-timeline-store.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-worker.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-immediate-dispatcher.php';
require_once KAYZART_PATH . 'includes/ai/class-kayzart-ai-editor.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-ai.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-ai-timeline.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-save.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-setup.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-settings.php';
require_once KAYZART_PATH . 'includes/rest/class-kayzart-rest-revisions.php';
require_once KAYZART_PATH . 'includes/class-kayzart-rest.php';
require_once KAYZART_PATH . 'includes/class-kayzart-preview.php';
require_once KAYZART_PATH . 'includes/class-kayzart-frontend.php';

if ( ! function_exists( 'kayzart_is_standalone_mode' ) ) {
	/**
	 * Check whether the current Kayzart request resolves to standalone mode.
	 *
	 * @param int|null $post_id Kayzart post ID. Defaults to queried object ID.
	 * @return bool
	 */
	function kayzart_is_standalone_mode( ?int $post_id = null ): bool {
		return \KayzArt\Frontend::is_standalone_mode( $post_id );
	}
}

add_action(
	'plugins_loaded',
	function () {
		\KayzArt\Ai_Setup::maybe_upgrade();
		\KayzArt\Ai_Worker::init();
		\KayzArt\Ai_Editor::init();

		// Custom post type used exclusively by Kayzart.
		\KayzArt\Post_Type::init();

		// Admin UI.
		\KayzArt\Admin::init();
		\KayzArt\Feedback::init();
		\KayzArt\Editor_Bridge::init();
		\KayzArt\Snapshot::init();
		add_action(
			'before_delete_post',
			static function ( $post_id ) {
				$job_uuid = ( new \KayzArt\Ai_Job_Store() )->cancel_active_for_post( (int) $post_id );
				if ( $job_uuid ) {
					\KayzArt\Ai_Worker::unschedule_job( $job_uuid );
				}
				( new \KayzArt\Ai_Timeline_Store() )->delete_for_post( (int) $post_id );
			}
		);
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
	\KayzArt\Ai_Setup::activate();
	\KayzArt\Post_Type::activation();
}
register_activation_hook( __FILE__, 'kayzart_activate' );

/**
 * Plugin deactivation hook.
 */
function kayzart_deactivate() {
	\KayzArt\Ai_Worker::deactivate();
	\KayzArt\Post_Type::deactivation();
}
register_deactivation_hook( __FILE__, 'kayzart_deactivate' );
