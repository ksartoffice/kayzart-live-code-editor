<?php
/**
 * Recoverable/terminal error raised by AI edit tools.
 *
 * Mirrors the AgentError type from the legacy kayzart-server implementation.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown by AI edit tools.
 *
 * A "retryable" error is one the model may recover from by inspecting the
 * document and trying a different instruction; a non-retryable error should
 * abort the current agent turn handling for that tool call.
 */
class Ai_Tool_Error extends \Exception {

	/**
	 * Whether the model may retry after this error.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructor.
	 *
	 * @param string $message   Human readable error message.
	 * @param bool   $retryable Whether the model may retry.
	 */
	public function __construct( string $message, bool $retryable = false ) {
		parent::__construct( $message );
		$this->retryable = $retryable;
	}

	/**
	 * Whether the model may retry after this error.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
