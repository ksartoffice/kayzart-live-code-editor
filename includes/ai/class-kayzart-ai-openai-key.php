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
	 * Whether WordPress can manage AI providers for this site itself.
	 *
	 * Deliberately does not consult Ai_Availability::is_provider_configured():
	 * that probes the provider, and a site that has simply not filled in
	 * Connectors yet is exactly the site this rule exists for. Checking the SDK
	 * rather than the version alone keeps a 7.0 site that has no AI Client from
	 * losing the direct field while having no Connectors screen to use instead.
	 */
	public static function connectors_available(): bool {
		global $wp_version;

		return version_compare( (string) $wp_version, '7.0', '>=' ) && Ai_Availability::is_sdk_present();
	}

	/**
	 * Whether this site may still be given a direct OpenAI key through the admin.
	 *
	 * Once WordPress can manage providers itself, Kayzart stops offering a second
	 * place to put a credential. Sites upgraded from 6.9 keep managing the key
	 * they already saved, including replacing it, so an upgrade never strands a
	 * credential with no way to see or remove it. Removing that key is one-way:
	 * the field does not come back, and Connectors takes over from there.
	 *
	 * The settings field, its label and {@see self::sanitize()} all gate on this,
	 * so what is shown and what is accepted can never disagree.
	 *
	 * @return bool
	 */
	public static function is_entry_allowed(): bool {
		$allowed = ! self::connectors_available() || 'database' === self::source();

		/**
		 * Filter whether the direct OpenAI key may still be entered here.
		 *
		 * Returning true restores the field, and the matching write, on a site
		 * where Connectors cannot serve text generation.
		 *
		 * @param bool $allowed Whether direct key entry is offered.
		 */
		return (bool) apply_filters( 'kayzart_ai_show_direct_key_field', $allowed );
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
		if ( ! self::is_entry_allowed() ) {
			add_settings_error( self::OPTION, 'kayzart_openai_key_not_offered', __( 'This site manages AI providers through WordPress Connectors, so a direct OpenAI key cannot be saved here.', 'kayzart-live-code-editor' ) );
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

		// wp_set_option_autoload() does not exist on these versions, so the autoload
		// column can only be reached directly. The option cache is cleared below.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
