<?php
/**
 * Contract between the AI agent loop and the underlying model provider.
 *
 * The agent loop ({@see Ai_Agent}) depends only on this interface, never on a
 * concrete SDK. This keeps the loop testable with {@see Ai_Client_Fake} and
 * lets the WordPress AI Client adapter ({@see Ai_Client_Wp}) evolve
 * independently as the SDK changes.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A provider-agnostic single-turn generation client.
 */
interface Ai_Client_Interface {

	/**
	 * Whether the client can currently serve requests.
	 *
	 * Implementations check for SDK availability and a configured provider.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Run one model turn.
	 *
	 * @param array $messages Normalized conversation history built with
	 *                        {@see Ai_Message}. Does not include the system
	 *                        instruction; pass that via $options.
	 * @param array $tools    Tool definitions from
	 *                        {@see Ai_Tool_Schema::build_tool_definitions()}.
	 *                        Pass an empty array to disable tool calling
	 *                        (finalization turns).
	 * @param array $options  Optional settings:
	 *                        - systemInstruction string  System prompt text.
	 *                        - jsonSchema        array   JSON schema to force
	 *                          structured output (finalization turn); null/absent
	 *                          for free-form/tool turns.
	 *                        - modelPreference   array   Optional model hints.
	 *
	 * @return array Normalized result:
	 *   array{
	 *     toolCalls: array<int, array{id:string, name:string, args:array}>,
	 *     text: string,
	 *     usage: array{
	 *       inputTokens:int, cachedInputTokens:int,
	 *       outputTokens:int, reasoningOutputTokens:int
	 *     }
	 *   }
	 *
	 * @throws Ai_Client_Exception When the model cannot be reached or returns
	 *                             an unusable response.
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array;
}
