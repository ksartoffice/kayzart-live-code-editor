<?php
/**
 * Detects whether the WordPress AI Client is usable on this site.
 *
 * The AI editing feature is gated on the WordPress 7.0 AI Client SDK, a
 * configured provider, Action Scheduler, and the Kayzart feature gate.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Client availability checks.
 */
class Ai_Availability {

	/**
	 * Whether the Kayzart AI feature is enabled by site policy.
	 *
	 * @return bool
	 */
	public static function is_feature_enabled(): bool {
		/**
		 * Filter whether Kayzart AI editing is enabled.
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		return (bool) apply_filters( 'kayzart_ai_feature_enabled', true );
	}

	/**
	 * Whether the AI Client SDK is loaded on this request.
	 *
	 * The WordPress-native prompt function is the stable public entry point.
	 *
	 * @return bool
	 */
	public static function is_sdk_present(): bool {
		$present = function_exists( 'wp_ai_client_prompt' );

		/**
		 * Filter AI Client SDK presence detection.
		 *
		 * Allows overriding detection in tests or bespoke integrations.
		 *
		 * @param bool $present Whether the SDK appears to be present.
		 */
		return (bool) apply_filters( 'kayzart_ai_sdk_present', $present );
	}

	/**
	 * Whether a usable AI provider is configured (e.g. via Connectors).
	 *
	 * @return bool
	 */
	public static function is_provider_configured(): bool {
		$configured = false;
		if ( function_exists( 'wp_ai_client_prompt' ) && self::is_sdk_present() ) {
			try {
				$builder    = wp_ai_client_prompt( 'Kayzart AI availability check.' );
				$configured = true === $builder->is_supported_for_text_generation();
			} catch ( \Throwable $error ) {
				$configured = false;
			}
		}

		/**
		 * Filter whether an AI provider is configured.
		 *
		 * @param bool $configured Whether a provider is configured.
		 */
		return (bool) apply_filters( 'kayzart_ai_provider_configured', $configured );
	}

	/**
	 * Whether the Action Scheduler API required by AI jobs is loaded.
	 *
	 * @return bool
	 */
	public static function is_scheduler_present(): bool {
		$present = function_exists( 'as_enqueue_async_action' );

		/**
		 * Filter Action Scheduler presence detection.
		 *
		 * @param bool $present Whether Action Scheduler appears to be present.
		 */
		return (bool) apply_filters( 'kayzart_ai_scheduler_present', $present );
	}

	/**
	 * Return all AI availability checks and their combined result.
	 *
	 * @return array{feature_enabled:bool,sdk_present:bool,provider_configured:bool,scheduler_present:bool,available:bool}
	 */
	public static function get_status(): array {
		$feature_enabled     = self::is_feature_enabled();
		$sdk_present         = self::is_sdk_present();
		$provider_configured = self::is_provider_configured();
		$scheduler_present   = self::is_scheduler_present();

		return array(
			'feature_enabled'     => $feature_enabled,
			'sdk_present'         => $sdk_present,
			'provider_configured' => $provider_configured,
			'scheduler_present'   => $scheduler_present,
			'available'           => $feature_enabled && $sdk_present && $provider_configured && $scheduler_present,
		);
	}

	/**
	 * Whether AI editing can run right now.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		$status = self::get_status();

		return $status['available'];
	}
}
