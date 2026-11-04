<?php
/**
 * Transport/model error raised by an AI client implementation.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when an AI client call fails.
 *
 * Distinct from {@see Ai_Tool_Error}: this represents a failure to talk to the
 * model (SDK missing, transport error, malformed model response), whereas
 * Ai_Tool_Error represents a recoverable tool-argument problem.
 */
class Ai_Client_Exception extends \Exception {

	/**
	 * Whether the caller may retry the request.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Recognized provider billing failure, or an empty string.
	 *
	 * @var string
	 */
	private $billing_error = '';

	/**
	 * Constructor.
	 *
	 * @param string $message       Error message for the server log.
	 * @param bool   $retryable     Whether the caller may retry.
	 * @param string $provider_code Optional structured provider error code.
	 */
	public function __construct( string $message, bool $retryable = false, string $provider_code = '' ) {
		parent::__construct( $message );
		// WordPress currently preserves the provider message but drops its error
		// code. Match explicit billing wording as a fallback; HTTP 429 alone also
		// covers temporary rate limits and must remain retryable.
		if ( 'credit_balance_exhausted' === $provider_code || false !== stripos( $message, 'no credits remaining' ) || false !== stripos( $message, 'credit balance is too low' ) ) {
			$this->billing_error = 'credits';
		} elseif ( in_array( $provider_code, array( 'insufficient_quota', 'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded' ), true ) || false !== stripos( $message, 'exceeded your current quota' ) ) {
			$this->billing_error = 'quota';
		}
		$this->retryable = '' === $this->billing_error && $retryable;
	}

	/**
	 * Return a translated public message without exposing raw provider details.
	 *
	 * @return string
	 */
	public function get_user_message(): string {
		if ( 'credits' === $this->billing_error ) {
			return __( 'The AI provider has no credits remaining. Check the provider billing settings.', 'kayzart-live-code-editor' );
		}
		if ( 'quota' === $this->billing_error ) {
			return __( 'The AI provider billing or usage limit has been reached. Check the provider billing settings and usage limits.', 'kayzart-live-code-editor' );
		}
		return __( 'The AI provider request failed.', 'kayzart-live-code-editor' );
	}

	/**
	 * Whether the caller may retry the request.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
