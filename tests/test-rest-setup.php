<?php
/**
 * REST setup route tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Rest_Setup extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_setup_clears_setup_required_and_legacy_tailwind_meta(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		update_post_meta( $post_id, '_kayzart_tailwind', '1' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		update_post_meta( $post_id, '_kayzart_generated_css', '.text-sm{font-size:.875rem;}' );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_setup(
			array(
				'post_id' => $post_id,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Setup should succeed for valid request.' );
		$data = $response->get_data();
		$this->assertTrue( $data['ok'] ?? false );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_setup_required', true ) );
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

	private function dispatch_setup( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1/setup' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( get_current_user_id() > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}

		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$this->fail( $response->get_error_message() );
		}

		return $response;
	}
}

