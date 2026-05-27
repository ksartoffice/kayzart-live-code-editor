<?php
/**
 * Tests for setup REST endpoint.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Rest_Setup extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_setup_tailwind_locks_tailwind_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/setup',
			array(
				'post_id' => $post_id,
				'mode'    => 'tailwind',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Tailwind setup should succeed.' );
		$this->assertSame( true, $response->get_data()['tailwindEnabled'] ?? false );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_setup_required', true ) );
	}

	public function test_setup_normal_locks_normal_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/setup',
			array(
				'post_id' => $post_id,
				'mode'    => 'normal',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Normal setup should succeed.' );
		$this->assertSame( false, $response->get_data()['tailwindEnabled'] ?? true );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_setup_required', true ) );
	}

	public function test_setup_does_not_change_locked_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		update_post_meta( $post_id, '_kayzart_tailwind', '1' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/setup',
			array(
				'post_id' => $post_id,
				'mode'    => 'normal',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Locked setup should succeed without changing mode.' );
		$this->assertSame( true, $response->get_data()['tailwindEnabled'] ?? false );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_setup_required', true ) );
	}

	public function test_setup_rejects_import_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/setup',
			array(
				'post_id' => $post_id,
				'mode'    => 'import',
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Import setup mode should stay removed.' );
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

	private function dispatch_route( string $route, array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
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
