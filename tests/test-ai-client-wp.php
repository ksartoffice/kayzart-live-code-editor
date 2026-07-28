<?php
/**
 * WordPress AI Client DTO conversion tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Client_Wp;
use KayzArt\Ai_Message;

/**
 * Verify provider-compatible message splitting at the WordPress SDK seam.
 */
class Test_Kayzart_Ai_Client_Wp extends WP_UnitTestCase {

	/**
	 * Skip only when a developer still has a pre-7.0 local test core installed.
	 */
	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( WordPress\AiClient\Messages\DTO\MessagePart::class ) ) {
			$this->markTestSkipped( 'WordPress 7.0 AI Client DTOs are not installed in this test environment.' );
		}
	}

	/**
	 * Convert normalized messages through the adapter's private SDK seam.
	 *
	 * @param array $messages Normalized messages.
	 * @return array SDK Message objects.
	 */
	private function convert_messages( array $messages ): array {
		$method = new ReflectionMethod( Ai_Client_Wp::class, 'to_sdk_messages' );
		$method->setAccessible( true );

		return $method->invoke( new Ai_Client_Wp(), $messages );
	}

	/**
	 * A text-only message remains one SDK message with one text part.
	 */
	public function test_text_message_remains_single_part(): void {
		$messages = $this->convert_messages( array( Ai_Message::assistant( 'Working.' ) ) );

		$this->assertCount( 1, $messages );
		$this->assertTrue( $messages[0]->getRole()->isModel() );
		$this->assertCount( 1, $messages[0]->getParts() );
		$this->assertSame( 'Working.', $messages[0]->getParts()[0]->getText() );
	}

	/**
	 * Text and parallel calls become ordered, single-part model messages.
	 */
	public function test_parallel_function_calls_are_split_and_preserved(): void {
		$messages = $this->convert_messages(
			array(
				Ai_Message::assistant(
					'Inspecting both sections.',
					array(
						array(
							'id'               => 'call-1',
							'name'             => 'search_text',
							'args'             => array( 'query' => 'faq' ),
							'thoughtSignature' => 'signature-1',
						),
						Ai_Message::tool_call( 'call-2', 'search_text', array( 'query' => 'features' ) ),
					)
				),
			)
		);

		$this->assertCount( 3, $messages );
		foreach ( $messages as $message ) {
			$this->assertTrue( $message->getRole()->isModel() );
			$this->assertCount( 1, $message->getParts() );
		}
		$this->assertSame( 'Inspecting both sections.', $messages[0]->getParts()[0]->getText() );

		$first_call = $messages[1]->getParts()[0];
		$this->assertTrue( $first_call->getType()->isFunctionCall() );
		$this->assertSame( 'call-1', $first_call->getFunctionCall()->getId() );
		$this->assertSame( 'search_text', $first_call->getFunctionCall()->getName() );
		$this->assertSame( array( 'query' => 'faq' ), $first_call->getFunctionCall()->getArgs() );
		$this->assertSame( 'signature-1', $first_call->getThoughtSignature() );

		$second_call = $messages[2]->getParts()[0]->getFunctionCall();
		$this->assertSame( 'call-2', $second_call->getId() );
		$this->assertSame( array( 'query' => 'features' ), $second_call->getArgs() );
	}

	/**
	 * Parallel responses become ordered, single-part user messages.
	 */
	public function test_parallel_function_responses_are_split_and_preserved(): void {
		$messages = $this->convert_messages(
			array(
				Ai_Message::tool(
					array(
						Ai_Message::tool_response( 'call-1', 'search_text', array( 'count' => 1 ) ),
						Ai_Message::tool_response( 'call-2', 'search_text', array( 'count' => 2 ) ),
					)
				),
			)
		);

		$this->assertCount( 2, $messages );
		foreach ( $messages as $message ) {
			$this->assertTrue( $message->getRole()->isUser() );
			$this->assertCount( 1, $message->getParts() );
			$this->assertTrue( $message->getParts()[0]->getType()->isFunctionResponse() );
		}
		$this->assertSame( 'call-1', $messages[0]->getParts()[0]->getFunctionResponse()->getId() );
		$this->assertSame( array( 'count' => 1 ), $messages[0]->getParts()[0]->getFunctionResponse()->getResponse() );
		$this->assertSame( 'call-2', $messages[1]->getParts()[0]->getFunctionResponse()->getId() );
		$this->assertSame( array( 'count' => 2 ), $messages[1]->getParts()[0]->getFunctionResponse()->getResponse() );
	}

	/**
	 * Empty normalized messages do not create invalid empty SDK messages.
	 */
	public function test_empty_messages_are_omitted(): void {
		$messages = $this->convert_messages(
			array(
				Ai_Message::assistant( '' ),
				Ai_Message::tool( array() ),
			)
		);

		$this->assertSame( array(), $messages );
	}
}
