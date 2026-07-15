<?php
/**
 * The AI edit agent loop.
 *
 * Faithful PHP port of `runAgentLoop`/`runFinalizationTurns` from the legacy
 * kayzart-server (`src/ai-jobs.ts`). It drives a multi-turn tool-calling loop
 * against an {@see Ai_Client_Interface}, applying edits to an in-memory
 * snapshot via {@see Ai_Tools}, until the model returns a final summary or the
 * turn limit is reached.
 *
 * The loop owns its conversation history in normalized {@see Ai_Message} form
 * and re-sends the growing message list every turn (the WordPress AI Client is
 * stateless per call). Optional hooks let a caller stream progress events,
 * request cancellation, and serve history tools.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the AI edit agent loop.
 */
class Ai_Agent {

	const MAX_AGENT_TURNS             = 15;
	const FINALIZATION_TURNS          = 1;
	const REPEATED_TOOL_FAILURE_LIMIT = 3;

	/**
	 * JSON schema forcing the final summary shape.
	 */
	const FINAL_SUMMARY_JSON_SCHEMA = array(
		'type'                 => 'object',
		'properties'           => array(
			'summary' => array( 'type' => 'string' ),
		),
		'required'             => array( 'summary' ),
		'additionalProperties' => false,
	);

	/**
	 * The model client.
	 *
	 * @var Ai_Client_Interface
	 */
	private $client;

	/**
	 * Progress event emitter: function( array $event ): void.
	 *
	 * @var callable|null
	 */
	private $emit;

	/**
	 * Cancellation probe: function(): bool.
	 *
	 * @var callable|null
	 */
	private $is_canceled;

	/**
	 * History tool handler: function( string $name, array $args ): mixed.
	 *
	 * @var callable|null
	 */
	private $history_handler;

	/**
	 * Constructor.
	 *
	 * @param Ai_Client_Interface $client The model client.
	 * @param array               $hooks  Optional hooks: 'emit'
	 *                                     callable(array $event), 'isCanceled'
	 *                                     callable():bool, 'historyTool'
	 *                                     callable(string,array):mixed.
	 */
	public function __construct( Ai_Client_Interface $client, array $hooks = array() ) {
		$this->client          = $client;
		$this->emit            = isset( $hooks['emit'] ) && is_callable( $hooks['emit'] ) ? $hooks['emit'] : null;
		$this->is_canceled     = isset( $hooks['isCanceled'] ) && is_callable( $hooks['isCanceled'] ) ? $hooks['isCanceled'] : null;
		$this->history_handler = isset( $hooks['historyTool'] ) && is_callable( $hooks['historyTool'] ) ? $hooks['historyTool'] : null;
	}

