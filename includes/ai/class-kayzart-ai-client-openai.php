<?php
/**
 * Direct OpenAI Responses API adapter for WordPress versions before 7.0.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provider adapter backed by the WordPress HTTP API. */
class Ai_Client_OpenAI implements Ai_Client_Interface {
	const ENDPOINT = 'https://api.openai.com/v1/responses';
	const MODEL    = 'gpt-5.6-luna';

	/** Whether a direct credential and the common AI runtime are available. */
	public function is_available(): bool {
		return Ai_Availability::is_direct_client_available();
	}

	/**
	 * Run one Responses API turn and normalize the result.
	 *
	 * @param array $messages Normalized conversation messages.
	 * @param array $tools Tool declarations.
	 * @param array $options Generation options.
	 * @return array Normalized generation result.
	 * @throws Ai_Client_Exception When the request fails or is malformed.
	 */
	public function generate( array $messages, array $tools, array $options = array() ): array {
		$key = Ai_OpenAI_Key::get();
		if ( '' === $key ) {
			throw new Ai_Client_Exception( 'OpenAI API key is not configured.', false );
		}

		$payload = array(
			'model' => self::MODEL,
			'input' => $this->build_input( $messages ),
		);
		if ( ! empty( $options['systemInstruction'] ) ) {
			$payload['instructions'] = (string) $options['systemInstruction'];
		}
		if ( ! empty( $tools ) ) {
			$payload['tools'] = $this->build_tools( $tools );
		}
		if ( isset( $options['jsonSchema'] ) && is_array( $options['jsonSchema'] ) ) {
			$payload['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'kayzart_response',
					'schema' => $options['jsonSchema'],
					'strict' => true,
				),
			);
		}

