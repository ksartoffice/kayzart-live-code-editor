<?php
/**
 * WordPress AI Client adapter.
 *
 * Implements {@see Ai_Client_Interface} on top of the WordPress 7.0 AI Client /
 * PHP AI Client SDK.
 *
 * Uses the WordPress-native prompt builder so provider discovery, credentials,
 * HTTP transport and WP_Error conversion stay under WordPress control. SDK
 * symbols are referenced only after the availability guard, so the plugin still
 * loads safely on WordPress versions without the AI Client.
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
	 * @throws Ai_Client_Exception When the SDK is unavailable or the request fails.
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array {
		if ( ! Ai_Availability::is_sdk_present() ) {
			throw new Ai_Client_Exception( 'WordPress AI Client SDK is not available.', false );
		}
		try {
			$sdk_result = $this->run_sdk_turn( $messages, $tools, $options );
		} catch ( Ai_Client_Exception $error ) {
			throw $error;
		} catch ( \Throwable $error ) {
			throw new Ai_Client_Exception( 'AI Client request failed: ' . $error->getMessage(), true );
		}

		try {
			return array(
				'toolCalls' => $this->extract_tool_calls( $sdk_result ),
				'text'      => $this->extract_text( $sdk_result ),
				'usage'     => $this->extract_usage( $sdk_result ),
				'model'     => $this->extract_model( $sdk_result ),
			);
		} catch ( \Throwable $error ) {
			throw new Ai_Client_Exception( 'AI Client returned an invalid result: ' . $error->getMessage(), true );
		}
	}

	/**
	 * SDK-SEAM: run one turn through the AI Client and return the raw result.
	 *
	 * @param array $messages Normalized messages.
	 * @param array $tools    Tool definitions.
	 * @param array $options  Options (systemInstruction/jsonSchema/modelPreference).
	 * @return mixed SDK result object.
	 * @throws Ai_Client_Exception When WordPress returns a generation error.
	 */
	private function run_sdk_turn( array $messages, array $tools, array $options ) {
		$sdk_messages = $this->to_sdk_messages( $messages );
		$declarations = $this->to_function_declarations( $tools );

		$builder = wp_ai_client_prompt( $sdk_messages );

		if ( count( $declarations ) > 0 ) {
			$builder = $builder->using_function_declarations( ...$declarations );
		}
		if ( isset( $options['systemInstruction'] ) && '' !== (string) $options['systemInstruction'] ) {
			$builder = $builder->using_system_instruction( (string) $options['systemInstruction'] );
		}
		if ( isset( $options['jsonSchema'] ) && is_array( $options['jsonSchema'] ) ) {
			$builder = $builder->as_json_response( $options['jsonSchema'] );
		}
		if ( isset( $options['modelPreference'] ) && is_array( $options['modelPreference'] ) ) {
			$preferences = array_values(
				array_filter(
					$options['modelPreference'],
					static function ( $preference ) {
						return is_string( $preference ) && '' !== $preference;
					}
				)
			);
			if ( count( $preferences ) > 0 ) {
				$builder = $builder->using_model_preference( ...$preferences );
			}
		}

		$result = $builder->generate_text_result();
		if ( is_wp_error( $result ) ) {
			$data      = $result->get_error_data();
			$status    = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			$retryable = 408 === $status || 429 === $status || $status >= 500;
			throw new Ai_Client_Exception( $result->get_error_message(), $retryable );
		}

		return $result;
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
			foreach ( $parts as $part ) {
				// OpenAI Responses requires calls and responses to be top-level
				// input items, so every SDK message carries exactly one part.
				$sdk_messages[] = new \WordPress\AiClient\Messages\DTO\Message( $enum, array( $part ) );
			}
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
				isset( $call['id'] ) && '' !== (string) $call['id'] ? (string) $call['id'] : null,
				isset( $call['name'] ) && '' !== (string) $call['name'] ? (string) $call['name'] : null,
				isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array()
			);
			$signature     = isset( $call['thoughtSignature'] ) && is_string( $call['thoughtSignature'] )
				? $call['thoughtSignature']
				: null;
			$parts[]       = new \WordPress\AiClient\Messages\DTO\MessagePart( $function_call, null, $signature );
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
				isset( $response['callId'] ) && '' !== (string) $response['callId'] ? (string) $response['callId'] : null,
				isset( $response['name'] ) && '' !== (string) $response['name'] ? (string) $response['name'] : null,
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
				? $tool['parameters']
				: null;
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
				$normalized = array(
					'id'   => method_exists( $call, 'getId' ) ? (string) $call->getId() : '',
					'name' => method_exists( $call, 'getName' ) ? (string) $call->getName() : '',
					'args' => method_exists( $call, 'getArgs' ) && is_array( $call->getArgs() ) ? $call->getArgs() : array(),
				);
				if ( is_object( $part ) && method_exists( $part, 'getThoughtSignature' ) ) {
					$signature = $part->getThoughtSignature();
					if ( is_string( $signature ) && '' !== $signature ) {
						$normalized['thoughtSignature'] = $signature;
					}
				}
				$tool_calls[] = $normalized;
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
		$texts = array();
		foreach ( $this->result_candidates( $sdk_result ) as $candidate ) {
			foreach ( $this->candidate_parts( $candidate ) as $part ) {
				if ( ! is_object( $part ) || ! method_exists( $part, 'getText' ) ) {
					continue;
				}
				$text = $part->getText();
				if ( ! is_string( $text ) || '' === $text ) {
					continue;
				}
				if ( method_exists( $part, 'getChannel' ) && ! $part->getChannel()->isContent() ) {
					continue;
				}
				$texts[] = $text;
			}
		}
		return trim( implode( "\n", $texts ) );
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
			'inputTokens'           => method_exists( $usage, 'getPromptTokens' ) ? (int) $usage->getPromptTokens() : 0,
			'cachedInputTokens'     => method_exists( $usage, 'getCachedInputTokens' ) ? (int) $usage->getCachedInputTokens() : 0,
			'outputTokens'          => method_exists( $usage, 'getCompletionTokens' ) ? (int) $usage->getCompletionTokens() : 0,
			'reasoningOutputTokens' => method_exists( $usage, 'getThoughtTokens' ) ? (int) $usage->getThoughtTokens() : 0,
		);
	}

	/**
	 * SDK-SEAM: extract the model identifier the provider actually used.
	 *
	 * @param mixed $sdk_result SDK result object.
	 * @return string Model ID, or '' when unavailable.
	 */
	private function extract_model( $sdk_result ): string {
		if ( ! is_object( $sdk_result ) || ! method_exists( $sdk_result, 'getModelMetadata' ) ) {
			return '';
		}
		$metadata = $sdk_result->getModelMetadata();
		if ( ! is_object( $metadata ) || ! method_exists( $metadata, 'getId' ) ) {
			return '';
		}
		return (string) $metadata->getId();
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
}
