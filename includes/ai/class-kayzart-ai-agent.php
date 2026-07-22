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
	const READ_BUDGET_PER_TURN        = 12000;
	const OBSERVATION_CONTEXT_CHARS   = 24000;
	const DEBUG_TRACE_PREVIEW_CHARS   = 500;
	const DEBUG_TRACE_MAX_BYTES       = 1048576;

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
	 * Identifier included in debug token logs.
	 *
	 * @var string
	 */
	private $debug_id;

	/**
	 * Exact named segments of the initial user prompt.
	 *
	 * @var array<string,string>
	 */
	private $debug_input_parts = array();

	/** Privacy-safe size statistics for the latest edit footprint.
	 *
	 * @var array<string,int|string|bool>
	 */
	private $debug_edit_footprint_stats = array();

	/** Privacy-safe statistics for the most recent model context projection.
	 *
	 * @var array<string,int>
	 */
	private $model_context_stats = array();

	/** Configured content trace mode: off, preview, or full.
	 *
	 * @var string
	 */
	private $debug_trace_mode;

	/** Step performance observer: function(array $metric): void.
	 *
	 * @var callable|null
	 */
	private $observe_step;

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
		$this->client           = $client;
		$this->emit             = isset( $hooks['emit'] ) && is_callable( $hooks['emit'] ) ? $hooks['emit'] : null;
		$this->is_canceled      = isset( $hooks['isCanceled'] ) && is_callable( $hooks['isCanceled'] ) ? $hooks['isCanceled'] : null;
		$this->history_handler  = isset( $hooks['historyTool'] ) && is_callable( $hooks['historyTool'] ) ? $hooks['historyTool'] : null;
		$this->debug_id         = isset( $hooks['debugId'] ) ? (string) $hooks['debugId'] : '';
		$this->debug_trace_mode = self::resolve_debug_trace_mode();
		$this->observe_step     = isset( $hooks['observeStep'] ) && is_callable( $hooks['observeStep'] ) ? $hooks['observeStep'] : null;
	}

	/**
	 * Run the agent loop for a request payload.
	 *
	 * @param array $payload Request payload (see Ai_Prompt for the shape, plus
	 *                       jsMode/baseHash and an optional truthy historyTool).
	 * @return array{snapshot:array,summary:string,usage:array}
	 *
	 * @throws Ai_Agent_Error On an unrecoverable loop outcome.
	 */
	public function run( array $payload ): array {
		$state = $this->create_state( $payload );
		while ( true ) {
			$step = $this->advance( $payload, $state );
			if ( 'completed' === $step['status'] ) {
				return $step['result'];
			}
			$state = $step['state'];
		}
	}

	/** Build a JSON-serializable checkpoint for a new agent run.
	 *
	 * @param array $payload Normalized request payload.
	 * @return array
	 */
	public function create_state( array $payload ): array {
		return array(
			'schemaVersion'        => 1,
			'phase'                => 'agent',
			'turn'                 => 0,
			'finalizationTurn'     => 0,
			'messages'             => array( Ai_Message::user( Ai_Prompt::build_user_prompt( $payload ) ) ),
			'snapshot'             => $this->initial_snapshot( $payload ),
			'selectionRecords'     => $this->resolve_selection_records( $payload ),
			'appliedEditOperation' => false,
			'finishReady'          => false,
			'usage'                => self::empty_usage(),
			'repeatedFailures'     => array(),
		);
	}

	/** Execute at most one provider call and return the next checkpoint.
	 *
	 * @param array $payload Request payload.
	 * @param array $state   State returned by create_state() or advance().
	 * @return array{status:string,state:array,result?:array,metrics:array}
	 * @throws Ai_Agent_Error When state or model output cannot be used.
	 */
	public function advance( array $payload, array $state ): array {
		$this->validate_state( $state );
		$this->debug_input_parts          = Ai_Prompt::debug_input_parts( $payload );
		$this->debug_edit_footprint_stats = $this->build_debug_edit_footprint_stats( $payload );
		$this->ensure_not_canceled();

		$phase = (string) $state['phase'];
		if ( 'finalization' === $phase ) {
			return $this->advance_finalization( $payload, $state );
		}
		if ( (int) $state['turn'] >= self::MAX_AGENT_TURNS ) {
			if ( empty( $state['appliedEditOperation'] ) ) {
				throw new Ai_Agent_Error( 'Agent loop exceeded maximum turns.', true );
			}
			$state['phase'] = 'finalization';
			return array(
				'status'  => 'continue',
				'state'   => $state,
				'metrics' => $this->step_metrics( 'transition', (int) $state['turn'], 0.0, 0.0, $state['usage'] ),
			);
		}

		$edit_policy           = Ai_Tool_Schema::resolve_edit_policy(
			isset( $payload['editorMode'] ) ? (string) $payload['editorMode'] : '',
			isset( $payload['prompt'] ) ? (string) $payload['prompt'] : ''
		);
		$editable_targets      = $edit_policy['editableTargets'];
		$has_history_tool      = ! empty( $payload['historyTool'] );
		$selection_records     = $state['selectionRecords'];
		$has_selection_context = $this->has_resolvable_selection( $selection_records );
		$tools                 = Ai_Tool_Schema::build_tool_definitions( $editable_targets, $has_history_tool, $has_selection_context );
		$snapshot              = $state['snapshot'];

		$turn_options     = array(
			'systemInstruction' => Ai_Prompt::system_prompt(),
		);
		$model_preference = self::resolve_model_preference( $payload );
		if ( count( $model_preference ) > 0 ) {
			$turn_options['modelPreference'] = $model_preference;
		}

		$messages               = $state['messages'];
		$applied_edit_operation = (bool) $state['appliedEditOperation'];
		$finish_ready           = (bool) $state['finishReady'];
		$usage                  = $state['usage'];
		$repeated_failures      = $state['repeatedFailures'];
		$turn                   = (int) $state['turn'];
		$provider_started       = microtime( true );
		$tool_seconds           = 0.0;

		$this->emit_event(
			array(
				'event'   => 'progress',
				'message' => sprintf( 'AI turn %d/%d', $turn + 1, self::MAX_AGENT_TURNS ),
			)
		);

		$model_messages = $this->build_model_context( $messages );
		$this->log_model_request_trace( 'agent', $turn + 1, $model_messages, $tools, $turn_options );
		$result           = $this->client->generate( $model_messages, $tools, $turn_options );
		$provider_seconds = microtime( true ) - $provider_started;
		$this->log_model_response_trace( 'agent', $turn + 1, $result );
		$this->log_input_token_breakdown( 'agent', $turn + 1, $model_messages, $tools, $turn_options, $result );
		$usage = self::add_usage( $usage, isset( $result['usage'] ) ? $result['usage'] : array() );
		$usage = self::remember_model( $usage, $result );
		$calls = isset( $result['toolCalls'] ) && is_array( $result['toolCalls'] ) ? $result['toolCalls'] : array();

		if ( count( $calls ) === 0 ) {
			if ( ! $applied_edit_operation ) {
				throw new Ai_Agent_Error( 'No edit operations were applied. Use edit tools before finalizing.', false );
			}
			$summary = $this->try_parse_final_summary( isset( $result['text'] ) ? (string) $result['text'] : '' );
			if ( null !== $summary ) {
				return $this->completed_step( $state, $snapshot, $summary, $usage, 'agent', $turn + 1, $provider_seconds, 0.0 );
			}
			$state['phase']    = 'finalization';
			$state['snapshot'] = $snapshot;
			$state['usage']    = $usage;
			return $this->continued_step( $state, 'agent', $turn + 1, $provider_seconds, 0.0 );
		}

		$messages[]     = Ai_Message::assistant( isset( $result['text'] ) ? (string) $result['text'] : '', $calls );
		$tool_responses = array();
		$finish_calls   = array();
		$turn_had_error = false;
		$turn_had_edit  = false;

		$remaining_read_budget = self::READ_BUDGET_PER_TURN;
		$tools_started         = microtime( true );
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

			if ( 'finish_edit' === $name ) {
				$finish_calls[] = array(
					'id'   => $id,
					'name' => $name,
					'args' => $args,
				);
				continue;
			}

			try {
				if ( 'read_document' === $name || 'read_selection' === $name ) {
					if ( $remaining_read_budget <= 0 ) {
						$this->throw_read_budget_exhausted();
					}
					$requested        = isset( $args['maxChars'] ) && is_numeric( $args['maxChars'] ) ? (int) $args['maxChars'] : Ai_Tools::DEFAULT_READ_CHARS;
					$args['maxChars'] = max( 1, min( $requested, $remaining_read_budget ) );
				}
				$tool_result = $this->run_tool_call( $name, $args, $snapshot, $selection_records, $editable_targets );
				if ( isset( $tool_result['snapshot'] ) && is_array( $tool_result['snapshot'] ) ) {
					$snapshot = $tool_result['snapshot'];
				}
				if ( isset( $tool_result['selectionRecords'] ) && is_array( $tool_result['selectionRecords'] ) ) {
					$selection_records = $tool_result['selectionRecords'];
				}
				if ( ( 'read_document' === $name || 'read_selection' === $name ) && isset( $tool_result['output']['content'] ) ) {
					$remaining_read_budget -= mb_strlen( (string) $tool_result['output']['content'] );
				}
				if ( isset( $tool_result['output'] ) && is_array( $tool_result['output'] ) && array_key_exists( 'ok', $tool_result['output'] ) && false === $tool_result['output']['ok'] ) {
					$turn_had_error = true;
				}
				$tool_applied_edit      = ! empty( $tool_result['appliedEditOperation'] );
				$turn_had_edit          = $turn_had_edit || $tool_applied_edit;
				$applied_edit_operation = $applied_edit_operation || $tool_applied_edit;

				$this->emit_event(
					array(
						'event'         => 'tool_end',
						'toolName'      => $name,
						'outputSummary' => $this->preview( wp_json_encode( $tool_result['output'] ), 220 ),
					)
				);
				$tool_responses[] = Ai_Message::tool_response( $id, $name, $tool_result['output'] );
			} catch ( Ai_Tool_Error $error ) {
				$turn_had_error = true;
				$key            = $this->repeated_failure_key( $name, $args, $error );
				if ( '' !== $key ) {
					$count                     = ( isset( $repeated_failures[ $key ] ) ? $repeated_failures[ $key ] : 0 ) + 1;
					$repeated_failures[ $key ] = $count;
					if ( $count >= self::REPEATED_TOOL_FAILURE_LIMIT ) {
						throw new Ai_Agent_Error( 'Repeated exact replacement failed. Inspect the current source and use a more specific instruction.', false );
					}
				}
				$recoverable   = array(
					'ok'    => false,
					'error' => array(
						'type'      => 'agent_error',
						'message'   => $error->getMessage(),
						'retryable' => $error->is_retryable(),
					),
				);
				$error_details = $error->get_details();
				if ( count( $error_details ) > 0 ) {
					$recoverable['error']['details'] = $error_details;
				}
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

		if ( $turn_had_error ) {
			$finish_ready = false;
		} elseif ( $turn_had_edit ) {
			$finish_ready = true;
		}

		if ( count( $finish_calls ) > 0 ) {
			$finish_error = '';
			$summary      = '';
			$retryable    = false;
			if ( count( $finish_calls ) > 1 ) {
				$finish_error = 'finish_edit may be called only once per turn.';
			} else {
				$finish_args = $finish_calls[0]['args'];
				$raw_summary = isset( $finish_args['summary'] ) && is_string( $finish_args['summary'] ) ? $finish_args['summary'] : '';
				$summary     = trim( $raw_summary );
				if ( '' === $summary ) {
					$finish_error = 'finish_edit.summary must be a non-empty string.';
				} elseif ( mb_strlen( $raw_summary ) > 1000 ) {
					$finish_error = 'finish_edit.summary must be 1,000 characters or fewer.';
				} elseif ( $turn_had_error ) {
					$finish_error = 'finish_edit was not accepted because another tool failed in this turn. Inspect the error and retry the required edit.';
					$retryable    = true;
				} elseif ( ! $finish_ready ) {
					$finish_error = 'finish_edit requires a successful edit with no unresolved tool errors.';
					$retryable    = true;
				}
			}

			if ( '' === $finish_error ) {
				$this->emit_event(
					array(
						'event'         => 'tool_end',
						'toolName'      => 'finish_edit',
						'outputSummary' => $this->preview( wp_json_encode( array( 'ok' => true ) ), 220 ),
					)
				);
				$tool_seconds = microtime( true ) - $tools_started;
				return $this->completed_step( $state, $snapshot, $summary, $usage, 'agent', $turn + 1, $provider_seconds, $tool_seconds );
			}

			$recoverable = array(
				'ok'    => false,
				'error' => array(
					'type'      => 'agent_error',
					'message'   => $finish_error,
					'retryable' => $retryable,
				),
			);
			foreach ( $finish_calls as $finish_call ) {
				$this->emit_event(
					array(
						'event'         => 'tool_end',
						'toolName'      => 'finish_edit',
						'outputSummary' => $this->preview( wp_json_encode( $recoverable ), 220 ),
					)
				);
				$tool_responses[] = Ai_Message::tool_response( $finish_call['id'], 'finish_edit', $recoverable );
			}
		}

		$messages[]                    = Ai_Message::tool( $tool_responses );
		$tool_seconds                  = microtime( true ) - $tools_started;
		$state['turn']                 = $turn + 1;
		$state['messages']             = $messages;
		$state['snapshot']             = $snapshot;
		$state['selectionRecords']     = $selection_records;
		$state['appliedEditOperation'] = $applied_edit_operation;
		$state['finishReady']          = $finish_ready;
		$state['usage']                = $usage;
		$state['repeatedFailures']     = $repeated_failures;
		if ( $state['turn'] >= self::MAX_AGENT_TURNS ) {
			if ( ! $applied_edit_operation ) {
				throw new Ai_Agent_Error( 'Agent loop exceeded maximum turns.', true );
			}
			$state['phase'] = 'finalization';
		}
		return $this->continued_step( $state, 'agent', $turn + 1, $provider_seconds, $tool_seconds );
	}

	/** Execute the single finalization provider turn.
	 *
	 * @param array $payload Request payload.
	 * @param array $state   Persisted agent state.
	 * @return array
	 * @throws Ai_Agent_Error When finalization fails.
	 */
	private function advance_finalization( array $payload, array $state ): array {
		$index = (int) $state['finalizationTurn'];
		if ( $index >= self::FINALIZATION_TURNS ) {
			throw new Ai_Agent_Error( 'Agent loop exceeded maximum turns before final summary.', true );
		}
		$options          = array(
			'systemInstruction' => Ai_Prompt::system_prompt(),
			'jsonSchema'        => self::FINAL_SUMMARY_JSON_SCHEMA,
		);
		$model_preference = self::resolve_model_preference( $payload );
		if ( count( $model_preference ) > 0 ) {
			$options['modelPreference'] = $model_preference;
		}
		$this->emit_event(
			array(
				'event'   => 'progress',
				'message' => 'Preparing final AI edit summary.',
			)
		);

		$model_messages = $this->build_model_context( $state['messages'] );
		$this->log_model_request_trace( 'finalization', $index + 1, $model_messages, array(), $options );
		$provider_started = microtime( true );
		$result           = $this->client->generate( $model_messages, array(), $options );
		$provider_seconds = microtime( true ) - $provider_started;
		$this->log_model_response_trace( 'finalization', $index + 1, $result );
		$this->log_input_token_breakdown( 'finalization', $index + 1, $model_messages, array(), $options, $result );
		$usage = self::add_usage( $state['usage'], isset( $result['usage'] ) ? $result['usage'] : array() );
		$usage = self::remember_model( $usage, $result );
		$calls = isset( $result['toolCalls'] ) && is_array( $result['toolCalls'] ) ? $result['toolCalls'] : array();

		if ( count( $calls ) > 0 ) {
			throw new Ai_Agent_Error( 'Model attempted tool calls during finalization after edit turn limit.', true );
		}

		$summary = $this->parse_final_summary( isset( $result['text'] ) ? (string) $result['text'] : '' );
		return $this->completed_step( $state, $state['snapshot'], $summary, $usage, 'finalization', $index + 1, $provider_seconds, 0.0 );
	}

	/** Validate a persisted checkpoint before using it.
	 *
	 * @param array $state Persisted agent state.
	 * @throws Ai_Agent_Error When the state shape is invalid.
	 */
	private function validate_state( array $state ): void {
		$required = array( 'schemaVersion', 'phase', 'turn', 'finalizationTurn', 'messages', 'snapshot', 'selectionRecords', 'appliedEditOperation', 'finishReady', 'usage', 'repeatedFailures' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $state ) ) {
				throw new Ai_Agent_Error( 'The persisted AI agent state is invalid.', false );
			}
		}
		if ( 1 !== (int) $state['schemaVersion'] || ! in_array( $state['phase'], array( 'agent', 'finalization' ), true ) || ! is_array( $state['messages'] ) || ! is_array( $state['snapshot'] ) || ! is_array( $state['selectionRecords'] ) || ! is_array( $state['usage'] ) || ! is_array( $state['repeatedFailures'] ) ) {
			throw new Ai_Agent_Error( 'The persisted AI agent state is invalid.', false );
		}
	}

	/** Build a completed step response.
	 *
	 * @param array  $state            Persisted agent state.
	 * @param array  $snapshot         Completed snapshot.
	 * @param string $summary          Completion summary.
	 * @param array  $usage            Accumulated token usage.
	 * @param string $phase            Current phase.
	 * @param int    $turn             Current turn.
	 * @param float  $provider_seconds Provider duration.
	 * @param float  $tool_seconds     Tool duration.
	 * @return array
	 */
	private function completed_step( array $state, array $snapshot, string $summary, array $usage, string $phase, int $turn, float $provider_seconds, float $tool_seconds ): array {
		$state['snapshot'] = $snapshot;
		$state['usage']    = $usage;
		$metrics           = $this->step_metrics( $phase, $turn, $provider_seconds, $tool_seconds, $usage );
		$this->observe_step( $metrics );
		return array(
			'status'  => 'completed',
			'state'   => $state,
			'result'  => $this->build_result( $snapshot, $summary, $usage ),
			'metrics' => $metrics,
		);
	}

	/** Build a continuation response.
	 *
	 * @param array  $state            Persisted agent state.
	 * @param string $phase            Current phase.
	 * @param int    $turn             Current turn.
	 * @param float  $provider_seconds Provider duration.
	 * @param float  $tool_seconds     Tool duration.
	 * @return array
	 */
	private function continued_step( array $state, string $phase, int $turn, float $provider_seconds, float $tool_seconds ): array {
		$metrics = $this->step_metrics( $phase, $turn, $provider_seconds, $tool_seconds, $state['usage'] );
		$this->observe_step( $metrics );
		return array(
			'status'  => 'continue',
			'state'   => $state,
			'metrics' => $metrics,
		);
	}

	/** Normalize one content-free performance record.
	 *
	 * @param string $phase            Current phase.
	 * @param int    $turn             Current turn.
	 * @param float  $provider_seconds Provider duration.
	 * @param float  $tool_seconds     Tool duration.
	 * @param array  $usage            Accumulated usage.
	 * @return array
	 */
	private function step_metrics( string $phase, int $turn, float $provider_seconds, float $tool_seconds, array $usage ): array {
		return array(
			'phase'      => $phase,
			'turn'       => $turn,
			'providerMs' => (int) round( $provider_seconds * 1000 ),
			'toolMs'     => (int) round( $tool_seconds * 1000 ),
			'usage'      => $usage,
		);
	}

	/** Notify the optional performance observer.
	 *
	 * @param array $metrics Content-free step metrics.
	 */
	private function observe_step( array $metrics ): void {
		if ( null !== $this->observe_step ) {
			call_user_func( $this->observe_step, $metrics );
		}
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
	 * Resolve the ordered model preference from a payload.
	 *
	 * Empty means "auto" (let the AI Client pick), preserving current behavior.
	 *
	 * @param array $payload Request payload.
	 * @return array<int,string>
	 */
	private static function resolve_model_preference( array $payload ): array {
		if ( empty( $payload['modelPreference'] ) || ! is_array( $payload['modelPreference'] ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					static function ( $preference ) {
						return is_string( $preference ) ? trim( $preference ) : '';
					},
					$payload['modelPreference']
				),
				static function ( $preference ) {
					return '' !== $preference;
				}
			)
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

	/** Resolve server-side selection records keyed by opaque selection ID.
	 *
	 * @param array $payload Agent payload.
	 * @return array
	 */
	private function resolve_selection_records( array $payload ): array {
		if ( empty( $payload['selectionRecords'] ) || ! is_array( $payload['selectionRecords'] ) ) {
			return array();
		}
		return $payload['selectionRecords'];
	}

	/** Determine whether at least one selection can safely be resolved.
	 *
	 * @param array $selection_records Selection records keyed by ID.
	 * @return bool
	 */
	private function has_resolvable_selection( array $selection_records ): bool {
		foreach ( $selection_records as $record ) {
			if ( is_array( $record ) && ! empty( $record['resolvable'] ) ) {
				return true;
			}
		}
		return false;
	}

	/** Build a bounded model-facing copy while retaining the canonical history.
	 *
	 * @param array $messages Canonical messages.
	 * @return array
	 */
	private function build_model_context( array $messages ): array {
		$projected               = $messages;
		$budget                  = self::OBSERVATION_CONTEXT_CHARS;
		$total_observation_chars = 0;
		$omitted_observations    = 0;
		for ( $message_index = count( $projected ) - 1; $message_index >= 0; $message_index-- ) {
			if ( empty( $projected[ $message_index ]['toolResponses'] ) || ! is_array( $projected[ $message_index ]['toolResponses'] ) ) {
				continue;
			}
			for ( $response_index = count( $projected[ $message_index ]['toolResponses'] ) - 1; $response_index >= 0; $response_index-- ) {
				$response = $projected[ $message_index ]['toolResponses'][ $response_index ];
				$name     = isset( $response['name'] ) ? (string) $response['name'] : '';
				if ( ! in_array( $name, array( 'read_document', 'read_selection', 'search_text', 'replace_string', 'replace_many', 'list_ai_edits', 'get_ai_edit' ), true ) ) {
					continue;
				}
				$output                   = isset( $response['output'] ) ? $response['output'] : null;
				$length                   = $this->observation_content_length( $name, $output );
				$total_observation_chars += $length;
				$is_error                 = is_array( $output ) && isset( $output['ok'] ) && false === $output['ok'];
				if ( $is_error || $length <= $budget ) {
					$budget -= min( $budget, $length );
					continue;
				}
				$projected[ $message_index ]['toolResponses'][ $response_index ]['output'] = $this->observation_receipt( $name, $output, $length );
				++$omitted_observations;
			}
		}
		$this->model_context_stats = array(
			'canonicalMessageBytes'     => strlen( $this->debug_json( $messages ) ),
			'modelMessageBytes'         => strlen( $this->debug_json( $projected ) ),
			'observationCharacters'     => $total_observation_chars,
			'sentObservationCharacters' => self::OBSERVATION_CONTEXT_CHARS - $budget,
			'omittedObservations'       => $omitted_observations,
		);
		return $projected;
	}

	/** Count the bulky observation portion, excluding small metadata receipts.
	 *
	 * @param string $name   Tool name.
	 * @param mixed  $output Tool output.
	 * @return int
	 */
	private function observation_content_length( string $name, $output ): int {
		if ( is_array( $output ) && in_array( $name, array( 'read_document', 'read_selection' ), true ) ) {
			return isset( $output['content'] ) ? mb_strlen( (string) $output['content'] ) : 0;
		}
		if ( is_array( $output ) && 'search_text' === $name ) {
			$encoded = wp_json_encode( isset( $output['matches'] ) ? $output['matches'] : array() );
			return is_string( $encoded ) ? mb_strlen( $encoded ) : 0;
		}
		$encoded = wp_json_encode( $output );
		return is_string( $encoded ) ? mb_strlen( $encoded ) : 0;
	}

	/** Replace old bulky observation data with a small durable receipt.
	 *
	 * @param string $name   Tool name.
	 * @param mixed  $output Tool output.
	 * @param int    $length Omitted observation length.
	 * @return array
	 */
	private function observation_receipt( string $name, $output, int $length ): array {
		$receipt = array(
			'ok'                 => true,
			'tool'               => $name,
			'observationOmitted' => true,
			'omittedCharacters'  => $length,
		);
		if ( is_array( $output ) ) {
			foreach ( array( 'target', 'baseHash', 'selectionId', 'contentHash', 'startLine', 'endLine', 'totalLines', 'count', 'truncated', 'nextCursor' ) as $key ) {
				if ( array_key_exists( $key, $output ) ) {
					$receipt[ $key ] = $output[ $key ];
				}
			}
		}
		return $receipt;
	}

	/** Raise a recoverable per-turn read budget error.
	 *
	 * @return void
	 * @throws Ai_Tool_Error Always.
	 */
	private function throw_read_budget_exhausted(): void {
		throw new Ai_Tool_Error( 'read_budget_exhausted: this model turn already read 12000 characters.', false );
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
		$summary = $this->parse_final_summary_value( $text, true );
		return is_string( $summary ) ? $summary : '';
	}

	/**
	 * Try to parse a final summary without failing the normal agent turn.
	 *
	 * @param string $text Model text output.
	 * @return string|null Summary string, or null when invalid.
	 */
	private function try_parse_final_summary( string $text ) {
		return $this->parse_final_summary_value( $text, false );
	}

	/**
	 * Parse a final summary, optionally throwing detailed validation errors.
	 *
	 * @param string $text   Model text output.
	 * @param bool   $strict Whether invalid output should throw.
	 * @return string|null Summary string, or null in non-strict mode.
	 *
	 * @throws Ai_Agent_Error When strict parsing fails.
	 */
	private function parse_final_summary_value( string $text, bool $strict ) {
		$parsed = $this->parse_json_object_from_text( $text );
		if ( null === $parsed ) {
			if ( $strict ) {
				throw new Ai_Agent_Error( 'Model response is not valid JSON.', true );
			}
			return null;
		}
		if ( ! isset( $parsed['summary'] ) || ! is_string( $parsed['summary'] ) ) {
			if ( $strict ) {
				throw new Ai_Agent_Error( 'Model response does not match output schema.', true );
			}
			return null;
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
	 * Log a privacy-safe, approximate breakdown of one turn's input tokens.
	 *
	 * Providers expose only the exact total. Per-part values use a deliberately
	 * simple UTF-8 byte/4 estimate and therefore must not be used for billing.
	 * Prompt text and source code are never written to the log.
	 *
	 * @param string $phase    Agent or finalization phase.
	 * @param int    $turn     One-based turn within the phase.
	 * @param array  $messages Conversation sent to the provider.
	 * @param array  $tools    Function declarations sent to the provider.
	 * @param array  $options  Generation options.
	 * @param array  $result   Normalized provider result.
	 * @return void
	 */
	private function log_input_token_breakdown( string $phase, int $turn, array $messages, array $tools, array $options, array $result ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		$parts = array();
		$this->add_debug_part( $parts, 'system_instruction', isset( $options['systemInstruction'] ) ? (string) $options['systemInstruction'] : '' );
		foreach ( $this->debug_input_parts as $name => $value ) {
			$this->add_debug_part( $parts, 'initial_' . $name, $value );
		}
		$this->add_debug_part( $parts, 'initial_prompt_separators', str_repeat( "\n\n", max( count( $this->debug_input_parts ) - 1, 0 ) ) );

		$assistant_text = '';
		$tool_calls     = array();
		$tool_responses = array();
		foreach ( array_slice( $messages, 1 ) as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			if ( isset( $message['text'] ) ) {
				$assistant_text .= (string) $message['text'];
			}
			if ( ! empty( $message['toolCalls'] ) && is_array( $message['toolCalls'] ) ) {
				$tool_calls = array_merge( $tool_calls, $message['toolCalls'] );
			}
			if ( ! empty( $message['toolResponses'] ) && is_array( $message['toolResponses'] ) ) {
				$tool_responses = array_merge( $tool_responses, $message['toolResponses'] );
			}
		}
		$this->add_debug_part( $parts, 'conversation_assistant_text', $assistant_text );
		$this->add_debug_part( $parts, 'conversation_tool_calls', $this->debug_json( $tool_calls ) );
		$this->add_debug_part( $parts, 'conversation_tool_responses', $this->debug_json( $tool_responses ) );
		$this->add_debug_part( $parts, 'tool_definitions', $this->debug_json( $tools ) );
		$this->add_debug_part( $parts, 'response_json_schema', isset( $options['jsonSchema'] ) ? $this->debug_json( $options['jsonSchema'] ) : '' );

		$estimated_total = 0;
		foreach ( $parts as $part ) {
			$estimated_total += $part['estimatedTokens'];
		}
		$usage        = isset( $result['usage'] ) && is_array( $result['usage'] ) ? $result['usage'] : array();
		$input_tokens = isset( $usage['inputTokens'] ) ? max( 0, (int) $usage['inputTokens'] ) : 0;
		$structure    = $this->build_debug_message_structure( $messages );
		$log          = array(
			'jobId'                   => $this->debug_id,
			'phase'                   => $phase,
			'turn'                    => $turn,
			'model'                   => isset( $result['model'] ) ? (string) $result['model'] : '',
			'actualInputTokens'       => $input_tokens,
			'actualCachedInputTokens' => isset( $usage['cachedInputTokens'] ) ? max( 0, (int) $usage['cachedInputTokens'] ) : 0,
			'estimatedPartsTotal'     => $estimated_total,
			'providerOverheadOrError' => $input_tokens - $estimated_total,
			'estimateMethod'          => 'ceil(UTF-8 bytes / 4); per-part values are approximate',
			'contextProjection'       => $this->model_context_stats,
			'editFootprint'           => $this->debug_edit_footprint_stats,
			'messageStructure'        => $structure['messages'],
			'toolTotals'              => $structure['toolTotals'],
			'parts'                   => $parts,
		);
		$encoded      = wp_json_encode( $log, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( is_string( $encoded ) ) {
			error_log( '[Kayzart AI token breakdown] ' . $encoded ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Add one non-empty diagnostic part without retaining its content.
	 *
	 * @param array  $parts Collected diagnostic parts, passed by reference.
	 * @param string $name  Part name.
	 * @param string $value Exact input value used only to calculate its size.
	 * @return void
	 */
	private function add_debug_part( array &$parts, string $name, string $value ): void {
		if ( '' === $value ) {
			return;
		}
		$bytes          = strlen( $value );
		$parts[ $name ] = array(
			'characters'      => mb_strlen( $value ),
			'bytes'           => $bytes,
			'estimatedTokens' => (int) ceil( $bytes / 4 ),
		);
	}

	/**
	 * JSON-encode a diagnostic structure for size estimation only.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private function debug_json( $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}

	/** Build content-free per-message and per-tool size diagnostics.
	 *
	 * @param array $messages Normalized messages sent to the client.
	 * @return array
	 */
	private function build_debug_message_structure( array $messages ): array {
		$message_metrics = array();
		$tool_totals     = array();
		foreach ( $messages as $index => $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}
			$text   = isset( $message['text'] ) ? (string) $message['text'] : '';
			$metric = array(
				'index' => (int) $index,
				'role'  => isset( $message['role'] ) ? (string) $message['role'] : '',
				'text'  => $this->debug_string_size( $text ),
			);
			if ( ! empty( $message['toolCalls'] ) && is_array( $message['toolCalls'] ) ) {
				$metric['toolCalls'] = array();
				foreach ( $message['toolCalls'] as $call ) {
					$call                  = is_array( $call ) ? $call : array();
					$name                  = isset( $call['name'] ) ? (string) $call['name'] : '';
					$args                  = isset( $call['args'] ) && is_array( $call['args'] ) ? $call['args'] : array();
					$args_bytes            = strlen( $this->debug_json( $args ) );
					$metric['toolCalls'][] = array(
						'id'            => isset( $call['id'] ) ? (string) $call['id'] : '',
						'name'          => $name,
						'argumentBytes' => $args_bytes,
						'stringFields'  => $this->debug_string_fields( $args ),
					);
					if ( ! isset( $tool_totals[ $name ] ) ) {
						$tool_totals[ $name ] = array(
							'callCount'     => 0,
							'argumentBytes' => 0,
							'responseCount' => 0,
							'responseBytes' => 0,
						);
					}
					++$tool_totals[ $name ]['callCount'];
					$tool_totals[ $name ]['argumentBytes'] += $args_bytes;
				}
			}
			if ( ! empty( $message['toolResponses'] ) && is_array( $message['toolResponses'] ) ) {
				$metric['toolResponses'] = array();
				foreach ( $message['toolResponses'] as $response ) {
					$response                  = is_array( $response ) ? $response : array();
					$name                      = isset( $response['name'] ) ? (string) $response['name'] : '';
					$output                    = isset( $response['output'] ) ? $response['output'] : null;
					$output_bytes              = strlen( $this->debug_json( $output ) );
					$metric['toolResponses'][] = array(
						'callId'       => isset( $response['callId'] ) ? (string) $response['callId'] : '',
						'name'         => $name,
						'outputBytes'  => $output_bytes,
						'stringFields' => $this->debug_string_fields( $output ),
					);
					if ( ! isset( $tool_totals[ $name ] ) ) {
						$tool_totals[ $name ] = array(
							'callCount'     => 0,
							'argumentBytes' => 0,
							'responseCount' => 0,
							'responseBytes' => 0,
						);
					}
					++$tool_totals[ $name ]['responseCount'];
					$tool_totals[ $name ]['responseBytes'] += $output_bytes;
				}
			}
			$message_metrics[] = $metric;
		}
		ksort( $tool_totals );
		return array(
			'messages'   => $message_metrics,
			'toolTotals' => $tool_totals,
		);
	}

	/** Return string leaf sizes without retaining their contents.
	 *
	 * @param mixed  $value Current value.
	 * @param string $path  Dot-separated field path.
	 * @param array  $found Collected metrics.
	 * @return array
	 */
	private function debug_string_fields( $value, string $path = '', array &$found = array() ): array {
		if ( count( $found ) >= 100 ) {
			return $found;
		}
		if ( is_string( $value ) ) {
			$found[ '' !== $path ? $path : 'value' ] = $this->debug_string_size( $value );
			return $found;
		}
		if ( ! is_array( $value ) ) {
			return $found;
		}
		foreach ( $value as $key => $item ) {
			$next_path = '' === $path ? (string) $key : $path . '.' . $key;
			$this->debug_string_fields( $item, $next_path, $found );
		}
		return $found;
	}

	/** Return exact size metadata for one string.
	 *
	 * @param string $value String value.
	 * @return array
	 */
	private function debug_string_size( string $value ): array {
		return array(
			'characters' => mb_strlen( $value ),
			'bytes'      => strlen( $value ),
		);
	}

	/** Build content-free size metrics for the latest edit footprint.
	 *
	 * @param array $payload Agent request payload.
	 * @return array
	 */
	private function build_debug_edit_footprint_stats( array $payload ): array {
		$history = isset( $payload['recentEditContext'] ) && is_array( $payload['recentEditContext'] ) ? $payload['recentEditContext'] : array();
		if ( 0 === count( $history ) ) {
			return array( 'present' => false );
		}
		$latest    = end( $history );
		$footprint = is_array( $latest ) && isset( $latest['editFootprint'] ) && is_array( $latest['editFootprint'] ) ? $latest['editFootprint'] : array();
		if ( 0 === count( $footprint ) ) {
			return array( 'present' => false );
		}
		$content = '';
		$changes = isset( $footprint['changes'] ) && is_array( $footprint['changes'] ) ? $footprint['changes'] : array();
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$content .= isset( $change['before'] ) ? (string) $change['before'] : '';
			$content .= isset( $change['after'] ) ? (string) $change['after'] : '';
		}
		$json = $this->debug_json( $footprint );
		return array(
			'present'           => true,
			'validation'        => isset( $footprint['validation'] ) ? (string) $footprint['validation'] : '',
			'changeCount'       => count( $changes ),
			'contentCharacters' => mb_strlen( $content ),
			'contentBytes'      => strlen( $content ),
			'jsonCharacters'    => mb_strlen( $json ),
			'jsonBytes'         => strlen( $json ),
		);
	}

	/** Log the normalized request immediately before the provider call.
	 *
	 * @param string $phase   Agent phase.
	 * @param int    $turn    One-based phase turn.
	 * @param array  $messages Normalized messages.
	 * @param array  $tools    Tool definitions.
	 * @param array  $options  Generation options.
	 * @return void
	 */
	private function log_model_request_trace( string $phase, int $turn, array $messages, array $tools, array $options ): void {
		$this->write_model_trace(
			'[Kayzart AI request trace] ',
			$phase,
			$turn,
			array(
				'normalizedLayer'   => 'Ai_Client_Interface input; not raw HTTP wire format',
				'initialInputParts' => $this->debug_input_parts,
				'messages'          => $messages,
				'tools'             => $tools,
				'options'           => $options,
			)
		);
	}

	/** Log the normalized provider response.
	 *
	 * @param string $phase  Agent phase.
	 * @param int    $turn   One-based phase turn.
	 * @param array  $result Normalized result.
	 * @return void
	 */
	private function log_model_response_trace( string $phase, int $turn, array $result ): void {
		$this->write_model_trace( '[Kayzart AI response trace] ', $phase, $turn, array( 'result' => $result ) );
	}

	/** Encode and emit one opt-in trace event.
	 *
	 * @param string $prefix  Log prefix.
	 * @param string $phase   Agent phase.
	 * @param int    $turn    One-based phase turn.
	 * @param array  $payload Trace payload.
	 * @return void
	 */
	private function write_model_trace( string $prefix, string $phase, int $turn, array $payload ): void {
		if ( ! $this->is_debug_logging_enabled() || 'off' === $this->debug_trace_mode ) {
			return;
		}
		$log     = $this->build_model_trace_event( $phase, $turn, $payload, $this->debug_trace_mode );
		$encoded = $this->debug_json( $log );
		if ( '' !== $encoded ) {
			error_log( $prefix . $encoded ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Build one trace event, applying the full-to-preview size fallback.
	 *
	 * @param string $phase   Agent phase.
	 * @param int    $turn    One-based phase turn.
	 * @param array  $payload Trace payload.
	 * @param string $mode    preview or full.
	 * @return array
	 */
	private function build_model_trace_event( string $phase, int $turn, array $payload, string $mode ): array {
		$log     = array(
			'jobId' => $this->debug_id,
			'phase' => $phase,
			'turn'  => $turn,
			'mode'  => $mode,
			'data'  => $this->trace_value( $payload, $mode ),
		);
		$encoded = $this->debug_json( $log );
		if ( 'full' === $mode && strlen( $encoded ) > self::DEBUG_TRACE_MAX_BYTES ) {
			$original_bytes                = strlen( $encoded );
			$log['mode']                   = 'preview';
			$log['fullTraceOmitted']       = true;
			$log['fullTraceOmittedReason'] = 'encoded event exceeded 1 MiB';
			$log['originalEncodedBytes']   = $original_bytes;
			$log['data']                   = $this->trace_value( $payload, 'preview' );
		}
		return $log;
	}

	/** Convert normalized data into preview/full trace-safe data.
	 *
	 * @param mixed  $value Value to convert.
	 * @param string $mode  preview or full.
	 * @param string $key   Current field name.
	 * @return mixed
	 */
	private function trace_value( $value, string $mode, string $key = '' ) {
		if ( is_string( $value ) ) {
			if ( $this->is_opaque_trace_key( $key ) ) {
				return $this->trace_string_record( $value, 0, true );
			}
			return 'full' === $mode ? $value : $this->trace_string_record( $value, self::DEBUG_TRACE_PREVIEW_CHARS, false );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$result = array();
		foreach ( $value as $item_key => $item ) {
			$result[ $item_key ] = $this->trace_value( $item, $mode, is_string( $item_key ) ? $item_key : '' );
		}
		return $result;
	}

	/** Build a preview or opaque string record.
	 *
	 * @param string $value  Original string.
	 * @param int    $limit  Preview character limit.
	 * @param bool   $opaque Whether content must be redacted.
	 * @return array
	 */
	private function trace_string_record( string $value, int $limit, bool $opaque ): array {
		$characters = mb_strlen( $value );
		$record     = array(
			'characters' => $characters,
			'bytes'      => strlen( $value ),
			'sha256'     => hash( 'sha256', $value ),
			'truncated'  => $opaque || $characters > $limit,
		);
		if ( $opaque ) {
			$record['opaque'] = true;
		} else {
			$record['preview'] = mb_substr( $value, 0, $limit );
		}
		return $record;
	}

	/** Determine whether a field contains an opaque provider/internal token.
	 *
	 * @param string $key Field name.
	 * @return bool
	 */
	private function is_opaque_trace_key( string $key ): bool {
		return in_array(
			strtolower( $key ),
			array(
				'thoughtsignature',
				'cursor',
				'nextcursor',
				'authorization',
				'apikey',
				'api_key',
				'accesstoken',
				'access_token',
				'refreshtoken',
				'refresh_token',
				'password',
				'secret',
			),
			true
		);
	}

	/** Check the standard WordPress debug logging gates.
	 *
	 * @return bool
	 */
	private function is_debug_logging_enabled(): bool {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/** Resolve the opt-in trace mode constant.
	 *
	 * @return string off, preview, or full.
	 */
	private static function resolve_debug_trace_mode(): string {
		$value = defined( 'KAYZART_AI_DEBUG_TRACE' ) ? KAYZART_AI_DEBUG_TRACE : '';
		return self::normalize_debug_trace_mode( $value );
	}

	/** Normalize a raw trace mode value.
	 *
	 * @param mixed $value Raw setting.
	 * @return string off, preview, or full.
	 */
	private static function normalize_debug_trace_mode( $value ): string {
		$mode = strtolower( trim( (string) $value ) );
		return in_array( $mode, array( 'preview', 'full' ), true ) ? $mode : 'off';
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
			'model'                 => '',
		);
	}

	/**
	 * Remember the model a turn used, keeping the most recent non-empty value.
	 *
	 * Carried on the usage array so it flows through finalization and the result
	 * without extra signatures. Turns normally use one model per request.
	 *
	 * @param array $usage  Running usage total.
	 * @param mixed $result Normalized turn result.
	 * @return array
	 */
	private static function remember_model( array $usage, $result ): array {
		if ( is_array( $result ) && isset( $result['model'] ) && '' !== (string) $result['model'] ) {
			$usage['model'] = (string) $result['model'];
		}
		return $usage;
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