		$timeout  = isset( $options['requestTimeout'] ) && is_numeric( $options['requestTimeout'] ) ? max( 1.0, (float) $options['requestTimeout'] ) : 120.0;
		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Ai_Client_Exception( 'OpenAI request failed: ' . $response->get_error_message(), true );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			throw new Ai_Client_Exception( 'OpenAI rejected the request with HTTP ' . $status . '.', 408 === $status || 429 === $status || $status >= 500 );
		}
		if ( ! is_array( $body ) || ! isset( $body['output'] ) || ! is_array( $body['output'] ) ) {
			throw new Ai_Client_Exception( 'OpenAI returned an invalid response.', true );
		}

		return $this->normalize_response( $body );
	}

	/**
	 * Convert normalized messages to Responses API input items.
	 *
	 * @param array $messages Normalized conversation messages.
	 * @return array Responses API input items.
	 */
	private function build_input( array $messages ): array {
		$input = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$role                = isset( $message['role'] ) ? (string) $message['role'] : '';
			$openai_output_items = $this->get_openai_output_items( $message );
			if ( ! empty( $openai_output_items ) ) {
				foreach ( $openai_output_items as $output_item ) {
					$input[] = $output_item;
				}
				continue;
			}
			$text = isset( $message['text'] ) ? (string) $message['text'] : '';
			if ( in_array( $role, array( Ai_Message::ROLE_USER, Ai_Message::ROLE_ASSISTANT, Ai_Message::ROLE_SYSTEM ), true ) && '' !== $text ) {
				$input[] = array(
					'role'    => Ai_Message::ROLE_SYSTEM === $role ? 'developer' : $role,
					'content' => $text,
				);
			}
			foreach ( isset( $message['toolCalls'] ) && is_array( $message['toolCalls'] ) ? $message['toolCalls'] : array() as $call ) {
				if ( ! is_array( $call ) || empty( $call['id'] ) || empty( $call['name'] ) ) {
					continue;
				}
				$input[] = array(
					'type'      => 'function_call',
					'call_id'   => (string) $call['id'],
					'name'      => (string) $call['name'],
					'arguments' => wp_json_encode( isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array() ),
				);
			}
			foreach ( isset( $message['toolResponses'] ) && is_array( $message['toolResponses'] ) ? $message['toolResponses'] : array() as $tool_response ) {
				if ( ! is_array( $tool_response ) || empty( $tool_response['callId'] ) ) {
					continue;
				}
				$output  = isset( $tool_response['output'] ) ? $tool_response['output'] : null;
				$encoded = is_string( $output ) ? $output : wp_json_encode( $output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$input[] = array(
					'type'    => 'function_call_output',
					'call_id' => (string) $tool_response['callId'],
					'output'  => false === $encoded ? '' : $encoded,
				);
			}
		}
		return $input;
	}

	/**
	 * Read validated OpenAI output items from an assistant message.
	 *
	 * The items are replayed verbatim only when they contain every normalized
	 * function call on the message. Otherwise the provider-neutral mapping is
	 * used, which keeps old checkpoints and malformed data usable.
	 *
	 * @param array $message Normalized conversation message.
	 * @return array Valid output items, or an empty array for fallback mapping.
	 */
	private function get_openai_output_items( array $message ): array {
		if ( Ai_Message::ROLE_ASSISTANT !== ( $message['role'] ?? '' ) || empty( $message['toolCalls'] ) || ! is_array( $message['toolCalls'] ) ) {
			return array();
		}
		if ( empty( $message['providerData']['openai']['outputItems'] ) || ! is_array( $message['providerData']['openai']['outputItems'] ) ) {
			return array();
		}

		$expected_calls = array();
		foreach ( $message['toolCalls'] as $call ) {
			if ( ! is_array( $call ) || empty( $call['id'] ) || empty( $call['name'] ) || isset( $expected_calls[ (string) $call['id'] ] ) ) {
				return array();
			}
			$expected_calls[ (string) $call['id'] ] = (string) $call['name'];
		}

		$output_items   = array_values( $message['providerData']['openai']['outputItems'] );
		$returned_calls = array();
		foreach ( $output_items as $item ) {
			if ( ! is_array( $item ) || empty( $item['type'] ) || ! is_string( $item['type'] ) ) {
				return array();
			}
			if ( 'function_call' === $item['type'] ) {
				if ( empty( $item['call_id'] ) || empty( $item['name'] ) || isset( $returned_calls[ (string) $item['call_id'] ] ) ) {
					return array();
				}
				$returned_calls[ (string) $item['call_id'] ] = (string) $item['name'];
			}
		}

		if ( $expected_calls !== $returned_calls ) {
			return array();
		}
		return $output_items;
	}

	/**
	 * Convert Kayzart tool declarations to Responses API function tools.
	 *
	 * @param array $tools Kayzart tool declarations.
	 * @return array Responses API tools.
	 */
	private function build_tools( array $tools ): array {
		$result = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}
			$result[] = array(
				'type'        => 'function',
				'name'        => (string) $tool['name'],
				'description' => isset( $tool['description'] ) ? (string) $tool['description'] : '',
				'parameters'  => isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ? $tool['parameters'] : array(
					'type'       => 'object',
					'properties' => array(),
				),
			);
		}
		return $result;
	}

	/**
	 * Normalize Responses API output into the provider-neutral contract.
	 *
	 * @param array $body Decoded Responses API body.
	 * @return array Normalized generation result.
	 */
	private function normalize_response( array $body ): array {
		$texts        = array();
		$calls        = array();
		$output_items = array();
		foreach ( $body['output'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$output_items[] = $item;
			if ( 'function_call' === ( $item['type'] ?? '' ) ) {
				$args    = isset( $item['arguments'] ) ? json_decode( (string) $item['arguments'], true ) : array();
				$calls[] = array(
					'id'   => isset( $item['call_id'] ) ? (string) $item['call_id'] : '',
					'name' => isset( $item['name'] ) ? (string) $item['name'] : '',
					'args' => is_array( $args ) ? $args : array(),
				);
				continue;
			}
			foreach ( isset( $item['content'] ) && is_array( $item['content'] ) ? $item['content'] : array() as $part ) {
				if ( is_array( $part ) && 'output_text' === ( $part['type'] ?? '' ) && isset( $part['text'] ) ) {
					$texts[] = (string) $part['text'];
				}
			}
		}

		$usage  = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : array();
		$result = array(
			'toolCalls' => $calls,
			'text'      => trim( implode( "\n", $texts ) ),
			'usage'     => array(
				'inputTokens'           => (int) ( $usage['input_tokens'] ?? 0 ),
				'cachedInputTokens'     => (int) ( $usage['input_tokens_details']['cached_tokens'] ?? 0 ),
				'outputTokens'          => (int) ( $usage['output_tokens'] ?? 0 ),
				'reasoningOutputTokens' => (int) ( $usage['output_tokens_details']['reasoning_tokens'] ?? 0 ),
			),
			'model'     => isset( $body['model'] ) ? (string) $body['model'] : self::MODEL,
		);
		if ( ! empty( $calls ) && ! empty( $output_items ) ) {
			$result['providerData'] = array(
				'openai' => array(
					'outputItems' => $output_items,
				),
			);
		}
		return $result;
	}
}
