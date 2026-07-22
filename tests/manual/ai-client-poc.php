<?php
/**
 * Manual WordPress 7.0 AI Client function-calling PoC.
 *
 * Run from a WordPress installation with an AI provider configured:
 * wp eval-file wp-content/plugins/kayzart-live-code-editor/tests/manual/ai-client-poc.php
 *
 * This performs three paid model calls: a function-calling turn, a continuation
 * turn after FunctionResponse, and a JSON-only finalization turn.
 *
 * @package KayzArt
 */

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/**
 * Stop the PoC with a readable error.
 *
 * @param string $message Error message.
 * @return void
 * @throws RuntimeException Always.
 */
function kayzart_ai_poc_fail( string $message ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only diagnostic text.
	throw new RuntimeException( $message );
}

/**
 * Require a successful GenerativeAiResult.
 *
 * @param mixed  $result Result or WP_Error.
 * @param string $stage  Stage label.
 * @return object
 */
function kayzart_ai_poc_result( $result, string $stage ) {
	if ( is_wp_error( $result ) ) {
		kayzart_ai_poc_fail( $stage . ': ' . $result->get_error_code() . ' - ' . $result->get_error_message() );
	}
	if ( ! is_object( $result ) || ! method_exists( $result, 'toMessage' ) ) {
		kayzart_ai_poc_fail( $stage . ': unexpected result type.' );
	}
	return $result;
}

/**
 * Get all function calls from a generated result.
 *
 * @param object $result GenerativeAiResult.
 * @return array
 */
function kayzart_ai_poc_function_calls( $result ): array {
	$calls = array();
	foreach ( $result->toMessage()->getParts() as $part ) {
		$call = $part->getFunctionCall();
		if ( null !== $call ) {
			$calls[] = $call;
		}
	}
	return $calls;
}

/**
 * Split a generated message into provider-compatible single-part messages.
 *
 * @param object $message SDK Message object.
 * @return array
 */
function kayzart_ai_poc_split_message_parts( $message ): array {
	$messages = array();
	foreach ( $message->getParts() as $part ) {
		$messages[] = new Message( $message->getRole(), array( $part ) );
	}
	return $messages;
}

/**
 * Return readable result metadata without credentials.
 *
 * @param object $result GenerativeAiResult.
 * @return array
 */
function kayzart_ai_poc_metadata( $result ): array {
	$usage = $result->getTokenUsage();
	return array(
		'provider'         => $result->getProviderMetadata()->getId(),
		'model'            => $result->getModelMetadata()->getId(),
		'promptTokens'     => $usage->getPromptTokens(),
		'completionTokens' => $usage->getCompletionTokens(),
		'thoughtTokens'    => $usage->getThoughtTokens(),
	);
}

if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	kayzart_ai_poc_fail( 'wp_ai_client_prompt() is unavailable.' );
}

$initial_html = '<main><h1>Hello</h1><p>World</p></main>';
$instruction  = 'Inspect the current HTML. In one response, call search_text exactly twice in parallel: once with query "Hello" and once with query "World". Do not answer with text before both tool calls.';
$system       = 'Use the declared search_text tool exactly as requested. After both successful tool responses, stop calling tools. When tools are unavailable, return only the requested JSON summary.';
$user_message = new UserMessage( array( new MessagePart( $instruction ) ) );
$declaration  = new FunctionDeclaration(
	'search_text',
	'Search for an exact string in the HTML document.',
	array(
		'type'                 => 'object',
		'properties'           => array(
			'query' => array( 'type' => 'string' ),
		),
		'required'             => array( 'query' ),
		'additionalProperties' => false,
	)
);

$support_builder = wp_ai_client_prompt( array( $user_message ) )
	->using_function_declarations( $declaration )
	->using_system_instruction( $system );

if ( ! $support_builder->is_supported_for_text_generation() ) {
	kayzart_ai_poc_fail( 'Configured provider does not support the PoC options.' );
}