	/**
	 * Run the agent loop for a request payload.
	 *
	 * @param array $payload Request payload (see Ai_Prompt for the shape, plus
	 *                       jsMode/baseHash and an optional truthy historyTool).
	 * @return array{snapshot:array,summary:string,usage:array}
	 *
	 * @throws Ai_Agent_Error On an unrecoverable loop outcome. Cancellation
	 *                        (Ai_Agent_Canceled) and transport failures
	 *                        (Ai_Client_Exception) may also propagate.
	 */
	public function run( array $payload ): array {
		$edit_policy      = Ai_Tool_Schema::resolve_edit_policy(
			isset( $payload['editorMode'] ) ? (string) $payload['editorMode'] : '',
			isset( $payload['prompt'] ) ? (string) $payload['prompt'] : ''
		);
		$editable_targets = $edit_policy['editableTargets'];
		$has_history_tool = ! empty( $payload['historyTool'] );
		$tools            = Ai_Tool_Schema::build_tool_definitions( $editable_targets, $has_history_tool );

		$selected_contexts = $this->resolve_selected_contexts( $payload );
		$snapshot          = $this->initial_snapshot( $payload );

		$turn_options         = array(
			'systemInstruction' => Ai_Prompt::system_prompt(),
		);
		$finalization_options = array(
			'systemInstruction' => Ai_Prompt::system_prompt(),
			'jsonSchema'        => self::FINAL_SUMMARY_JSON_SCHEMA,
		);

		$messages = array( Ai_Message::user( Ai_Prompt::build_user_prompt( $payload ) ) );

		$applied_edit_operation = false;
		$usage                  = self::empty_usage();
		$repeated_failures      = array();

		for ( $turn = 0; $turn < self::MAX_AGENT_TURNS; $turn++ ) {
			$this->ensure_not_canceled();
			$this->emit_event(
				array(
					'event'   => 'progress',
					'message' => sprintf( 'AI turn %d/%d', $turn + 1, self::MAX_AGENT_TURNS ),
				)
			);

			$result = $this->client->generate( $messages, $tools, $turn_options );
			$usage  = self::add_usage( $usage, isset( $result['usage'] ) ? $result['usage'] : array() );
			$calls  = isset( $result['toolCalls'] ) && is_array( $result['toolCalls'] ) ? $result['toolCalls'] : array();

			if ( count( $calls ) === 0 ) {
				if ( ! $applied_edit_operation ) {
					throw new Ai_Agent_Error( 'No edit operations were applied. Use edit tools before finalizing.', false );
				}
				return $this->run_finalization_turns( $messages, $snapshot, $usage, $finalization_options );
			}

			$messages[]     = Ai_Message::assistant( isset( $result['text'] ) ? (string) $result['text'] : '', $calls );
			$tool_responses = array();

			foreach ( $calls as $call ) {
				$name = isset( $call['name'] ) ? (string) $call['name'] : '';
				$args = isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array();
				$id   = isset( $call['id'] ) ? (string) $call['id'] : '';

				$this->emit_event(
					array(
						'event'        => 'tool_start',
						'toolName'     => $name,
						'inputSummary' => $this->preview( wp_json_encode( $args ), 180 ),
					)
				);

				try {
					$tool_result = $this->run_tool_call( $name, $args, $snapshot, $selected_contexts, $editable_targets );
					if ( isset( $tool_result['snapshot'] ) && is_array( $tool_result['snapshot'] ) ) {
						$snapshot = $tool_result['snapshot'];
					}
					$applied_edit_operation = $applied_edit_operation || ! empty( $tool_result['appliedEditOperation'] );

					$this->emit_event(
						array(
							'event'         => 'tool_end',
							'toolName'      => $name,
							'outputSummary' => $this->preview( wp_json_encode( $tool_result['output'] ), 220 ),
						)
					);
					$tool_responses[] = Ai_Message::tool_response( $id, $name, $tool_result['output'] );
				} catch ( Ai_Tool_Error $error ) {
					$key = $this->repeated_failure_key( $name, $args, $error );
					if ( '' !== $key ) {
						$count                     = ( isset( $repeated_failures[ $key ] ) ? $repeated_failures[ $key ] : 0 ) + 1;
						$repeated_failures[ $key ] = $count;
						if ( $count >= self::REPEATED_TOOL_FAILURE_LIMIT ) {
							throw new Ai_Agent_Error( 'Repeated exact replacement failed. Inspect the current source and use a more specific instruction.', false );
						}
					}
					$recoverable = array(
						'ok'    => false,
						'error' => array(
							'type'      => 'agent_error',
							'message'   => $error->getMessage(),
							'retryable' => $error->is_retryable(),
						),
					);
					$this->emit_event(
						array(
							'event'         => 'tool_end',
							'toolName'      => $name,
							'outputSummary' => $this->preview( wp_json_encode( $recoverable ), 220 ),
						)
					);
					$tool_responses[] = Ai_Message::tool_response( $id, $name, $recoverable );
				}
			}

			$messages[] = Ai_Message::tool( $tool_responses );
		}

		if ( $applied_edit_operation ) {
			return $this->run_finalization_turns( $messages, $snapshot, $usage, $finalization_options );
		}

		throw new Ai_Agent_Error( 'Agent loop exceeded maximum turns.', true );
	}

