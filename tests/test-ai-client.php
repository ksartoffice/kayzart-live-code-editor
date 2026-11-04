<?php
/**
 * Unit tests for the AI client contract: messages, fake client, schema mapping.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Message;
use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Client_Exception;

require_once __DIR__ . '/doubles/class-kayzart-ai-client-fake.php';

/**
 * Verify the client abstraction the agent loop depends on.
 */
class Test_Kayzart_Ai_Client extends WP_UnitTestCase {

	/**
	 * Known billing failures have actionable, safe messages and cannot be retried.
	 *
	 * @dataProvider billing_failures
	 * @param string $message Provider message.
	 * @param string $code Structured provider code.
	 * @param string $expected Expected public message.
	 */
	public function test_billing_failures_are_actionable( string $message, string $code, string $expected ): void {
		$error = new Ai_Client_Exception( $message, true, $code );
		$this->assertFalse( $error->is_retryable() );
		$this->assertSame( $expected, $error->get_user_message() );
		$this->assertSame( $message, $error->getMessage() );
	}

	/** Provider error formats from direct OpenAI and WordPress Connectors. */
	public static function billing_failures(): array {
		$credits = 'The AI provider has no credits remaining. Check the provider billing settings.';
		$quota   = 'The AI provider billing or usage limit has been reached. Check the provider billing settings and usage limits.';
		return array(
			'direct credit code'     => array( 'Provider detail', 'credit_balance_exhausted', $credits ),
			'connector credit text'  => array( 'Too Many Requests (429) - You have no credits remaining. Add credits to continue using the API.', 'prompt_client_error', $credits ),
			'low credit text'        => array( 'Your credit balance is too low to access the API.', '', $credits ),
			'legacy quota code'      => array( 'Provider detail', 'insufficient_quota', $quota ),
			'connector quota text'   => array( 'You exceeded your current quota, please check your plan and billing details.', '', $quota ),
			'organization spending' => array( 'Provider detail', 'organization_spend_limit_exceeded', $quota ),
			'project spending'      => array( 'Provider detail', 'project_spend_limit_exceeded', $quota ),
			'organization usage'    => array( 'Provider detail', 'organization_usage_limit_exceeded', $quota ),
		);
	}

	/** A temporary rate limit keeps retry behavior and hides raw details. */
	public function test_rate_limit_remains_retryable_and_generic(): void {
		$error = new Ai_Client_Exception( 'Too Many Requests (429) - Rate limit reached for private-project.', true, 'rate_limit_exceeded' );
		$this->assertTrue( $error->is_retryable() );
		$this->assertSame( 'The AI provider request failed.', $error->get_user_message() );
	}

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
