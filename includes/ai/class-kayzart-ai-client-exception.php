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
	 * Constructor.
	 *
	 * @param string $message   Error message.
	 * @param bool   $retryable Whether the caller may retry.
	 */
	public function __construct( string $message, bool $retryable = false ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
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
