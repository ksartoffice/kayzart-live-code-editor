<?php
/**
 * Tests for template catalog REST endpoint.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Rest_Templates extends WP_UnitTestCase {
	private const CATALOG_URL = 'https://templates.kayzart.com/v1/catalog.json';

	public function setUp(): void {
		parent::setUp();
		do_action( 'init' );
		do_action( 'rest_api_init' );
		$this->clear_catalog_cache();
	}

	public function tearDown(): void {
		$this->clear_catalog_cache();
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'kayzart_template_catalog_url' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_catalog_endpoint_fetches_and_returns_templates(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		wp_set_current_user( $admin_id );

		$this->mock_catalog_response(
			array(
				'templates' => array(
					$this->valid_template(
						array(
							'id'    => 'hero-en',
							'title' => 'Hero EN',
						)
					),
				),
			)
		);

		$response = $this->dispatch_route( $post_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( true, $data['ok'] ?? false );
		$this->assertCount( 1, $data['templates'] ?? array() );
		$this->assertSame( 'hero-en', $data['templates'][0]['id'] ?? '' );
		$this->assertSame( 'Hero EN', $data['templates'][0]['title'] ?? '' );
	}

	public function test_catalog_endpoint_filters_invalid_templates(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		wp_set_current_user( $admin_id );

		$this->mock_catalog_response(
			array(
				'templates' => array(
					$this->valid_template( array( 'id' => 'valid-en' ) ),
					$this->valid_template(
						array(
							'id'     => 'bad-market',
							'market' => 'fr',
						)
					),
					$this->valid_template(
						array(
							'id'   => 'bad-tier',
							'tier' => 'enterprise',
						)
					),
					array(
						'id' => 'missing-fields',
					),
				),
			)
		);

		$response  = $this->dispatch_route( $post_id );
		$templates = $response->get_data()['templates'] ?? array();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $templates );
		$this->assertSame( 'valid-en', $templates[0]['id'] ?? '' );
	}

	public function test_catalog_endpoint_returns_error_when_remote_fails(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		wp_set_current_user( $admin_id );

		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_failed', 'HTTP failed' );
			}
		);

		$response = $this->dispatch_route( $post_id );
		$data     = $response->get_data();

		$this->assertSame( 502, $response->get_status() );
		$this->assertSame( false, $data['ok'] ?? true );
	}

	public function test_catalog_endpoint_uses_transient_cache(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		wp_set_current_user( $admin_id );

		$request_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$request_count ) {
				$request_count++;
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'templates' => array(
								self::valid_template( array( 'id' => 'cached-en' ) ),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);

		$first  = $this->dispatch_route( $post_id );
		$second = $this->dispatch_route( $post_id );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 1, $request_count );
	}

	public function test_catalog_endpoint_requires_permission(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( 0 );
		$response = $this->dispatch_route( $post_id, false );

		$this->assertNotSame( 200, $response->get_status() );
	}

	private static function valid_template( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'               => 'hero-en',
				'title'            => 'Hero EN',
				'description'      => 'English hero',
				'category'         => 'landing',
				'market'           => 'en',
				'tier'             => 'free',
				'thumbnailUrl'     => 'https://templates.kayzart.com/thumbs/hero-en.webp',
				'requiresTailwind' => true,
				'available'        => true,
				'version'          => '1.0.0',
			),
			$overrides
		);
	}

	private function mock_catalog_response( array $body ): void {
		add_filter(
			'pre_http_request',
			static function () use ( $body ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			}
		);
	}

	private function create_kayzart_post( int $author_id ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
	}

	private function dispatch_route( int $post_id, bool $with_nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/kayzart/v1/templates/catalog' );
		$request->set_param( 'post_id', $post_id );
		if ( $with_nonce && get_current_user_id() > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$this->fail( $response->get_error_message() );
		}
		return $response;
	}

	private function clear_catalog_cache(): void {
		delete_transient( 'kayzart_template_catalog_' . md5( self::CATALOG_URL ) );
	}
}
