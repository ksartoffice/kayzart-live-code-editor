<?php
/**
 * Secure lookup and storage helpers for the direct OpenAI API key.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves the direct OpenAI credential without exposing it to the browser. */
class Ai_OpenAI_Key {
	const OPTION = 'kayzart_openai_api_key';
	const NAME   = 'KAYZART_OPENAI_API_KEY';

	/**
	 * Prevent recursive sanitization while creating the option explicitly.
	 *
	 * @var bool
	 */
	private static $storing = false;

	/** Return the effective API key. */
	public static function get(): string {
		$environment = getenv( self::NAME );
		if ( is_string( $environment ) && '' !== trim( $environment ) ) {
			return trim( $environment );
		}

		if ( defined( self::NAME ) ) {
			$constant = constant( self::NAME );
			if ( is_string( $constant ) && '' !== trim( $constant ) ) {
				return trim( $constant );
			}
		}

		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) ? trim( $stored ) : '';
	}

	/** Return the effective credential source without returning the credential. */
	public static function source(): string {
		$environment = getenv( self::NAME );
		if ( is_string( $environment ) && '' !== trim( $environment ) ) {
			return 'environment';
		}
		if ( defined( self::NAME ) ) {
			$constant = constant( self::NAME );
			if ( is_string( $constant ) && '' !== trim( $constant ) ) {
				return 'constant';
			}
		}
		$stored = get_option( self::OPTION, '' );
		return is_string( $stored ) && '' !== trim( $stored ) ? 'database' : 'none';
	}

	/** Whether a direct OpenAI credential is configured. */
	public static function is_configured(): bool {
		return '' !== self::get();
	}

	/**
	 * Sanitize a Settings API value while preserving a masked/blank credential.
	 *
	 * The initial add is performed explicitly with autoload disabled so this
	 * secret is never loaded into every WordPress request on older core versions.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitize( $value ): string {
		if ( self::$storing ) {
			return is_string( $value ) ? $value : '';
		}

		$current = get_option( self::OPTION, '' );
		$current = is_string( $current ) ? $current : '';
		$value   = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';

		if ( '' === $value ) {
			return $current;
		}
		if ( strlen( $value ) > 512 || preg_match( '/[\x00-\x20\x7f]/', $value ) ) {
			add_settings_error( self::OPTION, 'kayzart_openai_key_invalid', __( 'Enter a valid OpenAI API key without spaces.', 'kayzart-live-code-editor' ) );
			return $current;
		}

		self::store_without_autoload( $value );
		return $value;
	}

	/**
	 * Create the credential option without autoloading, or migrate an existing row.
	 *
	 * The add_option() function is attempted directly instead of using get_option() as an
	 * existence check because registered setting defaults affect get_option().
	 *
	 * @param string $value Validated credential value.
	 */
	private static function store_without_autoload( string $value ): void {
		self::$storing = true;
		try {
			if ( add_option( self::OPTION, $value, '', 'no' ) ) {
				return;
			}

			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::OPTION, false );
				return;
			}

			self::disable_autoload_legacy();
		} finally {
			self::$storing = false;
		}
	}

	/** Disable autoload on WordPress versions without wp_set_option_autoload(). */
	private static function disable_autoload_legacy(): void {
		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => self::OPTION ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $updated || 0 === $updated ) {
			return;
		}

		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
