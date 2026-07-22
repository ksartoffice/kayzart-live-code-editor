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
	 * Constructor.
	 *
	 * @param string $message   Error message.
	 * @param bool   $retryable Whether the request may be retried.
	 */
	public function __construct( string $message, bool $retryable = false ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
	}

	/**
	 * Whether the overall request may be retried.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
