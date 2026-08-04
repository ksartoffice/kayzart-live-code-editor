<?php
/**
 * Agent-loop level error.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception raised by the agent loop itself.
 *
 * Distinct from {@see Ai_Tool_Error} (recoverable tool-argument problems fed
 * back to the model) and {@see Ai_Client_Exception} (transport/model failures):
 * this represents an unrecoverable loop outcome such as "no edits were applied"
 * or "turn limit exceeded".
 */
class Ai_Agent_Error extends \Exception {

	/**
	 * Whether the overall request may be retried.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Machine-readable cause, used to pick a translated message for the user.
	 *
	 * @var string
	 */
	private $code_key;

	/**
	 * Constructor.
	 *
	 * @param string $message   Error message.
	 * @param bool   $retryable Whether the request may be retried.
	 * @param string $code_key  Machine-readable cause, e.g. 'max_turns'.
	 */
	public function __construct( string $message, bool $retryable = false, string $code_key = '' ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
		$this->code_key  = $code_key;
	}

	/**
	 * Whether the overall request may be retried.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}

	/**
	 * Machine-readable cause. Empty when the message is the only detail.
	 *
	 * `Exception::getCode()` is an int, so the key lives on its own accessor.
	 *
	 * @return string
	 */
	public function get_code_key(): string {
		return $this->code_key;
	}
}