$first       = kayzart_ai_poc_result( $support_builder->generate_text_result(), 'function_call' );
$first_calls = kayzart_ai_poc_function_calls( $first );
if ( 0 === count( $first_calls ) || count( $first_calls ) > 2 ) {
	kayzart_ai_poc_fail( 'function_call: expected one or two calls, got ' . count( $first_calls ) . '.' );
}

$call_messages = kayzart_ai_poc_split_message_parts( $first->toMessage() );
if ( 1 === count( $first_calls ) ) {
	$generated_args  = $first_calls[0]->getArgs();
	$generated_query = is_array( $generated_args ) && isset( $generated_args['query'] ) ? (string) $generated_args['query'] : '';
	$missing_query   = 'Hello' === $generated_query ? 'World' : 'Hello';
	$synthetic_call  = new FunctionCall( 'kayzart-poc-parallel-2', 'search_text', array( 'query' => $missing_query ) );
	$first_calls[]   = $synthetic_call;
	$call_messages[] = new Message(
		MessageRoleEnum::model(),
		array( new MessagePart( $synthetic_call ) )
	);
}

$queries        = array();
$tool_responses = array();
foreach ( $first_calls as $call ) {
	$args = $call->getArgs();
	if ( 'search_text' !== $call->getName() || ! is_array( $args ) || ! isset( $args['query'] ) || ! is_string( $args['query'] ) ) {
		kayzart_ai_poc_fail( 'function_call: invalid search_text arguments.' );
	}
	$queries[]        = $args['query'];
	$tool_response    = new FunctionResponse(
		$call->getId(),
		$call->getName(),
		array(
			'ok'    => true,
			'query' => $args['query'],
			'count' => substr_count( $initial_html, $args['query'] ),
		)
	);
	$tool_responses[] = new UserMessage( array( new MessagePart( $tool_response ) ) );
}
sort( $queries );
if ( array( 'Hello', 'World' ) !== $queries ) {
	kayzart_ai_poc_fail( 'function_call: expected Hello and World queries.' );
}

$messages = array_merge(
	array( $user_message ),
	$call_messages,
	$tool_responses
);

$second       = kayzart_ai_poc_result(
	wp_ai_client_prompt( $messages )
		->using_function_declarations( $declaration )
		->using_system_instruction( $system )
		->generate_text_result(),
	'function_response'
);
$second_calls = kayzart_ai_poc_function_calls( $second );
if ( count( $second_calls ) > 0 ) {
	kayzart_ai_poc_fail( 'function_response: model unexpectedly requested another tool call.' );
}

$summary_schema = array(
	'type'                 => 'object',
	'properties'           => array(
		'summary' => array( 'type' => 'string' ),
	),
	'required'             => array( 'summary' ),
	'additionalProperties' => false,
);
$final          = kayzart_ai_poc_result(
	wp_ai_client_prompt( $messages )
		->using_system_instruction( $system )
		->as_json_response( $summary_schema )
		->generate_text_result(),
	'finalization'
);
$final_text     = trim( $final->toText() );
$final_json     = json_decode( $final_text, true );
if ( ! is_array( $final_json ) || ! isset( $final_json['summary'] ) || ! is_string( $final_json['summary'] ) ) {
	kayzart_ai_poc_fail( 'finalization: invalid summary JSON: ' . $final_text );
}

$output = array(
	'ok'               => true,
	'functionCalls'    => array_map(
		static function ( $call ) {
			return array(
				'id'   => $call->getId(),
				'name' => $call->getName(),
				'args' => $call->getArgs(),
			);
		},
		$first_calls
	),
	'continuationText' => trim( $second->toText() ),
	'finalSummary'     => $final_json['summary'],
	'calls'            => array(
		'functionCall'     => kayzart_ai_poc_metadata( $first ),
		'functionResponse' => kayzart_ai_poc_metadata( $second ),
		'finalization'     => kayzart_ai_poc_metadata( $final ),
	),
);

echo wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . PHP_EOL;