	/**
	 * Force a final summary after the edit turn limit, without tools.
	 *
	 * @param array $messages Conversation history.
	 * @param array $snapshot Working snapshot.
	 * @param array $usage    Accumulated usage.
	 * @param array $options  Generation options.
	 * @return array{snapshot:array,summary:string,usage:array}
	 *
	 * @throws Ai_Agent_Error When the model keeps calling tools or no summary
	 *                        appears. Cancellation may also propagate.
	 */
	private function run_finalization_turns( array $messages, array $snapshot, array $usage, array $options ): array {
		for ( $index = 0; $index < self::FINALIZATION_TURNS; $index++ ) {
			$this->ensure_not_canceled();
			$this->emit_event(
				array(
					'event'   => 'progress',
					'message' => 'Preparing final AI edit summary.',
				)
			);

			$result = $this->client->generate( $messages, array(), $options );
			$usage  = self::add_usage( $usage, isset( $result['usage'] ) ? $result['usage'] : array() );
			$calls  = isset( $result['toolCalls'] ) && is_array( $result['toolCalls'] ) ? $result['toolCalls'] : array();

			if ( count( $calls ) > 0 ) {
				throw new Ai_Agent_Error( 'Model attempted tool calls during finalization after edit turn limit.', true );
			}

			$summary = $this->parse_final_summary( isset( $result['text'] ) ? (string) $result['text'] : '' );
			return $this->build_result( $snapshot, $summary, $usage );
		}

		throw new Ai_Agent_Error( 'Agent loop exceeded maximum turns before final summary.', true );
	}

	/**
	 * Execute a single tool call.
	 *
	 * @param string $name              Tool name.
	 * @param array  $args              Tool arguments.
	 * @param array  $snapshot          Working snapshot.
	 * @param array  $selected_contexts Selected element contexts.
	 * @param array  $editable_targets  Editable target allow list.
	 * @return array Tool call result (output, snapshot?, appliedEditOperation).
	 *
	 * @throws Ai_Tool_Error On a recoverable tool-argument problem.
	 */
	private function run_tool_call( string $name, array $args, array $snapshot, array $selected_contexts, array $editable_targets ): array {
		if ( 'list_ai_edits' === $name || 'get_ai_edit' === $name ) {
			if ( null === $this->history_handler ) {
				return array(
					'output'               => array(
						'ok'    => false,
						'error' => 'AI edit history tool is not available.',
					),
					'appliedEditOperation' => false,
				);
			}
			return array(
				'output'               => call_user_func( $this->history_handler, $name, $args ),
				'appliedEditOperation' => false,
			);
		}

		return Ai_Tools::run_tool( $name, $args, $snapshot, $selected_contexts, $editable_targets );
	}

	/**
	 * Build the loop result array.
	 *
	 * @param array  $snapshot Snapshot.
	 * @param string $summary  Summary text.
	 * @param array  $usage    Usage totals.
	 * @return array
	 */
	private function build_result( array $snapshot, string $summary, array $usage ): array {
		return array(
			'snapshot' => $snapshot,
			'summary'  => $summary,
			'usage'    => $usage,
		);
	}

	/**
	 * Build the initial snapshot from the request payload.
	 *
	 * @param array $payload Request payload.
	 * @return array
	 */
	private function initial_snapshot( array $payload ): array {
		$html        = isset( $payload['html'] ) ? (string) $payload['html'] : '';
		$custom_head = isset( $payload['customHead'] ) ? (string) $payload['customHead'] : '';
		$css         = isset( $payload['css'] ) ? (string) $payload['css'] : '';
		$js          = isset( $payload['js'] ) ? (string) $payload['js'] : '';
		$js_mode     = ( isset( $payload['jsMode'] ) && 'module' === $payload['jsMode'] ) ? 'module' : 'classic';
		$base_hash   = isset( $payload['baseHash'] ) && '' !== (string) $payload['baseHash']
			? (string) $payload['baseHash']
			: Ai_Tools::compute_base_hash( $html, $custom_head, $css, $js );

		return array(
			'html'       => $html,
			'customHead' => $custom_head,
			'css'        => $css,
			'js'         => $js,
			'jsMode'     => $js_mode,
			'baseHash'   => $base_hash,
		);
	}

	/**
	 * Resolve the effective selected-context list from a payload.
	 *
	 * @param array $payload Request payload.
	 * @return array<int,array>
	 */
	private function resolve_selected_contexts( array $payload ): array {
		if ( ! empty( $payload['selectedContexts'] ) && is_array( $payload['selectedContexts'] ) ) {
			return array_values( $payload['selectedContexts'] );
		}
		if ( ! empty( $payload['selectedContext'] ) && is_array( $payload['selectedContext'] ) ) {
			return array( $payload['selectedContext'] );
		}
		return array();
	}

