<?php
/**
 * Unit tests for the AI client contract: messages, fake client, schema mapping.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Message;
use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Client_Exception;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/**
 * Verify the client abstraction the agent loop depends on.
 */
class Test_Kayzart_Ai_Client extends WP_UnitTestCase {

	/**
	 * Message helpers produce the documented normalized shapes.
	 */
	public function test_message_helpers_shapes(): void {
		$this->assertSame(
			array(
				'role'      => 'assistant',
				'text'      => 'ok',
				'toolCalls' => array(
					array(
						'id'   => 'c1',
						'name' => 'replace_string',
						'args' => array( 'target' => 'html' ),
					),
				),
			),
			Ai_Message::assistant(
				'ok',
				array( Ai_Message::tool_call( 'c1', 'replace_string', array( 'target' => 'html' ) ) )
			)
		);

		$provider_data = array(
			'openai' => array(
				'outputItems' => array(
					array(
						'type' => 'reasoning',
						'id'   => 'rs_1',
					),
				),
			),
		);
		$this->assertSame(
			$provider_data,
			Ai_Message::assistant( '', array(), $provider_data )['providerData']
		);
		$this->assertSame(
			array(
				'role'          => 'tool',
				'toolResponses' => array(
					array(
						'callId' => 'c1',
						'name'   => 'replace_string',
						'output' => array( 'ok' => true ),
					),
				),
			),
			Ai_Message::tool(
				array( Ai_Message::tool_response( 'c1', 'replace_string', array( 'ok' => true ) ) )
			)
		);
	}

	/**
	 * The fake returns queued results in order and records calls.
	 */
	public function test_fake_returns_queued_results_in_order(): void {
		$fake          = new Ai_Client_Fake();
		$provider_data = array( 'openai' => array( 'outputItems' => array( array( 'type' => 'reasoning' ) ) ) );
		$fake->queue_result(
			array(
				'toolCalls'    => array( Ai_Message::tool_call( 'c1', 'search_text', array( 'query' => 'x' ) ) ),
				'providerData' => $provider_data,
			)
		);
		$fake->queue_final_text( 'done' );
		$first = $fake->generate( array(), array( 'tool' ), array( 'systemInstruction' => 'sys' ) );
		$this->assertCount( 1, $first['toolCalls'] );
		$this->assertSame( 'search_text', $first['toolCalls'][0]['name'] );
		$this->assertSame( '', $first['text'] );
		$this->assertSame( $provider_data, $first['providerData'] );

		$second = $fake->generate( array(), array() );
		$this->assertSame( 'done', $second['text'] );
		$this->assertSame( array(), $second['toolCalls'] );
		$this->assertArrayNotHasKey( 'providerData', $second );

		$calls = $fake->calls();
		$this->assertCount( 2, $calls );
		$this->assertSame( 'sys', $calls[0]['options']['systemInstruction'] );
	}

	/**
	 * The fake throws when the queue is exhausted.
	 */
	public function test_fake_throws_when_empty(): void {
		$this->expectException( Ai_Client_Exception::class );
		( new Ai_Client_Fake() )->generate( array(), array() );
	}

	/**
	 * The fake reports the configured availability.
	 */
	public function test_fake_availability_toggle(): void {
		$fake = new Ai_Client_Fake();
		$this->assertTrue( $fake->is_available() );
		$fake->set_available( false );
		$this->assertFalse( $fake->is_available() );
	}
}
