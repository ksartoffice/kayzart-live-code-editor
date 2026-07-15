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
	 * A tool edit followed by a final summary returns the edited snapshot.
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
		// First a miss (0 occurrences), then a valid edit, then the summary.
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Missing', 'X' ) ) );
		$fake->queue_tool_calls( array( $this->replace_call( 'c2', 'Hello', 'World' ) ) );
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
}