	/**
	 * Parse and validate the final summary from model text.
	 *
	 * @param string $text Model text output.
	 * @return string Summary string.
	 *
	 * @throws Ai_Agent_Error When the text is not valid JSON or lacks a summary.
	 */
	private function parse_final_summary( string $text ): string {
		$parsed = $this->parse_json_object_from_text( $text );
		if ( null === $parsed ) {
			throw new Ai_Agent_Error( 'Model response is not valid JSON.', true );
		}
		if ( ! isset( $parsed['summary'] ) || ! is_string( $parsed['summary'] ) ) {
			throw new Ai_Agent_Error( 'Model response does not match output schema.', true );
		}
		return $parsed['summary'];
	}

	/**
	 * Extract a JSON object from raw text, tolerating surrounding prose.
	 *
	 * @param string $text Raw text.
	 * @return array|null
	 */
	private function parse_json_object_from_text( string $text ) {
		$direct = json_decode( $text, true );
		if ( is_array( $direct ) ) {
			return $direct;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Build a dedup key for tracked repeated tool failures.
	 *
	 * @param string        $name  Tool name.
	 * @param array         $args  Tool arguments.
	 * @param Ai_Tool_Error $error The error raised.
	 * @return string Empty string when the failure is not tracked.
	 */
	private function repeated_failure_key( string $name, array $args, Ai_Tool_Error $error ): string {
		$message = $error->getMessage();
		$tracked = ( false !== strpos( $message, 'replace_string matched 0 occurrences' ) )
			|| ( false !== strpos( $message, 'replace_string is ambiguous' ) );
		if ( ! $tracked ) {
			return '';
		}

		$target = isset( $args['target'] ) ? (string) $args['target'] : '';
		if ( 'replace_string' === $name ) {
			$from = isset( $args['from'] ) ? (string) $args['from'] : '';
			return implode( "\x00", array( $name, $target, $from, $message ) );
		}
		return implode( "\x00", array( $name, $target, (string) wp_json_encode( $args ), $message ) );
	}

	/**
	 * Throw if cancellation has been requested.
	 *
	 * @return void
	 *
	 * @throws Ai_Agent_Canceled When canceled.
	 */
	private function ensure_not_canceled(): void {
		if ( null !== $this->is_canceled && call_user_func( $this->is_canceled ) ) {
			throw new Ai_Agent_Canceled();
		}
	}

	/**
	 * Emit a progress event when an emitter is configured.
	 *
	 * @param array $event Event payload.
	 * @return void
	 */
	private function emit_event( array $event ): void {
		if ( null !== $this->emit ) {
			call_user_func( $this->emit, $event );
		}
	}

	/**
	 * Truncate a string for event previews.
	 *
	 * @param mixed $value Value to preview.
	 * @param int   $limit Max length.
	 * @return string
	 */
	private function preview( $value, int $limit ): string {
		$text = is_string( $value ) ? $value : '';
		return strlen( $text ) > $limit ? substr( $text, 0, $limit ) . '...' : $text;
	}

	/**
	 * Empty usage totals.
	 *
	 * @return array
	 */
	private static function empty_usage(): array {
		return array(
			'inputTokens'           => 0,
			'cachedInputTokens'     => 0,
			'outputTokens'          => 0,
			'reasoningOutputTokens' => 0,
		);
	}

	/**
	 * Add a usage delta to a running total.
	 *
	 * @param array $total Running total.
	 * @param mixed $delta Delta usage.
	 * @return array
	 */
	private static function add_usage( array $total, $delta ): array {
		$delta = is_array( $delta ) ? $delta : array();
		foreach ( array( 'inputTokens', 'cachedInputTokens', 'outputTokens', 'reasoningOutputTokens' ) as $key ) {
			$total[ $key ] = ( isset( $total[ $key ] ) ? (int) $total[ $key ] : 0 )
				+ ( isset( $delta[ $key ] ) ? (int) $delta[ $key ] : 0 );
		}
		return $total;
	}
}
