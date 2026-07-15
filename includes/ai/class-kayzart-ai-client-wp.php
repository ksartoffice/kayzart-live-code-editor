<?php
/**
 * WordPress AI Client adapter (reference implementation).
 *
 * Implements {@see Ai_Client_Interface} on top of the WordPress 7.0 AI Client /
 * PHP AI Client SDK.
 *
 * UNVERIFIED: the SDK method and DTO names used below are taken from the
 * documented API and MUST be verified against the installed SDK version. Live
 * generation is therefore disabled by default and only runs when the
 * `kayzart_ai_wp_client_enabled` filter returns true (used during verification);
 * otherwise {@see generate()} throws. All SDK symbols are referenced only inside
 * the guarded code path, so this file loads safely when the SDK is absent, and
 * the agent loop is exercised with {@see Ai_Client_Fake} until verification.
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
	 * Whether the client can currently serve requests.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return Ai_Availability::is_available();
	}

	/**
	 * Run one model turn against the WordPress AI Client.
	 *
	 * @param array $messages Normalized conversation messages.
	 * @param array $tools    Tool definitions.
	 * @param array $options  Generation options.
	 * @return array Normalized result (toolCalls/text/usage).
	 *
	 * @throws Ai_Client_Exception When the SDK is unavailable, disabled, or the
	 *                             request fails.
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array {
		if ( ! Ai_Availability::is_sdk_present() ) {
			throw new Ai_Client_Exception( 'WordPress AI Client SDK is not available.', false );
		}
		if ( ! self::live_generation_enabled() ) {
			throw new Ai_Client_Exception(
				'Ai_Client_Wp live generation is disabled until the SDK mapping is verified. Enable it via the kayzart_ai_wp_client_enabled filter after verification.',
				false
			);
		}

		try {
			$sdk_result = $this->run_sdk_turn( $messages, $tools, $options );
		} catch ( Ai_Client_Exception $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			throw new Ai_Client_Exception( 'AI Client request failed: ' . $error->getMessage(), true );
		}

		return array(
			'toolCalls' => $this->extract_tool_calls( $sdk_result ),
			'text'      => $this->extract_text( $sdk_result ),
			'usage'     => $this->extract_usage( $sdk_result ),
		);
	}

	/**
	 * Whether live generation is enabled (opt-in during SDK verification).
	 *
	 * @return bool
	 */
	private static function live_generation_enabled(): bool {
		/**
		 * Enable the (unverified) live WordPress AI Client generation path.
		 *
		 * @param bool $enabled Whether live generation is enabled.
		 */
		return (bool) apply_filters( 'kayzart_ai_wp_client_enabled', false );
	}

	/**
	 * SDK-SEAM: run one turn through the AI Client and return the raw result.
	 *
	 * @param array $messages Normalized messages.
	 * @param array $tools    Tool definitions.
	 * @param array $options  Options (systemInstruction/jsonSchema/modelPreference).
	 * @return mixed SDK result object.
	 */
	private function run_sdk_turn( array $messages, array $tools, array $options ) {
		$sdk_messages = $this->to_sdk_messages( $messages );
		$declarations = $this->to_function_declarations( $tools );

		$builder = \WordPress\AI_Client\AI_Client::prompt( $sdk_messages );

		if ( count( $declarations ) > 0 ) {
			$builder = $builder->using_function_declarations( ...$declarations );
		}
		if ( isset( $options['systemInstruction'] ) && '' !== (string) $options['systemInstruction'] ) {
			$builder = $builder->using_system_instruction( (string) $options['systemInstruction'] );
		}
		if ( isset( $options['jsonSchema'] ) && is_array( $options['jsonSchema'] ) ) {
			$builder = $builder->as_json_response( self::schema_to_object( $options['jsonSchema'] ) );
		}

		return $builder->generate_text_result();
	}

	/**
	 * SDK-SEAM: convert normalized messages into SDK Message objects.
	 *
	 * Verify against the SDK: Message, MessagePart, MessageRoleEnum and the
	 * function-call / function-response part constructors.
	 *
	 * @param array $messages Normalized messages.
	 * @return array SDK message objects.
	 */
	private function to_sdk_messages( array $messages ): array {
		$sdk_messages = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'] ) ) {
				continue;
			}
			$role = (string) $message['role'];
			if ( Ai_Message::ROLE_TOOL === $role ) {
				$parts = $this->tool_response_parts( isset( $message['toolResponses'] ) ? (array) $message['toolResponses'] : array() );
				$enum  = \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
			} else {
				$parts = $this->text_and_call_parts( $message );
				$enum  = Ai_Message::ROLE_ASSISTANT === $role
					? \WordPress\AiClient\Messages\Enums\MessageRoleEnum::model()
					: \WordPress\AiClient\Messages\Enums\MessageRoleEnum::user();
			}
			$sdk_messages[] = new \WordPress\AiClient\Messages\DTO\Message( $enum, $parts );
		}
		return $sdk_messages;
	}

	/**
	 * SDK-SEAM: build text and function-call parts for a message.
	 *
	 * @param array $message Normalized message.
	 * @return array SDK MessagePart objects.
	 */
	private function text_and_call_parts( array $message ): array {
		$parts = array();
		$text  = isset( $message['text'] ) ? (string) $message['text'] : '';
		if ( '' !== $text ) {
			$parts[] = new \WordPress\AiClient\Messages\DTO\MessagePart( $text );
		}
		$tool_calls = isset( $message['toolCalls'] ) && is_array( $message['toolCalls'] ) ? $message['toolCalls'] : array();
		foreach ( $tool_calls as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}
			$function_call = new \WordPress\AiClient\Tools\DTO\FunctionCall(
				isset( $call['id'] ) ? (string) $call['id'] : '',
				isset( $call['name'] ) ? (string) $call['name'] : '',
				isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array()
			);
			$parts[]       = new \WordPress\AiClient\Messages\DTO\MessagePart( $function_call );
		}
		return $parts;
	}

	/**
	 * SDK-SEAM: build function-response parts for a tool message.
	 *
	 * @param array $tool_responses Normalized tool responses.
	 * @return array SDK MessagePart objects.
	 */
	private function tool_response_parts( array $tool_responses ): array {
		$parts = array();
		foreach ( $tool_responses as $response ) {
			if ( ! is_array( $response ) ) {
				continue;
			}
			$function_response = new \WordPress\AiClient\Tools\DTO\FunctionResponse(
				isset( $response['callId'] ) ? (string) $response['callId'] : '',
				isset( $response['name'] ) ? (string) $response['name'] : '',
				isset( $response['output'] ) ? $response['output'] : null
			);
			$parts[]           = new \WordPress\AiClient\Messages\DTO\MessagePart( $function_response );
		}
		return $parts;
	}

	/**
	 * SDK-SEAM: convert tool definitions into SDK FunctionDeclaration objects.
	 *
	 * @param array $tools Tool definitions from Ai_Tool_Schema.
	 * @return array SDK FunctionDeclaration objects.
	 */
	private function to_function_declarations( array $tools ): array {
		$declarations = array();
		foreach ( $tools as $tool ) {
			if ( ! isset( $tool['name'] ) ) {
				continue;
			}
			$parameters     = isset( $tool['parameters'] ) && is_array( $tool['parameters'] )
				? self::schema_to_object( $tool['parameters'] )
				: new \stdClass();
			$declarations[] = new \WordPress\AiClient\Tools\DTO\FunctionDeclaration(
				(string) $tool['name'],
				isset( $tool['description'] ) ? (string) $tool['description'] : '',
				$parameters
			);
		}
		return $declarations;
	}

	/**
	 * SDK-SEAM: extract normalized tool calls from an SDK result.
	 *
	 * @param mixed $sdk_result SDK result object.
	 * @return array Normalized tool calls.
	 */
	private function extract_tool_calls( $sdk_result ): array {
		$tool_calls = array();
		foreach ( $this->result_candidates( $sdk_result ) as $candidate ) {
			foreach ( $this->candidate_parts( $candidate ) as $part ) {
				$call = is_object( $part ) && method_exists( $part, 'getFunctionCall' ) ? $part->getFunctionCall() : null;
				if ( null === $call ) {
					continue;
				}
				$tool_calls[] = array(
					'id'   => method_exists( $call, 'getId' ) ? (string) $call->getId() : '',
					'name' => method_exists( $call, 'getName' ) ? (string) $call->getName() : '',
					'args' => method_exists( $call, 'getArgs' ) && is_array( $call->getArgs() ) ? $call->getArgs() : array(),
				);
			}
		}
		return $tool_calls;
	}

	/**
	 * SDK-SEAM: extract assistant text from an SDK result.
	 *
	 * @param mixed $sdk_result SDK result object.
	 * @return string
	 */
	private function extract_text( $sdk_result ): string {
		if ( is_object( $sdk_result ) && method_exists( $sdk_result, 'toText' ) ) {
			return trim( (string) $sdk_result->toText() );
		}
		return '';
	}

	/**
	 * SDK-SEAM: extract token usage from an SDK result.
	 *
	 * @param mixed $sdk_result SDK result object.
	 * @return array Usage totals.
	 */
	private function extract_usage( $sdk_result ): array {
		$empty = array(
			'inputTokens'           => 0,
			'cachedInputTokens'     => 0,
			'outputTokens'          => 0,
			'reasoningOutputTokens' => 0,
		);
		if ( ! is_object( $sdk_result ) || ! method_exists( $sdk_result, 'getTokenUsage' ) ) {
			return $empty;
		}
		$usage = $sdk_result->getTokenUsage();
		if ( ! is_object( $usage ) ) {
			return $empty;
		}
		return array(
			'inputTokens'           => method_exists( $usage, 'getInputTokens' ) ? (int) $usage->getInputTokens() : 0,
			'cachedInputTokens'     => method_exists( $usage, 'getCachedInputTokens' ) ? (int) $usage->getCachedInputTokens() : 0,
			'outputTokens'          => method_exists( $usage, 'getOutputTokens' ) ? (int) $usage->getOutputTokens() : 0,
			'reasoningOutputTokens' => method_exists( $usage, 'getReasoningTokens' ) ? (int) $usage->getReasoningTokens() : 0,
		);
	}

	/**
	 * SDK-SEAM: get candidate objects from an SDK result.
	 *
	 * @param mixed $sdk_result SDK result object.
	 * @return array
	 */
	private function result_candidates( $sdk_result ): array {
		if ( is_object( $sdk_result ) && method_exists( $sdk_result, 'getCandidates' ) ) {
			$candidates = $sdk_result->getCandidates();
			return is_array( $candidates ) ? $candidates : array();
		}
		return array();
	}

	/**
	 * SDK-SEAM: get message parts from a candidate object.
	 *
	 * @param mixed $candidate SDK candidate object.
	 * @return array
	 */
	private function candidate_parts( $candidate ): array {
		if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'getMessage' ) ) {
			return array();
		}
		$message = $candidate->getMessage();
		if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
			return array();
		}
		$parts = $message->getParts();
		return is_array( $parts ) ? $parts : array();
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
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}
		return true;
	}
}
