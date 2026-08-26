<?php
/**
 * Detects whether an AI editing backend is usable on this site.
 *
 * WordPress 7.0 and later prefer the WordPress AI Client. Sites without a
 * configured connector can fall back to Kayzart's direct OpenAI integration.
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
	 * Whether the multibyte functions required by AI editing are available.
	 *
	 * @return bool
	 */
	public static function is_mbstring_present(): bool {
		$present = function_exists( 'mb_check_encoding' )
			&& function_exists( 'mb_convert_encoding' )
			&& function_exists( 'mb_strlen' )
			&& function_exists( 'mb_strpos' )
			&& function_exists( 'mb_substr' );

		/**
		 * Filter mbstring availability for AI editing.
		 *
		 * @param bool $present Whether all required multibyte functions are available.
		 */
		return (bool) apply_filters( 'kayzart_ai_mbstring_present', $present );
	}

	/**
	 * Whether the DOM and libxml APIs required by AI output policy checks are available.
	 *
	 * @return bool
	 */
	public static function is_dom_present(): bool {
		$present = class_exists( '\\DOMDocument' )
			&& function_exists( 'libxml_use_internal_errors' )
			&& function_exists( 'libxml_clear_errors' );

		/**
		 * Filter DOM/libxml availability for AI editing.
		 *
		 * @param bool $present Whether the DOM and libxml APIs are available.
		 */
		return (bool) apply_filters( 'kayzart_ai_dom_present', $present );
	}

	/**
	 * Whether the shared background and output-policy runtime is usable.
	 */
	private static function is_runtime_available(): bool {

		return self::is_feature_enabled() && self::is_scheduler_present() && self::is_mbstring_present() && self::is_dom_present();
	}

	/** Whether the WordPress AI Client backend can serve a request. */
	public static function is_wp_client_available(): bool {

		global $wp_version;
		return version_compare( (string) $wp_version, '7.0', '>=' )
			&& self::is_runtime_available()
			&& self::is_sdk_present()
			&& self::is_provider_configured();
	}

	/** Whether the direct OpenAI backend can serve a request. */
	public static function is_direct_client_available(): bool {

		return self::is_runtime_available() && Ai_OpenAI_Key::is_configured();
	}

	/**
	 * Return all AI availability checks and their combined result.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_status(): array {
		$feature_enabled     = self::is_feature_enabled();
		$sdk_present         = self::is_sdk_present();
		$provider_configured = self::is_provider_configured();
		$scheduler_present   = self::is_scheduler_present();
		$mbstring_present    = self::is_mbstring_present();
		$dom_present         = self::is_dom_present();

		$connector_configured = $sdk_present && $provider_configured;
		$direct_configured    = Ai_OpenAI_Key::is_configured();
		$backend              = Ai_Client_Factory::resolve_backend();
		$system_available     = $feature_enabled && $scheduler_present && $mbstring_present && $dom_present;
		$available            = Ai_Client_Factory::NONE !== $backend;

		return array(
			'feature_enabled'       => $feature_enabled,
			'sdk_present'           => $sdk_present,
			'provider_configured'   => $connector_configured || $direct_configured,
			'connector_configured'  => $connector_configured,
			'direct_key_configured' => $direct_configured,
			'direct_key_source'     => Ai_OpenAI_Key::source(),
			'backend'               => $backend,
			'scheduler_present'     => $scheduler_present,
			'mbstring_present'      => $mbstring_present,
			'dom_present'           => $dom_present,
			'setup_state'           => $available ? 'ready' : ( $system_available ? 'setup_required' : 'system_unavailable' ),
			'available'             => $available,
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
