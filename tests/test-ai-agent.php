<?php
/**
 * Unit tests for the AI agent loop, driven by the fake client.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Agent;
use KayzArt\Ai_Agent_Error;
use KayzArt\Ai_Agent_Canceled;
use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Message;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/**
 * Verify multi-turn tool calling, recovery, guards and finalization.
 */
class Test_Kayzart_Ai_Agent extends WP_UnitTestCase {

	/**
	 * A minimal normal-mode payload.
	 *
	 * @param string $html Initial HTML.
	 * @return array
	 */
	private function payload( string $html = '<main>Hello</main>' ): array {
		return array(
			'editorMode' => 'normal',
			'prompt'     => 'change greeting',
			'html'       => $html,
			'customHead' => '',
			'css'        => '',
			'js'         => '',
			'jsMode'     => 'classic',
		);
	}

	/** Invoke a private agent diagnostic helper.
	 *
	 * @param Ai_Agent $agent  Agent instance.
	 * @param string   $method Method name.
	 * @param array    $args   Method arguments.
	 * @return mixed
	 */
	private function invoke_agent_helper( Ai_Agent $agent, string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Ai_Agent::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $agent, $args );
	}

	/**
	 * Build a replace_string tool call.
	 *
	 * @param string $id   Call id.
	 * @param string $from From string.
	 * @param string $to   To string.
	 * @return array
	 */
	private function replace_call( string $id, string $from, string $to ): array {
		return Ai_Message::tool_call(
			$id,
			'replace_string',
			array(
				'target' => 'html',
				'from'   => $from,
				'to'     => $to,
			)
		);
	}

	/**
	 * A tool edit followed by a valid summary completes without finalization.
	 */
	public function test_happy_path_edit_then_summary(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		$fake->queue_final_text( '{"summary":"Changed greeting to World."}' );

		$agent  = new Ai_Agent( $fake );
		$result = $agent->run( $this->payload() );

		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Changed greeting to World.', $result['summary'] );

		// Second turn must carry the assistant tool call + tool response history.
		$second_turn_messages = $fake->calls()[1]['messages'];
		$roles                = array_column( $second_turn_messages, 'role' );
		$this->assertContains( 'assistant', $roles );
		$this->assertContains( 'tool', $roles );

		$calls = $fake->calls();
		$this->assertArrayNotHasKey( 'jsonSchema', $calls[0]['options'] );
		$this->assertArrayNotHasKey( 'jsonSchema', $calls[1]['options'] );
		$this->assertCount( 2, $calls );
	}

	/**
	 * A non-JSON stop response falls back to one tool-free finalization turn.
	 */
	public function test_non_json_stop_response_falls_back_to_finalization(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		$fake->queue_final_text( 'Editing complete.' );
		$fake->queue_final_text( '{"summary":"Changed greeting to World."}' );

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$calls  = $fake->calls();

		$this->assertSame( 'Changed greeting to World.', $result['summary'] );
		$this->assertCount( 3, $calls );
		$this->assertSame( Ai_Agent::FINAL_SUMMARY_JSON_SCHEMA, $calls[2]['options']['jsonSchema'] );
		$this->assertSame( array(), $calls[2]['tools'] );
	}

	/**
	 * Missing and non-string summaries both fall back to finalization.
	 */
	public function test_invalid_summary_shapes_fall_back_to_finalization(): void {
		foreach ( array( '{"message":"done"}', '{"summary":123}' ) as $invalid_summary ) {
			$fake = new Ai_Client_Fake();
			$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
			$fake->queue_final_text( $invalid_summary );
			$fake->queue_final_text( '{"summary":"Fallback summary."}' );

			$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );

			$this->assertSame( 'Fallback summary.', $result['summary'] );
			$this->assertCount( 3, $fake->calls() );
		}
	}

	/**
	 * Finalizing without any edit is rejected.
	 */
	public function test_finalizing_without_edit_throws(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_final_text( '{"summary":"nothing"}' );

		$this->expectException( Ai_Agent_Error::class );
		$this->expectExceptionMessage( 'No edit operations were applied' );
		( new Ai_Agent( $fake ) )->run( $this->payload() );
	}

	/**
	 * A recoverable tool error is fed back and the loop can still succeed.
	 */
	public function test_recovers_from_tool_error(): void {
		$fake = new Ai_Client_Fake();
		// First a miss (0 occurrences), then a valid edit, a stop turn, and the summary.
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Missing', 'X' ) ) );
		$fake->queue_tool_calls( array( $this->replace_call( 'c2', 'Hello', 'World' ) ) );
		$fake->queue_final_text( 'Editing complete.' );
		$fake->queue_final_text( '{"summary":"Recovered and edited."}' );

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Recovered and edited.', $result['summary'] );

		// The failing call's response must be a recoverable error payload.
		$tool_message = null;
		foreach ( $fake->calls()[1]['messages'] as $message ) {
			if ( 'tool' === $message['role'] ) {
				$tool_message = $message;
			}
		}
		$this->assertNotNull( $tool_message );
		$this->assertFalse( $tool_message['toolResponses'][0]['output']['ok'] );
	}

	/**
	 * Repeating the same failing replacement trips the guard.
	 */
	public function test_repeated_failure_guard(): void {
		$fake = new Ai_Client_Fake();
		for ( $i = 0; $i < Ai_Agent::REPEATED_TOOL_FAILURE_LIMIT; $i++ ) {
			$fake->queue_tool_calls( array( $this->replace_call( 'c' . $i, 'Missing', 'X' ) ) );
		}

		$this->expectException( Ai_Agent_Error::class );
		$this->expectExceptionMessage( 'Repeated exact replacement failed' );
		( new Ai_Agent( $fake ) )->run( $this->payload() );
	}

	/**
	 * Cancellation aborts the loop.
	 */
	public function test_cancellation(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_final_text( '{"summary":"never"}' );

		$this->expectException( Ai_Agent_Canceled::class );
		( new Ai_Agent(
			$fake,
			array(
				'isCanceled' => static function () {
					return true;
				},
			)
		) )->run( $this->payload() );
	}

	/**
	 * Progress and tool events are emitted to the hook.
	 */
	public function test_emits_events(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		$fake->queue_final_text( 'Editing complete.' );
		$fake->queue_final_text( '{"summary":"ok"}' );

		$events = array();
		$agent  = new Ai_Agent(
			$fake,
			array(
				'emit' => static function ( array $event ) use ( &$events ) {
					$events[] = $event['event'];
				},
			)
		);
		$agent->run( $this->payload() );

		$this->assertContains( 'progress', $events );
		$this->assertContains( 'tool_start', $events );
		$this->assertContains( 'tool_end', $events );
	}

	/**
	 * Parallel tool calls are all executed and preserved for the next turn.
	 */
	public function test_parallel_tool_calls_continue_to_summary(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_result(
			array(
				'toolCalls' => array(
					Ai_Message::tool_call(
						'search-1',
						'search_text',
						array(
							'query'  => 'Hello',
							'target' => 'html',
						)
					),
					$this->replace_call( 'replace-1', 'Hello', 'World' ),
				),
				'usage'     => array(
					'inputTokens'  => 10,
					'outputTokens' => 4,
				),
			)
		);
		$fake->queue_result(
			array(
				'text'  => '{"summary":"Searched and changed the greeting."}',
				'usage' => array(
					'inputTokens'  => 20,
					'outputTokens' => 3,
				),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );

		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Searched and changed the greeting.', $result['summary'] );
		$this->assertSame( 30, $result['usage']['inputTokens'] );
		$this->assertSame( 7, $result['usage']['outputTokens'] );
		$this->assertCount( 2, $fake->calls() );

		$second_turn_messages = $fake->calls()[1]['messages'];
		$this->assertCount( 2, $second_turn_messages[1]['toolCalls'] );
		$this->assertSame( 'search-1', $second_turn_messages[1]['toolCalls'][0]['id'] );
		$this->assertSame( 'replace-1', $second_turn_messages[1]['toolCalls'][1]['id'] );
		$this->assertCount( 2, $second_turn_messages[2]['toolResponses'] );
		$this->assertSame( 'search-1', $second_turn_messages[2]['toolResponses'][0]['callId'] );
		$this->assertSame( 'replace-1', $second_turn_messages[2]['toolResponses'][1]['callId'] );
	}

	/**
	 * Reaching the turn limit after an edit runs a finalization turn.
	 */
	public function test_finalization_after_turn_limit(): void {
		$fake = new Ai_Client_Fake();
		// Turn 1 applies a real edit.
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		// Remaining edit turns use a non-editing tool so the limit is reached.
		for ( $i = 1; $i < Ai_Agent::MAX_AGENT_TURNS; $i++ ) {
			$fake->queue_tool_calls(
				array(
					Ai_Message::tool_call( 's' . $i, 'search_text', array( 'query' => 'World' ) ),
				)
			);
		}
		// Finalization turn returns the summary.
		$fake->queue_final_text( '{"summary":"Finalized after limit."}' );

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( 'Finalized after limit.', $result['summary'] );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		// 15 loop turns + 1 finalization turn.
		$this->assertCount( Ai_Agent::MAX_AGENT_TURNS + 1, $fake->calls() );
	}

	/** Old bulky read observations are receipts while recent observations remain. */
	public function test_model_context_compacts_old_read_observations(): void {
		$fake = new Ai_Client_Fake();
		$read = Ai_Message::tool_call(
			'r1',
			'read_document',
			array(
				'target'   => 'html',
				'maxChars' => 12000,
			)
		);
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'World' ), $read ) );
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'r2',
					'read_document',
					array(
						'target'   => 'html',
						'maxChars' => 12000,
					)
				),
			)
		);
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'r3',
					'read_document',
					array(
						'target'   => 'html',
						'maxChars' => 12000,
					)
				),
			)
		);
		$fake->queue_final_text( '{"summary":"done"}' );

		( new Ai_Agent( $fake ) )->run( $this->payload( '<main>Hello' . str_repeat( 'x', 40000 ) . '</main>' ) );
		$messages = $fake->calls()[3]['messages'];
		$outputs  = array();
		foreach ( $messages as $message ) {
			foreach ( isset( $message['toolResponses'] ) ? $message['toolResponses'] : array() as $response ) {
				if ( 'read_document' === $response['name'] ) {
					$outputs[] = $response['output'];
				}
			}
		}
		$this->assertTrue( $outputs[0]['observationOmitted'] );
		$this->assertArrayHasKey( 'content', $outputs[1] );
		$this->assertArrayHasKey( 'content', $outputs[2] );
	}

	/** Parallel reads share one 12k-character budget for the model turn. */
	public function test_parallel_reads_share_turn_budget(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e1', 'Hello', 'World' ),
				Ai_Message::tool_call( 'r1', 'read_document', array( 'target' => 'html' ) ),
				Ai_Message::tool_call( 'r2', 'read_document', array( 'target' => 'html' ) ),
			)
		);
		$fake->queue_final_text( '{"summary":"done"}' );
		( new Ai_Agent( $fake ) )->run( $this->payload( '<main>Hello' . str_repeat( 'x', 20000 ) . '</main>' ) );

		$responses = $fake->calls()[1]['messages'][2]['toolResponses'];
		$this->assertSame( 8000, mb_strlen( $responses[1]['output']['content'] ) );
		$this->assertSame( 4000, mb_strlen( $responses[2]['output']['content'] ) );
	}

	/** Size diagnostics identify tool fields without retaining their text. */
	public function test_debug_message_structure_has_tool_field_sizes_only(): void {
		$agent     = new Ai_Agent( new Ai_Client_Fake() );
		$secret    = 'private-from-value';
		$messages  = array(
			Ai_Message::assistant(
				'',
				array(
					Ai_Message::tool_call(
						'call-1',
						'replace_string',
						array(
							'target' => 'html',
							'from'   => $secret,
							'to'     => 'replacement',
						)
					),
				)
			),
			Ai_Message::tool( array( Ai_Message::tool_response( 'call-1', 'replace_string', array( 'content' => 'result' ) ) ) ),
		);
		$structure = $this->invoke_agent_helper( $agent, 'build_debug_message_structure', array( $messages ) );

		$this->assertSame( strlen( $secret ), $structure['messages'][0]['toolCalls'][0]['stringFields']['from']['bytes'] );
		$this->assertSame( 1, $structure['toolTotals']['replace_string']['callCount'] );
		$this->assertSame( 1, $structure['toolTotals']['replace_string']['responseCount'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $structure ) );
	}

	/** Preview traces truncate UTF-8 safely and include source metadata. */
	public function test_preview_trace_string_is_bounded_and_hashed(): void {
		$agent = new Ai_Agent( new Ai_Client_Fake() );
		$value = str_repeat( 'あ', 600 );
		$trace = $this->invoke_agent_helper( $agent, 'trace_value', array( array( 'content' => $value ), 'preview' ) );

		$this->assertSame( 500, mb_strlen( $trace['content']['preview'] ) );
		$this->assertSame( 600, $trace['content']['characters'] );
		$this->assertSame( hash( 'sha256', $value ), $trace['content']['sha256'] );
		$this->assertTrue( $trace['content']['truncated'] );
	}

	/** Full traces preserve normal text but redact opaque tokens. */
	public function test_full_trace_redacts_opaque_values(): void {
		$agent = new Ai_Agent( new Ai_Client_Fake() );
		$trace = $this->invoke_agent_helper(
			$agent,
			'trace_value',
			array(
				array(
					'text'             => 'visible model text',
					'thoughtSignature' => 'provider-secret',
					'cursor'           => 'opaque-cursor',
					'nextCursor'       => 'opaque-next-cursor',
					'authorization'    => 'Bearer transport-secret',
				),
				'full',
			)
		);

		$this->assertSame( 'visible model text', $trace['text'] );
		$this->assertTrue( $trace['thoughtSignature']['opaque'] );
		$this->assertTrue( $trace['cursor']['opaque'] );
		$this->assertTrue( $trace['nextCursor']['opaque'] );
		$this->assertTrue( $trace['authorization']['opaque'] );
		$this->assertStringNotContainsString( 'provider-secret', wp_json_encode( $trace ) );
	}

	/** Oversized full events fall back to valid preview structures. */
	public function test_oversized_full_trace_falls_back_to_preview(): void {
		$agent = new Ai_Agent( new Ai_Client_Fake() );
		$event = $this->invoke_agent_helper(
			$agent,
			'build_model_trace_event',
			array( 'agent', 1, array( 'text' => str_repeat( 'x', Ai_Agent::DEBUG_TRACE_MAX_BYTES + 1 ) ), 'full' )
		);

		$this->assertSame( 'preview', $event['mode'] );
		$this->assertTrue( $event['fullTraceOmitted'] );
		$this->assertSame( 500, strlen( $event['data']['text']['preview'] ) );
		$this->assertNotFalse( wp_json_encode( $event ) );
	}

	/** Trace mode accepts only the two explicit opt-in values. */
	public function test_debug_trace_mode_normalization(): void {
		$agent = new Ai_Agent( new Ai_Client_Fake() );
		$this->assertSame( 'preview', $this->invoke_agent_helper( $agent, 'normalize_debug_trace_mode', array( ' PREVIEW ' ) ) );
		$this->assertSame( 'full', $this->invoke_agent_helper( $agent, 'normalize_debug_trace_mode', array( 'full' ) ) );
		$this->assertSame( 'off', $this->invoke_agent_helper( $agent, 'normalize_debug_trace_mode', array( 'invalid' ) ) );
		$this->assertSame( 'off', $this->invoke_agent_helper( $agent, 'normalize_debug_trace_mode', array( true ) ) );
	}
}
