<?php
/**
 * Shared, public AI availability and connection guidance.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds connection guidance without exposing credentials. */
class Ai_Onboarding {
	/**
	 * Return the current state for admin screens and read-only REST checks.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_data(): array {
		$status     = Ai_Availability::get_status();
		$connectors = Ai_OpenAI_Key::connectors_available();
		$can_edit   = current_user_can( Ai_Setup::CAPABILITY );
		$can_manage = current_user_can( 'manage_options' );
		$message    = '';
		if ( ! $can_edit ) {
			$message = __( 'Ask an administrator for permission to use AI editing.', 'kayzart-live-code-editor' );
		} elseif ( ! $status['feature_enabled'] ) {
			$message = __( 'AI editing is disabled by this site’s policy. Ask an administrator.', 'kayzart-live-code-editor' );
		} elseif ( ! $status['scheduler_present'] || ! $status['mbstring_present'] || ! $status['dom_present'] ) {
			$message = __( 'AI editing requires Action Scheduler and the PHP mbstring and DOM extensions. Ask an administrator to check the environment requirements.', 'kayzart-live-code-editor' );
		} elseif ( ! $status['available'] && ! $can_manage ) {
			$message = __( 'Ask an administrator to configure an AI service for this site.', 'kayzart-live-code-editor' );
		}
		return array(
			'available'           => $can_edit && $status['available'],
			'setupState'          => $status['setup_state'],
			'backend'             => $status['backend'],
			'featureEnabled'      => $status['feature_enabled'],
			'sdkPresent'          => $status['sdk_present'],
			'providerConfigured'  => $status['provider_configured'],
			'connectorConfigured' => $status['connector_configured'],
			'directKeyConfigured' => $status['direct_key_configured'],
			'directKeySource'     => $status['direct_key_source'],
			'schedulerPresent'    => $status['scheduler_present'],
			'mbstringPresent'     => $status['mbstring_present'],
			'domPresent'          => $status['dom_present'],
			'canEdit'             => $can_edit,
			'canManageConnectors' => $can_manage,
			'canManageSettings'   => $can_manage,
			'canSetUp'            => $can_manage && $can_edit && 'setup_required' === $status['setup_state'],
			'unavailableMessage'  => $message,
			'setupMode'           => $connectors ? 'connectors' : 'direct',
			'setupUrl'            => $can_manage ? ( $connectors ? admin_url( 'options-connectors.php' ) : Admin::get_settings_url() . '#kayzart-ai-connection' ) : '',
			'setupLabel'          => $connectors ? __( 'Connect an AI service', 'kayzart-live-code-editor' ) : __( 'Set up an OpenAI API key', 'kayzart-live-code-editor' ),
			'setupGuide'          => self::get_guide( $connectors ),
			'availabilityUrl'     => rest_url( 'kayzart/v1/ai/availability' ),
			'maxPromptChars'      => Admin::get_ai_max_prompt_chars(),
		);
	}

	/**
	 * Return translated instructions shared by PHP and the editor.
	 *
	 * @param bool $connectors Whether WordPress Connectors can be used.
	 * @return array<string,mixed>
	 */
	public static function get_guide( bool $connectors ): array {
		return array(
			'summary' => $connectors ? __( 'See how to connect an AI service', 'kayzart-live-code-editor' ) : __( 'See how to obtain and set up an API key', 'kayzart-live-code-editor' ),
			'steps'   => $connectors ? array(
				__( 'Open WordPress Connectors in a new tab.', 'kayzart-live-code-editor' ),
				__( 'Choose an AI service, enter its connection details, and save the settings. Usage fees are paid to that service.', 'kayzart-live-code-editor' ),
				__( 'Return to the original Kayzart tab and select “Check settings again”.', 'kayzart-live-code-editor' ),
			) : array(
				__( 'An API key lets Kayzart use your OpenAI API account. Keep it private.', 'kayzart-live-code-editor' ),
				__( 'Open the OpenAI dashboard and create an API key.', 'kayzart-live-code-editor' ),
				__( 'Set up API billing and usage limits in your OpenAI account. A ChatGPT subscription does not include API usage.', 'kayzart-live-code-editor' ),
				__( 'Paste the key into Kayzart settings and save it.', 'kayzart-live-code-editor' ),
				__( 'Return to the original Kayzart tab and select “Check settings again”.', 'kayzart-live-code-editor' ),
			),
			'links'   => $connectors ? array() : array(
				array(
					'label' => __( 'OpenAI API keys', 'kayzart-live-code-editor' ),
					'url'   => 'https://platform.openai.com/api-keys',
				),
				array(
					'label' => __( 'OpenAI API quickstart', 'kayzart-live-code-editor' ),
					'url'   => 'https://developers.openai.com/api/docs/quickstart',
				),
				array(
					'label' => __( 'OpenAI API billing', 'kayzart-live-code-editor' ),
					'url'   => 'https://platform.openai.com/settings/organization/billing/overview',
				),
			),
		);
	}

	/**
	 * Render an accessible, initially closed connection guide.
	 *
	 * @param array $guide Translated connection guide.
	 */
	public static function render_guide( array $guide ): void {
		echo '<details class="kayzart-ai-connection-guide"><summary>' . esc_html( $guide['summary'] ) . '</summary><ol>';
		foreach ( $guide['steps'] as $step ) {
			echo '<li>' . esc_html( $step ) . '</li>';
		}
		echo '</ol>';
		foreach ( $guide['links'] as $link ) {
			echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . ' <span>' . esc_html__( '(opens in a new tab)', 'kayzart-live-code-editor' ) . '</span></a></p>';
		}
		echo '</details>';
	}
}
