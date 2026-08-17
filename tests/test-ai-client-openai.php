<?php
/**
 * Direct OpenAI Responses API adapter tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Client_Exception;
use KayzArt\Ai_Client_Factory;
use KayzArt\Ai_Client_OpenAI;
use KayzArt\Ai_Message;

/** Verify request mapping, response normalization, and retry policy. */
class Test_Kayzart_Ai_Client_OpenAI extends WP_UnitTestCase {
	/** Remove credentials and HTTP interception after each test. */
	protected function tearDown(): void {
		delete_option( 'kayzart_openai_api_key' );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'kayzart_ai_feature_enabled' );
		remove_all_filters( 'kayzart_ai_sdk_present' );
		remove_all_filters( 'kayzart_ai_provider_configured' );
		remove_all_filters( 'kayzart_ai_scheduler_present' );
		remove_all_filters( 'kayzart_ai_mbstring_present' );
		remove_all_filters( 'kayzart_ai_dom_present' );
		parent::tearDown();
	}

	/** WordPress Connectors win when both supported backends are ready. */
	public function test_factory_prefers_wordpress_connector_over_direct_key(): void {
		global $wp_version;

		$original_wp_version = $wp_version;
		update_option( 'kayzart_openai_api_key', 'sk-test-secret' );
		add_filter( 'kayzart_ai_feature_enabled', '__return_true' );
		add_filter( 'kayzart_ai_sdk_present', '__return_true' );
		add_filter( 'kayzart_ai_provider_configured', '__return_true' );
		add_filter( 'kayzart_ai_scheduler_present', '__return_true' );
		add_filter( 'kayzart_ai_mbstring_present', '__return_true' );
		add_filter( 'kayzart_ai_dom_present', '__return_true' );

		try {
			$wp_version = '7.0';
			$this->assertSame( Ai_Client_Factory::WORDPRESS, Ai_Client_Factory::resolve_backend() );
		} finally {
			$wp_version = $original_wp_version;
		}
	}

	/** A job remains on the backend captured when the REST request was created. */
	public function test_factory_honors_the_backend_captured_by_a_job(): void {
		$client = Ai_Client_Factory::for_job(
			array( 'payload_json' => wp_json_encode( array( 'providerMode' => Ai_Client_Factory::OPENAI ) ) )
		);

		$this->assertInstanceOf( Ai_Client_OpenAI::class, $client );
	}

	/** Requests and responses are mapped without changing provider semantics. */
	public function test_generate_maps_responses_request_and_normalizes_result(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-secret' );
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured ) {
				unset( $preempt );
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'model'  => 'gpt-5.6-luna-2026-08-01',
							'output' => array(
								array(
									'type'      => 'function_call',
									'call_id'   => 'call-1',
									'name'      => 'replace_string',
									'arguments' => '{"target":"html","from":"a","to":"b"}',
								),
							),
							'usage'  => array(
								'input_tokens'          => 20,
								'input_tokens_details'  => array( 'cached_tokens' => 5 ),
								'output_tokens'         => 8,
								'output_tokens_details' => array( 'reasoning_tokens' => 3 ),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = ( new Ai_Client_OpenAI() )->generate(
			array(
				Ai_Message::user( 'change it' ),
				Ai_Message::assistant( '', array( Ai_Message::tool_call( 'old-call', 'read_document', array( 'target' => 'html' ) ) ) ),
				Ai_Message::tool( array( Ai_Message::tool_response( 'old-call', 'read_document', array( 'content' => 'a' ) ) ) ),
			),
			array(
				array(
					'name'        => 'replace_string',
					'description' => 'Replace text.',
					'parameters'  => array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					),
				),
			),
			array( 'systemInstruction' => 'system' )
		);

		$this->assertSame( Ai_Client_OpenAI::ENDPOINT, $captured['url'] );
		$this->assertSame( 'Bearer sk-test-secret', $captured['args']['headers']['Authorization'] );
		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'gpt-5.6-luna', $body['model'] );
		$this->assertArrayNotHasKey( 'store', $body );
		$this->assertArrayNotHasKey( 'reasoning', $body );
		$this->assertSame( 'function_call_output', $body['input'][2]['type'] );
		$this->assertSame( 'replace_string', $result['toolCalls'][0]['name'] );
		$this->assertSame( 5, $result['usage']['cachedInputTokens'] );
		$this->assertSame( 3, $result['usage']['reasoningOutputTokens'] );
	}

	/** Rate limits are marked retryable for the worker retry policy. */
	public function test_http_429_is_retryable(): void {
		update_option( 'kayzart_openai_api_key', 'sk-test-secret' );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{"error":{"message":"Rate limited"}}',
					'response' => array(
						'code'    => 429,
						'message' => 'Too Many Requests',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		try {
			( new Ai_Client_OpenAI() )->generate( array( Ai_Message::user( 'test' ) ), array() );
			$this->fail( 'Expected an AI client exception.' );
		} catch ( Ai_Client_Exception $error ) {
			$this->assertTrue( $error->is_retryable() );
		}
	}
}
