<?php
/**
 * Normalized conversation message helpers for the AI agent loop.
 *
 * The agent loop owns its conversation history in this provider-agnostic
 * shape. The concrete {@see Ai_Client_Interface} adapter is responsible for
 * translating these arrays into whatever message objects the underlying SDK
 * expects, and for translating model output back into this shape.
 *
 * Message shapes:
 *   system    : array{role:'system', text:string}
 *   user      : array{role:'user', text:string}
 *   assistant : array{role:'assistant', text:string, toolCalls:array<int,ToolCall>}
 *   tool      : array{role:'tool', toolResponses:array<int,ToolResponse>}
 *
 *   ToolCall     : array{id:string, name:string, args:array}
 *   ToolResponse : array{callId:string, name:string, output:mixed}
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory helpers for normalized conversation messages.
 */
class Ai_Message {

	const ROLE_SYSTEM    = 'system';
	const ROLE_USER      = 'user';
	const ROLE_ASSISTANT = 'assistant';
	const ROLE_TOOL      = 'tool';

	/**
	 * Build a system message.
	 *
	 * @param string $text System instruction text.
	 * @return array
	 */
	public static function system( string $text ): array {
		return array(
			'role' => self::ROLE_SYSTEM,
			'text' => $text,
		);
	}

	/**
	 * Build a user message.
	 *
	 * @param string $text User text.
	 * @return array
	 */
	public static function user( string $text ): array {
		return array(
			'role' => self::ROLE_USER,
			'text' => $text,
		);
	}

	/**
	 * Build an assistant message, optionally carrying tool calls.
	 *
	 * @param string $text       Assistant text (may be empty when only calling tools).
	 * @param array  $tool_calls List of tool calls (see tool_call()).
	 * @return array
	 */
	public static function assistant( string $text, array $tool_calls = array() ): array {
		return array(
			'role'      => self::ROLE_ASSISTANT,
			'text'      => $text,
			'toolCalls' => array_values( $tool_calls ),
		);
	}

	/**
	 * Build a tool message carrying one or more tool responses.
	 *
	 * @param array $tool_responses List of tool responses (see tool_response()).
	 * @return array
	 */
	public static function tool( array $tool_responses ): array {
		return array(
			'role'          => self::ROLE_TOOL,
			'toolResponses' => array_values( $tool_responses ),
		);
	}

	/**
	 * Build a tool call entry.
	 *
	 * @param string $id   Provider call id (used to correlate the response).
	 * @param string $name Tool name.
	 * @param array  $args Decoded tool arguments.
	 * @return array
	 */
	public static function tool_call( string $id, string $name, array $args ): array {
		return array(
			'id'   => $id,
			'name' => $name,
			'args' => $args,
		);
	}

	/**
	 * Build a tool response entry.
	 *
	 * @param string $call_id Call id this response answers.
	 * @param string $name    Tool name.
	 * @param mixed  $output  Tool output payload (JSON-serializable).
	 * @return array
	 */
	public static function tool_response( string $call_id, string $name, $output ): array {
		return array(
			'callId' => $call_id,
			'name'   => $name,
			'output' => $output,
		);
	}
}
