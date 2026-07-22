<?php
/**
 * Scriptable fake AI client for tests.
 *
 * Returns queued results in order and records the arguments of each call so the
 * agent loop can be unit-tested without a live model or the SDK.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A deterministic {@see Ai_Client_Interface} implementation for tests.
 */
class Ai_Client_Fake implements Ai_Client_Interface {

	/**
	 * Queued results to return, oldest first.
	 *
	 * @var array<int,array>
	 */
	private $queue = array();

	/**
	 * Recorded call arguments.
	 *
	 * @var array<int,array>
	 */
	private $calls = array();

	/**
	 * Whether the fake reports itself as available.
	 *
	 * @var bool
	 */
	private $available = true;

	/**
	 * Constructor.
	 *
	 * @param array<int,array> $results Initial queued results.
	 */
	public function __construct( array $results = array() ) {
		foreach ( $results as $result ) {
			$this->queue_result( $result );
		}
	}

	/**
	 * Queue a result (raw shape; normalized on return).
	 *
	 * @param array $result Result with optional toolCalls/text/usage keys.
	 * @return void
	 */
	public function queue_result( array $result ): void {
		$this->queue[] = $result;
	}

	/**
	 * Convenience: queue a tool-calling turn.
	 *
	 * @param array $tool_calls Tool calls built with {@see Ai_Message::tool_call()}.
	 * @return void
	 */
	public function queue_tool_calls( array $tool_calls ): void {
		$this->queue_result( array( 'toolCalls' => $tool_calls ) );
	}

	/**
	 * Convenience: queue a final text turn (no tool calls).
	 *
	 * @param string $text Assistant text.
	 * @return void
	 */
	public function queue_final_text( string $text ): void {
		$this->queue_result( array( 'text' => $text ) );
	}

	/**
	 * Set the availability flag.
	 *
	 * @param bool $available Availability.
	 * @return void
	 */
	public function set_available( bool $available ): void {
		$this->available = $available;
	}

	/**
	 * Recorded calls, each: array{messages:array, tools:array, options:array}.
	 *
	 * @return array<int,array>
	 */
	public function calls(): array {
		return $this->calls;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * Return the next queued result and record the call arguments.
	 *
	 * @param array $messages Normalized conversation messages.
	 * @param array $tools    Tool definitions.
	 * @param array $options  Generation options.
	 * @return array Normalized result.
	 *
	 * @throws Ai_Client_Exception When the result queue is empty.
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array {
		$this->calls[] = array(
			'messages' => $messages,
			'tools'    => $tools,
			'options'  => $options,
		);

		if ( count( $this->queue ) === 0 ) {
			throw new Ai_Client_Exception( 'Ai_Client_Fake has no queued result for this call.', false );
		}

		return self::normalize_result( array_shift( $this->queue ) );
	}

	/**
	 * Normalize a queued result into the interface result shape.
	 *
	 * @param array $result Raw queued result.
	 * @return array
	 */
	private static function normalize_result( array $result ): array {
		$tool_calls = array();
		if ( isset( $result['toolCalls'] ) && is_array( $result['toolCalls'] ) ) {
			foreach ( $result['toolCalls'] as $call ) {
				if ( ! is_array( $call ) ) {
					continue;
				}
				$tool_calls[] = array(
					'id'   => isset( $call['id'] ) ? (string) $call['id'] : '',
					'name' => isset( $call['name'] ) ? (string) $call['name'] : '',
					'args' => isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array(),
				);
			}
		}

		return array(
			'toolCalls' => $tool_calls,
			'text'      => isset( $result['text'] ) ? (string) $result['text'] : '',
			'usage'     => self::normalize_usage( isset( $result['usage'] ) ? $result['usage'] : array() ),
			'model'     => isset( $result['model'] ) ? (string) $result['model'] : '',
		);
	}

	/**
	 * Normalize a usage payload with zero defaults.
	 *
	 * @param mixed $usage Raw usage.
	 * @return array
	 */
	private static function normalize_usage( $usage ): array {
		$usage = is_array( $usage ) ? $usage : array();
		return array(
			'inputTokens'           => isset( $usage['inputTokens'] ) ? (int) $usage['inputTokens'] : 0,
			'cachedInputTokens'     => isset( $usage['cachedInputTokens'] ) ? (int) $usage['cachedInputTokens'] : 0,
			'outputTokens'          => isset( $usage['outputTokens'] ) ? (int) $usage['outputTokens'] : 0,
			'reasoningOutputTokens' => isset( $usage['reasoningOutputTokens'] ) ? (int) $usage['reasoningOutputTokens'] : 0,
		);
	}
}
