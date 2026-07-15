<?php
/**
 * Detects whether the WordPress AI Client is usable on this site.
 *
 * The AI editing feature is gated on the presence of the AI Client SDK
 * (WordPress 7.0 core, or WordPress 6.9 with the Gutenberg plugin providing the
 * SDK) and a configured provider. When unavailable, the feature stays hidden
 * and the plugin continues to work as a plain HTML/CSS/JS editor.
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
	 * Whether the AI Client SDK is loaded on this request.
	 *
	 * The concrete symbol names are verified against the installed SDK version
	 * by the adapter; any one of them signals SDK presence.
	 *
	 * @return bool
	 */
	public static function is_sdk_present(): bool {
		$present = function_exists( 'wp_ai_client_prompt' )
			|| class_exists( 'WordPress\\AI_Client\\AI_Client' )
			|| class_exists( 'WordPress\\AiClient\\AiClient' );

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
	 * The real Connectors query is wired in a later phase; until then this
	 * defers to SDK presence and a filter so environments can opt in.
	 *
	 * @return bool
	 */
	public static function is_provider_configured(): bool {
		/**
		 * Filter whether an AI provider is configured.
		 *
		 * @param bool $configured Whether a provider is configured.
		 */
		return (bool) apply_filters( 'kayzart_ai_provider_configured', self::is_sdk_present() );
	}

	/**
	 * Whether AI editing can run right now.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return self::is_sdk_present() && self::is_provider_configured();
	}
}
