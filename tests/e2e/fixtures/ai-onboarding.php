<?php
/**
 * Install as a mu-plugin ONLY in a disposable E2E WordPress installation.
 * Credentials and availability are simulated; all AI job requests must be stubbed.
 *
 * @package KayzArt
 */

if ( ! defined( 'KAYZART_ONBOARDING_E2E' ) || ! KAYZART_ONBOARDING_E2E ) {
	return;
}

// The disposable installation must never make a provider or update request.
add_filter(
	'pre_http_request',
	static function () {
		return new WP_Error( 'kayzart_e2e_http_disabled', 'External HTTP is disabled in this disposable fixture.' );
	}
);

$kayzart_onboarding_mode = isset( $_COOKIE['kayzart_onboarding_mode'] ) ? $_COOKIE['kayzart_onboarding_mode'] : '';
if ( ! in_array( $kayzart_onboarding_mode, array( 'direct', 'connectors', 'fallback' ), true ) ) {
	return;
}
$kayzart_onboarding_ready = isset( $_COOKIE['kayzart_onboarding_ready'] ) && 'yes' === $_COOKIE['kayzart_onboarding_ready'];
add_action(
	'init',
	static function () use ( $kayzart_onboarding_mode ): void {
		$GLOBALS['wp_version'] = 'direct' === $kayzart_onboarding_mode ? '6.9' : '7.0';
	},
	1
);
add_filter( 'kayzart_ai_sdk_present', 'connectors' === $kayzart_onboarding_mode ? '__return_true' : '__return_false' );
add_filter( 'kayzart_ai_provider_configured', $kayzart_onboarding_ready && 'connectors' === $kayzart_onboarding_mode ? '__return_true' : '__return_false' );
add_filter(
	'pre_option_kayzart_openai_api_key',
	static function () use ( $kayzart_onboarding_ready, $kayzart_onboarding_mode ) {
		return $kayzart_onboarding_ready && 'connectors' !== $kayzart_onboarding_mode ? 'sk-e2e-fixture-never-send' : '';
	}
);
foreach ( array( 'feature_enabled', 'scheduler_present', 'mbstring_present', 'dom_present' ) as $kayzart_onboarding_gate ) {
	add_filter( 'kayzart_ai_' . $kayzart_onboarding_gate, '__return_true' );
}
