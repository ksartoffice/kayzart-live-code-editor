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
use KayzArt\Ai_Prompt;

require_once __DIR__ . '/doubles/class-kayzart-ai-client-fake.php';

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
			'editorMode'  => 'normal',
			'prompt'      => 'change greeting',
			'canEditHead' => true,
			'html'        => $html,
			'customHead'  => '',
			'css'         => '',
			'js'          => '',
			'jsMode'      => 'classic',
		);
	}

	/** A page-creation payload over blank sources.
	 *
	 * @param string $editor_mode Editor mode.
	 * @param string $css         Initial CSS.
	 * @return array
	 */
	private function create_payload( string $editor_mode = 'normal', string $css = '' ): array {
		$payload               = $this->payload( '' );
		$payload['intent']     = 'create';
		$payload['editorMode'] = $editor_mode;
		$payload['prompt']     = 'A page for an apple farm';
		$payload['css']        = $css;
		return $payload;
	}

	/** Encode a generation-workflow response.
	 *
	 * @param array $overrides Fields to replace or, with a null value, remove.
	 * @return string
	 */
	private function generated_page( array $overrides = array() ): string {
		$page = array_merge(
			array(
				'css'     => 'main { color: #1f2937; }',
				'head'    => '<title>Apple farm</title>',
				'html'    => '<main><h1>Apples</h1></main>',
				'summary' => 'Built an apple farm page.',
			),
			$overrides
		);
		return (string) wp_json_encode( array_filter( $page, 'is_string' ) );
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

	/** Build a finish_edit marker call.
	 *
	 * @param string $id      Call ID.
	 * @param mixed  $summary Summary argument.
	 * @return array
	 */
	private function finish_call( string $id, $summary ): array {
		return Ai_Message::tool_call( $id, 'finish_edit', array( 'summary' => $summary ) );
	}

	/** Build a finish_without_edit marker call.
	 *
	 * @param string $id      Call ID.
	 * @param string $summary Summary argument.
	 */
	private function finish_without_edit_call( string $id, string $summary ): array {
		return Ai_Message::tool_call( $id, 'finish_without_edit', array( 'summary' => $summary ) );
	}

	/** Agent tool exposure follows the presence of a resolvable selection. */
	/**
	 * Page creation raises the provider timeout; editing keeps core's default.
	 */
	public function test_create_intent_raises_the_request_timeout(): void {
		$creating           = $this->payload();
		$creating['intent'] = 'create';

		$this->assertSame( Ai_Agent::CREATE_REQUEST_TIMEOUT_SECONDS, Ai_Agent::resolve_request_timeout( $creating ) );
		$this->assertNull( Ai_Agent::resolve_request_timeout( $this->payload() ) );

		$editing           = $this->payload();
		$editing['intent'] = 'edit';
		$this->assertNull( Ai_Agent::resolve_request_timeout( $editing ) );
	}

	/**
	 * Sites can tune the creation timeout, and a bad value falls back to default.
	 */
	public function test_create_request_timeout_filter(): void {
		$payload           = $this->payload();
		$payload['intent'] = 'create';

		add_filter( 'kayzart_ai_create_request_timeout', static fn() => 45 );
		$this->assertSame( 45.0, Ai_Agent::resolve_request_timeout( $payload ) );
		remove_all_filters( 'kayzart_ai_create_request_timeout' );

		add_filter( 'kayzart_ai_create_request_timeout', '__return_zero' );
		$this->assertNull( Ai_Agent::resolve_request_timeout( $payload ) );
		remove_all_filters( 'kayzart_ai_create_request_timeout' );
	}

	/**
	 * The resolved timeout reaches the client as a generation option.
	 */
	public function test_request_timeout_is_passed_to_the_client(): void {
		$creating           = $this->payload();
		$creating['intent'] = 'create';

		$client = new Ai_Client_Fake();
		$client->queue_final_text( $this->generated_page() );
		( new Ai_Agent( $client ) )->run( $creating );
		$this->assertSame( Ai_Agent::CREATE_REQUEST_TIMEOUT_SECONDS, $client->calls()[0]['options']['requestTimeout'] );

		$editor = new Ai_Client_Fake();
		$editor->queue_final_text( '{"summary":"not edited"}' );
		try {
			( new Ai_Agent( $editor ) )->run( $this->payload() );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'No edit operations', $error->getMessage() );
		}
		$this->assertArrayNotHasKey( 'requestTimeout', $editor->calls()[0]['options'] );
	}

	/** Selection tools appear only when the payload carries resolvable selections. */
	public function test_agent_builds_selection_aware_tool_schema(): void {
		$without_selection = new Ai_Client_Fake();
		$without_selection->queue_final_text( '{"summary":"not edited"}' );
		try {
			( new Ai_Agent( $without_selection ) )->run( $this->payload() );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'No edit operations', $error->getMessage() );
		}
		$without_names = array_column( $without_selection->calls()[0]['tools'], 'name' );
		$this->assertNotContains( 'read_selection', $without_names );
		$replace = array_values(
			array_filter(
				$without_selection->calls()[0]['tools'],
				static function ( $tool ) {
					return 'replace_string' === $tool['name'];
				}
			)
		)[0];
		$this->assertArrayNotHasKey( 'selectionId', $replace['parameters']['properties'] );

		$html           = '<main>Hello</main>';
		$with_selection = new Ai_Client_Fake();
		$with_selection->queue_final_text( '{"summary":"not edited"}' );
		$payload                     = $this->payload( $html );
		$payload['selectionRecords'] = array(
			's1' => array(
				'startOffset' => 0,
				'endOffset'   => strlen( $html ),
				'contentHash' => hash( 'sha256', $html ),
				'resolvable'  => true,
			),
		);
		try {
			( new Ai_Agent( $with_selection ) )->run( $payload );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'No edit operations', $error->getMessage() );
		}
		$with_names = array_column( $with_selection->calls()[0]['tools'], 'name' );
		$this->assertContains( 'read_selection', $with_names );
	}

	/** Editing exposes and executes scoped history/font readers; creation does not. */
	public function test_agent_builds_context_tools_for_editing_only(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call( 'h1', 'list_ai_edits', array( 'limit' => 3 ) ),
				Ai_Message::tool_call( 'f1', 'list_available_fonts', array() ),
			)
		);
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'World' ), $this->finish_call( 'done', 'Updated.' ) ) );
		$history_args  = null;
		$agent         = new Ai_Agent(
			$fake,
			array(
				'historyTool' => static function ( string $name, array $args ) use ( &$history_args ): array {
					$history_args = array( $name, $args );
					return array(
						'ok'    => true,
						'items' => array(),
					);
				},
				'fontTool'    => static function (): array {
					return array(
						'ok'         => true,
						'registered' => array(),
						'system'     => array(),
					);
				},
			)
		);
		$result        = $agent->run( $this->payload() );
		$editing_names = array_column( $fake->calls()[0]['tools'], 'name' );
		$this->assertContains( 'list_ai_edits', $editing_names );
		$this->assertContains( 'get_ai_edit', $editing_names );
		$this->assertContains( 'list_available_fonts', $editing_names );
		$this->assertSame( array( 'list_ai_edits', array( 'limit' => 3 ) ), $history_args );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );

		$creation           = $this->payload( '' );
		$creation['intent'] = 'create';
		$create_fake        = new Ai_Client_Fake();
		// An unusable generation response sends creation to the tool loop, which
		// is where its tool exposure can be observed.
		$create_fake->queue_final_text( 'not json' );
		$create_fake->queue_final_text( '{"summary":"not edited"}' );
		try {
			( new Ai_Agent(
				$create_fake,
				array(
					'historyTool' => '__return_empty_array',
					'fontTool'    => '__return_empty_array',
				)
			) )->run( $creation );
			$this->fail( 'Expected creation without an edit operation to fail.' );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'No edit operations', $error->getMessage() );
		}
		$this->assertSame( array(), $create_fake->calls()[0]['tools'] );
		$creation_names = array_column( $create_fake->calls()[1]['tools'], 'name' );
		$this->assertNotContains( 'list_ai_edits', $creation_names );
		$this->assertNotContains( 'list_available_fonts', $creation_names );
	}

	/** Page creation writes every target from one structured call without tools. */
	public function test_create_generates_the_page_in_one_call(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_result(
			array(
				'text'  => $this->generated_page(),
				'usage' => array(
					'inputTokens'  => 3000,
					'outputTokens' => 900,
				),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->create_payload() );

		$this->assertCount( 1, $fake->calls() );
		$options = $fake->calls()[0]['options'];
		$this->assertSame( array(), $fake->calls()[0]['tools'] );
		$this->assertSame( Ai_Prompt::generation_system_prompt( 'normal' ), $options['systemInstruction'] );
		$this->assertSame( array( 'css', 'head', 'html', 'summary' ), array_keys( $options['jsonSchema']['properties'] ) );
		$this->assertSame( array( 'css', 'head', 'html', 'summary' ), $options['jsonSchema']['required'] );
		$this->assertSame( '<main><h1>Apples</h1></main>', $result['snapshot']['html'] );
		$this->assertSame( '<title>Apple farm</title>', $result['snapshot']['customHead'] );
		$this->assertSame( 'main { color: #1f2937; }', $result['snapshot']['css'] );
		$this->assertSame( 'Built an apple farm page.', $result['summary'] );
		$this->assertSame( 3000, $result['usage']['inputTokens'] );
	}

	/** A user who cannot persist head edits is never offered a head field. */
	public function test_create_schema_omits_head_without_permission(): void {
		$payload                = $this->create_payload();
		$payload['canEditHead'] = false;
		$fake                   = new Ai_Client_Fake();
		$fake->queue_final_text( $this->generated_page( array( 'head' => null ) ) );

		$result = ( new Ai_Agent( $fake ) )->run( $payload );

		$this->assertSame( array( 'css', 'html', 'summary' ), array_keys( $fake->calls()[0]['options']['jsonSchema']['properties'] ) );
		$this->assertSame( '', $result['snapshot']['customHead'] );
		$this->assertSame( '<main><h1>Apples</h1></main>', $result['snapshot']['html'] );
	}

	/** A dropped Tailwind import is restored without spending a repair turn. */
	public function test_create_restores_a_dropped_tailwind_import(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_final_text( $this->generated_page( array( 'css' => "@theme {\n  --color-ink: #111311;\n}\n" ) ) );

		$result = ( new Ai_Agent( $fake ) )->run( $this->create_payload( 'tailwind', "@import \"tailwindcss\";\n\n@theme {\n  /* ... */\n}\n" ) );

		$this->assertCount( 1, $fake->calls() );
		$this->assertStringStartsWith( "@import \"tailwindcss\";\n\n@theme {", $result['snapshot']['css'] );
	}

	/** An unsafe page is fixed in place by the editing loop, not regenerated. */
	public function test_create_repairs_an_unsafe_page_with_the_edit_loop(): void {
		$events = array();
		$fake   = new Ai_Client_Fake();
		$fake->queue_final_text( $this->generated_page( array( 'html' => '<main><button onclick="buy()">Buy</button></main>' ) ) );
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'r1', '<button onclick="buy()">Buy</button>', '<a href="#order">Buy</a>' ),
				$this->finish_call( 'f1', 'Removed the inline handler.' ),
			)
		);
		$agent = new Ai_Agent(
			$fake,
			array(
				'emit' => static function ( array $event ) use ( &$events ) {
					$events[] = $event;
				},
			)
		);

		$result = $agent->run( $this->create_payload() );

		$this->assertCount( 2, $fake->calls() );
		$repair = $fake->calls()[1];
		$this->assertNotEmpty( $repair['tools'] );
		$this->assertSame( Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT, 'normal' ), $repair['options']['systemInstruction'] );
		$this->assertCount( 1, $repair['messages'] );
		$this->assertStringContainsString( 'Repair request:', $repair['messages'][0]['text'] );
		$this->assertStringContainsString( 'onclick', $repair['messages'][0]['text'] );
		$this->assertSame( '<main><a href="#order">Buy</a></main>', $result['snapshot']['html'] );
		// The person asked for a page, so the page's summary wins over the fix's.
		$this->assertSame( 'Built an apple farm page.', $result['summary'] );
		$phases = array_column( array_filter( $events, static fn( $event ) => 'progress' === $event['event'] ), 'phase' );
		$this->assertSame( array( 'generate', 'repair' ), $phases );
	}

	/** A repair that never fixes the reported problem cannot complete the job. */
	public function test_create_repair_must_fix_every_violation(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_final_text( $this->generated_page( array( 'css' => 'main { color: red;' ) ) );
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'r1', '<h1>Apples</h1>', '<h1>Fresh apples</h1>' ),
				$this->finish_call( 'f1', 'Adjusted the heading.' ),
			)
		);

		try {
			( new Ai_Agent( $fake ) )->run( $this->create_payload() );
			$this->fail( 'Expected the unrepaired page to be rejected.' );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'still fails the server checks after repair', $error->getMessage() );
			$this->assertFalse( $error->is_retryable() );
		}
	}

	/** The repair loop runs under its own small turn cap. */
	public function test_create_repair_uses_its_own_turn_cap(): void {
		$payload                  = $this->create_payload();
		$payload['maxAgentTurns'] = 15;
		$agent                    = new Ai_Agent( new Ai_Client_Fake() );
		$state                    = $agent->create_state( $payload );
		$state['phase']           = 'agent';
		$state['repair']          = true;
		$state['turn']            = Ai_Agent::REPAIR_MAX_TURNS;

		try {
			$agent->advance( $payload, $state );
			$this->fail( 'Expected the repair turn cap to stop the loop.' );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertStringContainsString( 'maximum turns', $error->getMessage() );
		}
	}

	/** Unusable generation responses fall back to the creation tool loop. */
	public function test_create_falls_back_to_the_tool_loop_on_an_unusable_response(): void {
		foreach (
			array(
				'not json'      => 'Here is your page!',
				'missing html'  => $this->generated_page( array( 'html' => null ) ),
				'empty summary' => $this->generated_page( array( 'summary' => ' ' ) ),
			) as $label => $response
		) {
			$fake = new Ai_Client_Fake();
			$fake->queue_final_text( $response );
			$agent = new Ai_Agent( $fake );
			$step  = $agent->advance( $this->create_payload(), $agent->create_state( $this->create_payload() ) );

			$this->assertSame( 'continue', $step['status'], $label );
			$this->assertSame( 'agent', $step['state']['phase'], $label );
			$this->assertTrue( empty( $step['state']['repair'] ), $label );
			$this->assertSame( 'invalid_response', $step['metrics']['workflowFallback'], $label );
			$this->assertSame( '', $step['state']['snapshot']['html'], $label );
		}

		$fake = new Ai_Client_Fake();
		$fake->queue_final_text( 'Here is your page!' );
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'w1',
					'replace_string',
					array(
						'target' => 'html',
						'from'   => '',
						'to'     => '<main>Apples</main>',
					)
				),
				$this->finish_call( 'f1', 'Built the page.' ),
			)
		);
		$result = ( new Ai_Agent( $fake ) )->run( $this->create_payload() );
		$this->assertSame( Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE, 'normal' ), $fake->calls()[1]['options']['systemInstruction'] );
		$this->assertSame( '<main>Apples</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Built the page.', $result['summary'] );
	}

	/** A creation job checkpointed in the tool loop before this release still advances. */
	public function test_create_state_already_in_the_tool_loop_still_advances(): void {
		$payload        = $this->create_payload();
		$agent_fake     = new Ai_Client_Fake();
		$agent          = new Ai_Agent( $agent_fake );
		$state          = $agent->create_state( $payload );
		$state['phase'] = 'agent';
		unset( $state['repair'], $state['generatedSummary'] );
		$agent_fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'w1',
					'replace_string',
					array(
						'target' => 'html',
						'from'   => '',
						'to'     => '<main>Apples</main>',
					)
				),
				$this->finish_call( 'f1', 'Built the page.' ),
			)
		);

		$step = $agent->advance( $payload, $state );

		$this->assertSame( 'completed', $step['status'] );
		$this->assertSame( '<main>Apples</main>', $step['result']['snapshot']['html'] );
		$this->assertNotEmpty( $agent_fake->calls()[0]['tools'] );
	}

	/** Answer HTTP requests by URL, counting them.
	 *
	 * @param array<string,string|int> $pages Body per URL, or an HTTP status code for a failure.
	 * @param int                      $seen  Number of requests made, passed by reference.
	 * @return void
	 */
	private function mock_pages( array $pages, int &$seen ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $pages, &$seen ) {
				unset( $pre, $args );
				++$seen;
				$page = isset( $pages[ $url ] ) ? $pages[ $url ] : 404;
				return array(
					'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
					'body'     => is_string( $page ) ? $page : '',
					'response' => array(
						'code'    => is_string( $page ) ? 200 : (int) $page,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/** Linked pages are fetched before the one generation call and shown to it. */
	public function test_prefetch_feeds_linked_pages_to_generation(): void {
		$seen = 0;
		$this->mock_pages( array( 'https://ovdgolf.com/user_data/miura-top' => '<title>Miura</title><main><h1>TC-102</h1></main>' ), $seen );
		$payload                   = $this->create_payload();
		$payload['prompt']         = '以下のサイトを参考にしてください https://ovdgolf.com/user_data/miura-top と https://ovdgolf.com/missing';
		$payload['referenceFetch'] = true;
		$events                    = array();
		$fake                      = new Ai_Client_Fake();
		$fake->queue_final_text( $this->generated_page() );
		$agent = new Ai_Agent(
			$fake,
			array(
				'emit' => static function ( array $event ) use ( &$events ) {
					$events[] = $event;
				},
			)
		);

		$state = $agent->create_state( $payload );
		$this->assertSame( 'prefetch', $state['phase'] );
		$step = $agent->advance( $payload, $state );
		$this->assertSame( 'generate', $step['state']['phase'] );
		$this->assertSame( 2, $step['metrics']['referenceCount'] );
		$this->assertSame( 1, $step['metrics']['referenceFailures'] );
		$this->assertCount( 0, $fake->calls() );
		$result = $agent->advance( $payload, $step['state'] );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'completed', $result['status'] );
		$this->assertSame( 2, $seen );
		$prompt = $fake->calls()[0]['messages'][0]['text'];
		$this->assertStringContainsString( '<<<reference url="https://ovdgolf.com/user_data/miura-top" status="ok">>>', $prompt );
		$this->assertStringContainsString( "Text:\n# TC-102", $prompt );
		$this->assertStringContainsString( '<<<reference url="https://ovdgolf.com/missing" status="error">>>', $prompt );
		$tool_events = array_values( array_filter( $events, static fn( $event ) => 'fetch_reference' === ( $event['toolName'] ?? '' ) ) );
		$this->assertSame( array( 'tool_start', 'tool_end', 'tool_start', 'tool_end' ), array_column( $tool_events, 'event' ) );
		$this->assertSame( 'ovdgolf.com', $tool_events[0]['target'] );
		$this->assertTrue( $tool_events[1]['ok'] );
		$this->assertFalse( $tool_events[3]['ok'] );
	}

	/** An edit with a link reads the page, then runs the editing loop. */
	public function test_prefetch_precedes_the_editing_loop(): void {
		$seen = 0;
		$this->mock_pages( array( 'https://ovdgolf.com/blog/649/' => '<main><h1>スポンサー協賛のお知らせ</h1></main>' ), $seen );
		$payload                   = $this->payload();
		$payload['prompt']         = '下の記事をどこかに入れて https://ovdgolf.com/blog/649/';
		$payload['referenceFetch'] = true;
		$fake                      = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'スポンサー協賛のお知らせ' ), $this->finish_call( 'f1', 'Added the article.' ) ) );

		$result = ( new Ai_Agent( $fake ) )->run( $payload );
		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $seen );
		$this->assertNotEmpty( $fake->calls()[0]['tools'] );
		$this->assertStringContainsString( '# スポンサー協賛のお知らせ', $fake->calls()[0]['messages'][0]['text'] );
		$this->assertSame( '<main>スポンサー協賛のお知らせ</main>', $result['snapshot']['html'] );
	}

	/** With the setting off, or for jobs created before it existed, nothing is fetched. */
	public function test_prefetch_is_skipped_when_disabled(): void {
		$seen = 0;
		$this->mock_pages( array(), $seen );
		foreach ( array( false, null ) as $setting ) {
			$payload           = $this->create_payload();
			$payload['prompt'] = 'Refer to https://example.com/';
			if ( null !== $setting ) {
				$payload['referenceFetch'] = $setting;
			}
			$this->assertSame( 'generate', ( new Ai_Agent( new Ai_Client_Fake() ) )->create_state( $payload )['phase'] );
		}
		remove_all_filters( 'pre_http_request' );
		$this->assertSame( 0, $seen );
	}

	/** Missing font handlers return the defensive tool error. */
	public function test_font_tool_without_handler_returns_error(): void {
		$agent  = new Ai_Agent( new Ai_Client_Fake() );
		$result = $this->invoke_agent_helper(
			$agent,
			'run_tool_call',
			array( 'list_available_fonts', array(), array(), array(), array( 'html' ) )
		);

		$this->assertFalse( $result['output']['ok'] );
		$this->assertSame( 'Available font tool is not available.', $result['output']['error'] );
		$this->assertFalse( $result['appliedEditOperation'] );
	}

	/** A final edit and finish marker complete in the same model turn. */
	public function test_edit_and_finish_complete_in_one_turn(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_result(
			array(
				'toolCalls' => array(
					$this->finish_call( 'f1', 'Changed the greeting.' ),
					$this->replace_call( 'e1', 'Hello', 'World' ),
				),
				'usage'     => array(
					'inputTokens'  => 20,
					'outputTokens' => 8,
				),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Changed the greeting.', $result['summary'] );
		$this->assertSame( 20, $result['usage']['inputTokens'] );
		$this->assertSame( 8, $result['usage']['outputTokens'] );
		$this->assertCount( 1, $fake->calls() );
	}

	/** Unsupported JavaScript-only requests can complete without fabricating an edit. */
	public function test_finish_without_edit_preserves_snapshot(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->finish_without_edit_call( 'n1', 'JavaScript editing is not supported.' ) ) );
		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>Hello</main>', $result['snapshot']['html'] );
		$this->assertSame( 'JavaScript editing is not supported.', $result['summary'] );
		$this->assertCount( 1, $fake->calls() );
	}

	/** The finish_without_edit tool cannot hide a prior snapshot mutation. */
	public function test_finish_without_edit_rejects_changed_snapshot(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'World' ) ) );
		$fake->queue_tool_calls( array( $this->finish_without_edit_call( 'n1', 'No change.' ) ) );
		$fake->queue_tool_calls( array( $this->finish_call( 'f1', 'Changed the greeting.' ) ) );
		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$responses = $fake->calls()[2]['messages'][4]['toolResponses'];
		$this->assertFalse( $responses[0]['output']['ok'] );
		$this->assertStringContainsString( 'snapshot to remain unchanged', $responses[0]['output']['error']['message'] );
	}

	/** Finalization revalidates unsafe content even if a checkpoint is tampered with. */
	public function test_completed_snapshot_is_revalidated_against_original_input(): void {
		$fake                          = new Ai_Client_Fake();
		$agent                         = new Ai_Agent( $fake );
		$input                         = $this->payload();
		$state                         = $agent->create_state( $input );
		$state['snapshot']['html']     = '<main>Hello</main><script>alert(1)</script>';
		$state['appliedEditOperation'] = true;
		$state['finishReady']          = true;
		$fake->queue_tool_calls( array( $this->finish_call( 'f1', 'Unsafe result.' ) ) );

		$this->expectException( Ai_Agent_Error::class );
		$this->expectExceptionMessage( 'server safety policy' );
		$agent->advance( $input, $state );
	}

	/** A corrupted worker state cannot bypass the creator's head-edit permission. */
	public function test_completed_snapshot_rejects_unauthorized_head_change(): void {
		$agent                  = new Ai_Agent( new Ai_Client_Fake() );
		$payload                = $this->payload();
		$payload['canEditHead'] = false;
		$snapshot               = $this->invoke_agent_helper( $agent, 'initial_snapshot', array( $payload ) );
		$snapshot['customHead'] = '<meta name="robots" content="noindex">';

		$this->expectException( Ai_Agent_Error::class );
		$this->expectExceptionMessage( 'custom head without permission' );
		$this->invoke_agent_helper( $agent, 'completed_step', array( $payload, array(), $snapshot, 'Changed head.', array(), 'agent', 1, 0.0, 0.0 ) );
	}

	/** A finish-only turn is accepted after a prior successful edit. */
	public function test_delayed_finish_completes_after_prior_edit(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_result(
			array(
				'toolCalls' => array( $this->replace_call( 'e1', 'Hello', 'World' ) ),
				'usage'     => array(
					'inputTokens'  => 10,
					'outputTokens' => 3,
				),
			)
		);
		$fake->queue_result(
			array(
				'toolCalls' => array( $this->finish_call( 'f1', 'Changed the greeting.' ) ),
				'usage'     => array(
					'inputTokens'  => 20,
					'outputTokens' => 4,
				),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Changed the greeting.', $result['summary'] );
		$this->assertSame( 30, $result['usage']['inputTokens'] );
		$this->assertSame( 7, $result['usage']['outputTokens'] );
		$this->assertCount( 2, $fake->calls() );
	}

	/** Successful read-only turns preserve readiness for a delayed finish. */
	public function test_read_only_turn_preserves_finish_readiness(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'World' ) ) );
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					's1',
					'search_text',
					array(
						'query'  => 'World',
						'target' => 'html',
					)
				),
				Ai_Message::tool_call( 'r1', 'read_document', array( 'target' => 'html' ) ),
			)
		);
		$fake->queue_tool_calls( array( $this->finish_call( 'f1', 'Verified the greeting change.' ) ) );

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( 'Verified the greeting change.', $result['summary'] );
		$this->assertCount( 3, $fake->calls() );
	}

	/** Multiple successful edits may share a response with one finish marker. */
	public function test_parallel_edits_and_finish_complete_in_one_turn(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e1', 'Hello', 'World' ),
				$this->replace_call( 'e2', '<main>', '<section>' ),
				$this->replace_call( 'e3', '</main>', '</section>' ),
				$this->finish_call( 'f1', 'Updated the greeting and wrapper.' ),
			)
		);
		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<section>World</section>', $result['snapshot']['html'] );
		$this->assertCount( 1, $fake->calls() );
	}

	/** The observed blank-cursor/no-selection sequence completes without looping. */
	public function test_blank_cursor_and_none_selection_reproduction_completes(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'r1',
					'read_document',
					array(
						'target' => 'css',
						'cursor' => '',
					)
				),
			)
		);
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'e1',
					'replace_string',
					array(
						'target'      => 'html',
						'from'        => '',
						'to'          => '<section class="testimonials">Customer voices</section>',
						'selectionId' => 'none',
					)
				),
				$this->finish_call( 'f1', 'Added a testimonials section.' ),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload( '' ) );
		$this->assertStringContainsString( 'testimonials', $result['snapshot']['html'] );
		$this->assertCount( 2, $fake->calls() );
	}

	/** Any tool error in the turn invalidates finish and is visible next turn. */
	public function test_tool_error_invalidates_finish_until_retry(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'bad', 'Missing', 'X' ),
				$this->finish_call( 'f1', 'Not done.' ),
			)
		);
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'good', 'Hello', 'World' ),
				$this->finish_call( 'f2', 'Recovered and edited.' ),
			)
		);

		$result    = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$responses = $fake->calls()[1]['messages'][2]['toolResponses'];
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertFalse( $responses[0]['output']['ok'] );
		$this->assertFalse( $responses[1]['output']['ok'] );
		$this->assertStringContainsString( 'another tool failed', $responses[1]['output']['error']['message'] );
	}

	/** An error after a successful edit blocks finish until another edit succeeds. */
	public function test_later_error_clears_finish_readiness(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'e1', 'Hello', 'World' ) ) );
		$fake->queue_tool_calls( array( $this->replace_call( 'bad', 'Missing', 'X' ) ) );
		$fake->queue_tool_calls( array( $this->finish_call( 'f1', 'Not yet.' ) ) );
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e2', 'World', 'Done' ),
				$this->finish_call( 'f2', 'Recovered and finished.' ),
			)
		);

		$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$this->assertSame( '<main>Done</main>', $result['snapshot']['html'] );
		$this->assertSame( 'Recovered and finished.', $result['summary'] );
		$this->assertCount( 4, $fake->calls() );
		$finish_response = $fake->calls()[3]['messages'][6]['toolResponses'][0]['output'];
		$this->assertStringContainsString( 'no unresolved tool errors', $finish_response['error']['message'] );
	}

	/** A mixed success/error batch cannot finish on its partial edit. */
	public function test_mixed_edit_results_invalidate_same_turn_finish(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'good', 'Hello', 'World' ),
				$this->replace_call( 'bad', 'Missing', 'X' ),
				$this->finish_call( 'f1', 'Partial.' ),
			)
		);
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e2', 'World', 'Done' ),
				$this->finish_call( 'f2', 'Completed after retry.' ),
			)
		);

		$result    = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$responses = $fake->calls()[1]['messages'][2]['toolResponses'];
		$this->assertSame( '<main>Done</main>', $result['snapshot']['html'] );
		$this->assertFalse( $responses[1]['output']['ok'] );
		$this->assertStringContainsString( 'another tool failed', $responses[2]['output']['error']['message'] );
	}

	/** Finish before any successful edit is rejected and can be retried. */
	public function test_finish_only_is_rejected(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->finish_call( 'f1', 'Too early.' ) ) );
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e1', 'Hello', 'World' ),
				$this->finish_call( 'f2', 'Done.' ),
			)
		);
		$result   = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$response = $fake->calls()[1]['messages'][2]['toolResponses'][0]['output'];
		$this->assertSame( 'Done.', $result['summary'] );
		$this->assertStringContainsString( 'no unresolved tool errors', $response['error']['message'] );
	}

	/** Duplicate finish calls are rejected rather than choosing one summary. */
	public function test_duplicate_finish_calls_are_rejected(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'e1', 'Hello', 'World' ),
				$this->finish_call( 'f1', 'One.' ),
				$this->finish_call( 'f2', 'Two.' ),
			)
		);
		$fake->queue_tool_calls(
			array(
				$this->finish_call( 'f3', 'Valid finish.' ),
			)
		);
		$result    = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$responses = $fake->calls()[1]['messages'][2]['toolResponses'];
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertCount( 3, $responses );
		$this->assertStringContainsString( 'only once', $responses[1]['output']['error']['message'] );
		$this->assertStringContainsString( 'only once', $responses[2]['output']['error']['message'] );
	}

	/** Empty and oversized summaries are rejected by the runtime guard. */
	public function test_invalid_finish_summaries_are_rejected(): void {
		foreach ( array( '', str_repeat( 'a', 1001 ), 123 ) as $invalid_summary ) {
			$fake = new Ai_Client_Fake();
			$fake->queue_tool_calls(
				array(
					$this->replace_call( 'e1', 'Hello', 'World' ),
					$this->finish_call( 'f1', $invalid_summary ),
				)
			);
			$fake->queue_tool_calls(
				array(
					$this->finish_call( 'f2', 'Valid.' ),
				)
			);
			$result = ( new Ai_Agent( $fake ) )->run( $this->payload() );
			$this->assertSame( 'Valid.', $result['summary'] );
			$this->assertFalse( $fake->calls()[1]['messages'][2]['toolResponses'][1]['output']['ok'] );
		}
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

	/** No-match candidates let the model recover without separate read tools. */
	public function test_no_match_details_support_direct_replacement_retry(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'bad', '<main>Hallo</main>', '<main>World</main>' ) ) );
		$fake->queue_tool_calls(
			array(
				$this->replace_call( 'good', '<main>Hello</main>', '<main>World</main>' ),
				$this->finish_call( 'finish', 'Corrected the greeting.' ),
			)
		);

		$result   = ( new Ai_Agent( $fake ) )->run( $this->payload() );
		$response = $fake->calls()[1]['messages'][2]['toolResponses'][0]['output'];
		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertTrue( $response['error']['retryable'] );
		$this->assertSame( 'replace_no_match', $response['error']['details']['code'] );
		$this->assertSame( '<main>Hello</main>', $response['error']['details']['candidates'][0]['content'] );
		$this->assertCount( 2, $fake->calls() );
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
					$events[] = $event;
				},
			)
		);
		$agent->run( $this->payload() );

		$names = array_column( $events, 'event' );
		$this->assertContains( 'progress', $names );
		$this->assertContains( 'tool_start', $names );
		$this->assertContains( 'tool_end', $names );
	}

	/**
	 * The UI builds its own wording, so events carry structured fields instead
	 * of internal phrasing.
	 */
	public function test_events_carry_ui_fields(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		$fake->queue_final_text( 'Editing complete.' );
		$fake->queue_final_text( '{"summary":"ok"}' );

		$events = array();
		$agent  = new Ai_Agent(
			$fake,
			array(
				'emit' => static function ( array $event ) use ( &$events ) {
					$events[] = $event;
				},
			)
		);
		$agent->run( $this->payload() );

		$progress = array_values(
			array_filter(
				$events,
				static function ( array $event ) {
					return 'progress' === $event['event'] && isset( $event['turn'] );
				}
			)
		);
		$this->assertNotEmpty( $progress );
		$this->assertSame( '', $progress[0]['message'] );
		$this->assertSame( 1, $progress[0]['turn'] );
		$this->assertSame( Ai_Agent::MAX_AGENT_TURNS, $progress[0]['maxTurns'] );

		$starts = array_values(
			array_filter(
				$events,
				static function ( array $event ) {
					return 'tool_start' === $event['event'];
				}
			)
		);
		$this->assertSame( 'html', $starts[0]['target'] );

		$ends = array_values(
			array_filter(
				$events,
				static function ( array $event ) {
					return 'tool_end' === $event['event'];
				}
			)
		);
		$this->assertTrue( $ends[0]['ok'] );
		$this->assertSame( 'html', $ends[0]['target'] );
	}

	/** Configured job limits drive the UI progress event and preserve the default for legacy jobs. */
	public function test_configured_max_turns_are_used_for_progress_events(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		$fake->queue_final_text( '{"summary":"ok"}' );
		$events                   = array();
		$payload                  = $this->payload();
		$payload['maxAgentTurns'] = 20;

		( new Ai_Agent(
			$fake,
			array(
				'emit' => static function ( array $event ) use ( &$events ) {
					$events[] = $event;
				},
			)
		) )->run( $payload );

		$this->assertSame( 20, $events[0]['maxTurns'] );
		$this->assertSame( Ai_Agent::MAX_AGENT_TURNS, Ai_Agent::resolve_max_agent_turns( $this->payload() ) );
	}

	/** A configured limit also controls when the edit loop enters finalization. */
	public function test_configured_max_turns_limit_the_agent_loop(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls( array( $this->replace_call( 'c1', 'Hello', 'World' ) ) );
		for ( $i = 1; $i < 10; $i++ ) {
			$fake->queue_tool_calls(
				array(
					Ai_Message::tool_call( 's' . $i, 'search_text', array( 'query' => 'World' ) ),
				)
			);
		}
		$fake->queue_final_text( '{"summary":"Finalized after configured limit."}' );
		$payload                  = $this->payload();
		$payload['maxAgentTurns'] = 10;

		$result = ( new Ai_Agent( $fake ) )->run( $payload );

		$this->assertSame( 'Finalized after configured limit.', $result['summary'] );
		$this->assertCount( 11, $fake->calls() );
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

	/**
	 * Hitting the limit without an edit is tagged so the worker can show a
	 * translated, actionable message instead of the internal wording.
	 */
	public function test_turn_limit_without_edit_is_tagged_max_turns(): void {
		$fake = new Ai_Client_Fake();
		for ( $i = 0; $i <= Ai_Agent::MAX_AGENT_TURNS; $i++ ) {
			$fake->queue_tool_calls(
				array(
					Ai_Message::tool_call( 's' . $i, 'search_text', array( 'query' => 'Hello' ) ),
				)
			);
		}

		try {
			( new Ai_Agent( $fake ) )->run( $this->payload() );
			$this->fail( 'Expected the turn limit to abort the run.' );
		} catch ( Ai_Agent_Error $error ) {
			$this->assertSame( 'max_turns', $error->get_code_key() );
			$this->assertTrue( $error->is_retryable() );
		}
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

	/** Font catalogs remain available after larger observations consume the budget. */
	public function test_model_context_preserves_font_catalog_observations(): void {
		$catalog  = array(
			'ok'         => true,
			'registered' => array(
				array(
					'name'     => 'Captured Font',
					'cssValue' => '"Captured Font", sans-serif',
				),
			),
		);
		$messages = array(
			Ai_Message::tool(
				array(
					Ai_Message::tool_response( 'font-1', 'list_available_fonts', $catalog ),
				)
			),
			Ai_Message::tool(
				array(
					Ai_Message::tool_response(
						'read-1',
						'read_document',
						array(
							'ok'      => true,
							'content' => str_repeat( 'x', Ai_Agent::OBSERVATION_CONTEXT_CHARS ),
						)
					),
				)
			),
		);

		$projected = $this->invoke_agent_helper( new Ai_Agent( new Ai_Client_Fake() ), 'build_model_context', array( $messages ) );
		$this->assertSame( '"Captured Font", sans-serif', $projected[0]['toolResponses'][0]['output']['registered'][0]['cssValue'] );
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

	/** History metadata bypasses an exhausted source budget while source reads do not. */
	public function test_history_metadata_does_not_consume_source_read_budget(): void {
		$fake = new Ai_Client_Fake();
		$fake->queue_tool_calls(
			array(
				Ai_Message::tool_call(
					'r1',
					'read_document',
					array(
						'target'   => 'html',
						'maxChars' => 12000,
					)
				),
				Ai_Message::tool_call( 'h1', 'get_ai_edit', array( 'versionId' => 'version-1' ) ),
				Ai_Message::tool_call(
					'h2',
					'get_ai_edit',
					array(
						'versionId' => 'version-1',
						'snapshot'  => 'after',
						'target'    => 'html',
					)
				),
				$this->replace_call( 'e1', 'Hello', 'World' ),
			)
		);
		$agent = new Ai_Agent(
			$fake,
			array(
				'historyTool' => static function ( string $name, array $args ): array {
					unset( $name );
					if ( isset( $args['snapshot'], $args['target'] ) ) {
						return array(
							'ok'      => true,
							'content' => 'retained source',
						);
					}
					return array(
						'ok'        => true,
						'versionId' => $args['versionId'],
					);
				},
			)
		);

		$payload   = $this->payload( '<main>Hello' . str_repeat( 'x', 13000 ) . '</main>' );
		$step      = $agent->advance( $payload, $agent->create_state( $payload ) );
		$responses = $step['state']['messages'][2]['toolResponses'];
		$this->assertTrue( $responses[1]['output']['ok'] );
		$this->assertSame( 'version-1', $responses[1]['output']['versionId'] );
		$this->assertFalse( $responses[2]['output']['ok'] );
		$this->assertStringContainsString( 'read_budget_exhausted', $responses[2]['output']['error']['message'] );
	}

	/** Replacement diagnostics count against the bounded observation budget. */
	public function test_replace_error_details_count_toward_observation_budget(): void {
		$agent     = new Ai_Agent( new Ai_Client_Fake() );
		$candidate = str_repeat( 'x', 1200 );
		$messages  = array(
			Ai_Message::tool(
				array(
					Ai_Message::tool_response(
						'call-1',
						'replace_string',
						array(
							'ok'    => false,
							'error' => array(
								'details' => array(
									'candidates' => array( array( 'content' => $candidate ) ),
								),
							),
						)
					),
				)
			),
		);
		$projected = $this->invoke_agent_helper( $agent, 'build_model_context', array( $messages ) );
		$property  = new ReflectionProperty( Ai_Agent::class, 'model_context_stats' );
		$property->setAccessible( true );
		$stats = $property->getValue( $agent );

		$this->assertSame( $messages, $projected );
		$this->assertGreaterThanOrEqual( 1200, $stats['observationCharacters'] );
		$this->assertSame( $stats['observationCharacters'], $stats['sentObservationCharacters'] );
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
			Ai_Message::tool(
				array(
					Ai_Message::tool_response(
						'call-1',
						'replace_string',
						array(
							'ok'    => false,
							'error' => array(
								'details' => array(
									'candidates' => array( array( 'content' => $secret ) ),
								),
							),
						)
					),
				)
			),
		);
		$structure = $this->invoke_agent_helper( $agent, 'build_debug_message_structure', array( $messages ) );

		$this->assertSame( strlen( $secret ), $structure['messages'][0]['toolCalls'][0]['stringFields']['from']['bytes'] );
		$this->assertSame( 1, $structure['toolTotals']['replace_string']['callCount'] );
		$this->assertSame( 1, $structure['toolTotals']['replace_string']['responseCount'] );
		$this->assertSame( strlen( $secret ), $structure['messages'][1]['toolResponses'][0]['stringFields']['error.details.candidates.0.content']['bytes'] );
		$this->assertStringNotContainsString( $secret, wp_json_encode( $structure ) );
	}

	/** Footprint diagnostics expose sizes without retaining source snippets. */
	public function test_debug_edit_footprint_has_sizes_only(): void {
		$agent  = new Ai_Agent( new Ai_Client_Fake() );
		$before = 'background: var(--blue);';
		$after  = 'background: #16a34a;';
		$stats  = $this->invoke_agent_helper(
			$agent,
			'build_debug_edit_footprint_stats',
			array(
				array(
					'recentEditContext' => array(
						array(
							'editFootprint' => array(
								'validation' => 'snapshot_hash',
								'changes'    => array(
									array(
										'before' => $before,
										'after'  => $after,
									),
								),
							),
						),
					),
				),
			)
		);

		$this->assertTrue( $stats['present'] );
		$this->assertSame( 1, $stats['changeCount'] );
		$this->assertSame( mb_strlen( $before . $after ), $stats['contentCharacters'] );
		$this->assertSame( strlen( $before . $after ), $stats['contentBytes'] );
		$this->assertStringNotContainsString( $before, wp_json_encode( $stats ) );
		$this->assertStringNotContainsString( $after, wp_json_encode( $stats ) );
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
					'text'              => 'visible model text',
					'thoughtSignature'  => 'provider-secret',
					'encrypted_content' => 'encrypted-provider-secret',
					'cursor'            => 'opaque-cursor',
					'nextCursor'        => 'opaque-next-cursor',
					'authorization'     => 'Bearer transport-secret',
				),
				'full',
			)
		);

		$this->assertSame( 'visible model text', $trace['text'] );
		$this->assertTrue( $trace['thoughtSignature']['opaque'] );
		$this->assertTrue( $trace['encrypted_content']['opaque'] );
		$this->assertTrue( $trace['cursor']['opaque'] );
		$this->assertTrue( $trace['nextCursor']['opaque'] );
		$this->assertTrue( $trace['authorization']['opaque'] );
		$this->assertStringNotContainsString( 'provider-secret', wp_json_encode( $trace ) );
		$this->assertStringNotContainsString( 'encrypted-provider-secret', wp_json_encode( $trace ) );
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

	/** Checkpoints survive JSON storage and each advance performs one model call. */
	public function test_stepwise_checkpoint_round_trip_and_single_call_boundary(): void {
		$provider_data = array(
			'openai' => array(
				'outputItems' => array(
					array(
						'type'              => 'reasoning',
						'id'                => 'rs_checkpoint',
						'encrypted_content' => 'opaque-checkpoint',
					),
					array(
						'type'      => 'function_call',
						'call_id'   => 'r1',
						'name'      => 'replace_string',
						'arguments' => '{"target":"html","from":"Hello","to":"World"}',
					),
				),
			),
		);
		$fake          = new Ai_Client_Fake(
			array(
				array(
					'toolCalls'    => array( $this->replace_call( 'r1', 'Hello', 'World' ) ),
					'providerData' => $provider_data,
				),
				array( 'text' => 'Done' ),
				array( 'text' => '{"summary":"Changed the greeting."}' ),
			)
		);
		$observed      = array();
		$agent         = new Ai_Agent(
			$fake,
			array(
				'observeStep' => static function ( array $metrics ) use ( &$observed ) {
					$observed[] = $metrics;
				},
			)
		);
		$state         = $agent->create_state( $this->payload() );

		$first = $agent->advance( $this->payload(), $state );
		$this->assertSame( 'continue', $first['status'] );
		$this->assertCount( 1, $fake->calls() );
		$state = json_decode( wp_json_encode( $first['state'] ), true );
		$this->assertSame( $provider_data, $state['messages'][1]['providerData'] );

		$second = $agent->advance( $this->payload(), $state );
		$this->assertSame( 'continue', $second['status'] );
		$this->assertSame( 'finalization', $second['state']['phase'] );
		$this->assertCount( 2, $fake->calls() );
		$this->assertSame( $provider_data, $fake->calls()[1]['messages'][1]['providerData'] );
		$third = $agent->advance( $this->payload(), json_decode( wp_json_encode( $second['state'] ), true ) );
		$this->assertSame( 'completed', $third['status'] );
		$this->assertSame( '<main>World</main>', $third['result']['snapshot']['html'] );
		$this->assertSame( 'Changed the greeting.', $third['result']['summary'] );
		$this->assertCount( 3, $fake->calls() );
		$this->assertCount( 3, $observed );
		$this->assertArrayHasKey( 'providerMs', $observed[0] );
		$this->assertArrayHasKey( 'toolMs', $observed[0] );
		$this->assertStringNotContainsString( 'Hello', wp_json_encode( $observed ) );
		$this->assertStringNotContainsString( 'World', wp_json_encode( $observed ) );
	}
}
