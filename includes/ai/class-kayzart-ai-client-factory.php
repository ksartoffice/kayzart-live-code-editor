<?php
/**
 * AI backend selection and client construction.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps provider selection consistent between REST creation and workers. */
class Ai_Client_Factory {
	const WORDPRESS = 'wordpress_ai_client';
	const OPENAI    = 'openai_direct';
	const NONE      = 'none';

	/** Resolve the preferred currently usable backend. */
	public static function resolve_backend(): string {
		if ( Ai_Availability::is_wp_client_available() ) {
			return self::WORDPRESS;
		}
		if ( Ai_Availability::is_direct_client_available() ) {
			return self::OPENAI;
		}
		return self::NONE;
	}

	/**
	 * Create the backend captured by a job, falling back only for legacy jobs.
	 *
	 * @param array|null $job Optional stored AI job.
	 * @return Ai_Client_Interface Provider adapter.
	 */
	public static function for_job( ?array $job = null ): Ai_Client_Interface {
		$backend = self::NONE;
		if ( is_array( $job ) && isset( $job['payload_json'] ) ) {
			$payload = json_decode( (string) $job['payload_json'], true );
			if ( is_array( $payload ) && isset( $payload['providerMode'] ) ) {
				$backend = (string) $payload['providerMode'];
			}
		}
		if ( self::NONE === $backend ) {
			$backend = self::resolve_backend();
		}
		if ( self::WORDPRESS === $backend ) {
			return new Ai_Client_Wp();
		}
		if ( self::OPENAI === $backend ) {
			return new Ai_Client_OpenAI();
		}
		// Keep the historical filter seam callable for tests and bespoke
		// integrations. Production REST creation is already gated on availability,
		// and this adapter will fail cleanly if an orphaned legacy job reaches it.
		return new Ai_Client_Wp();
	}
}
