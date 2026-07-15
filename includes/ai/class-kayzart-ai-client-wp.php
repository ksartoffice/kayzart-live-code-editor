<?php
/**
 * WordPress AI Client adapter (reference implementation).
 *
 * Implements {@see Ai_Client_Interface} on top of the WordPress 7.0 AI Client /
 * PHP AI Client SDK. The pure schema helper {@see schema_to_object()} is used
 * now and fully tested; the live request/response mapping is disabled until it
 * is implemented against a verified SDK build (see below), so the agent loop is
 * exercised with {@see Ai_Client_Fake} in the meantime.
 *
 * SDK integration points to implement and verify against the installed SDK
 * version before enabling live generation:
 *
 *   1. Request: build the prompt via
 *      `\WordPress\AI_Client\AI_Client::prompt( $messages )`, attach tools with
 *      `->using_function_declarations( ...$declarations )`, the system prompt
 *      with `->using_system_instruction( $text )`, and (finalization turns)
 *      structured output with `->as_json_response( $schema )`; then call
 *      `->generate_text_result()`.
 *   2. Messages: convert the normalized {@see Ai_Message} history into SDK
 *      `Message` / `MessagePart` objects, including function-call parts
 *      (assistant turns) and function-response parts (tool turns).
 *   3. Tools: convert each {@see Ai_Tool_Schema} definition into a
 *      `\WordPress\AiClient\Tools\DTO\FunctionDeclaration( name, description,
 *      parameters )`, passing `schema_to_object( $parameters )` so JSON-schema
 *      maps encode as objects.
 *   4. Result: read candidates -> message parts, mapping `getFunctionCall()`
 *      parts into normalized tool calls (id/name/args), assistant text via
 *      `toText()`, and token usage via `getTokenUsage()`.
 *
 * All SDK symbols are referenced only where implemented, so this file loads
 * safely when the SDK is absent.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter backed by the WordPress AI Client.
 */
class Ai_Client_Wp implements Ai_Client_Interface {

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return Ai_Availability::is_available();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws Ai_Client_Exception Always, until the SDK mapping is implemented
	 *                             and verified (see class docblock).
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array {
		unset( $messages, $tools, $options );

		if ( ! Ai_Availability::is_sdk_present() ) {
			throw new Ai_Client_Exception( 'WordPress AI Client SDK is not available.', false );
		}

		throw new Ai_Client_Exception(
			'Ai_Client_Wp live generation is not yet implemented against a verified AI Client SDK build.',
			false
		);
	}

	/**
	 * Recursively convert JSON-schema maps into objects for the SDK.
	 *
	 * JSON Schema `parameters`, `properties` and `items` must serialize as
	 * objects. PHP associative arrays already encode as objects, but an EMPTY
	 * associative array encodes as `[]`; this converts every associative array
	 * (including empty maps such as `get_selected_context`'s `properties`) into
	 * an stdClass, while leaving non-empty sequential lists (`enum`, `required`)
	 * as arrays. Empty arrays are treated as empty objects (`{}`) because the
	 * tool schemas never place an empty list in a convertible position.
	 *
	 * @param mixed $schema Schema fragment.
	 * @return mixed Object/array tree safe for JSON object encoding.
	 */
	public static function schema_to_object( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		if ( count( $schema ) === 0 ) {
			return new \stdClass();
		}

		if ( self::is_list( $schema ) ) {
			return array_map( array( __CLASS__, 'schema_to_object' ), $schema );
		}

		$object = new \stdClass();
		foreach ( $schema as $key => $value ) {
			$object->{$key} = self::schema_to_object( $value );
		}
		return $object;
	}

	/**
	 * Whether an array is a sequential list (0..n integer keys).
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private static function is_list( array $value ): bool {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		$expected = 0;
		foreach ( $value as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}
}
