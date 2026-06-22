<?php
/**
 * Tests for create-from-import REST endpoint.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Rest_Import extends WP_UnitTestCase {
	public function setUp(): void {
		parent::setUp();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_create_from_import_creates_tailwind_draft(): void {
		$admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source_post_id = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			array(
				'post_id'    => $source_post_id,
				'mode'       => 'tailwind',
				'html'       => '<div class="text-sm">Tailwind</div>',
				'customHead' => '',
				'css'        => '@import "tailwindcss";',
				'js'         => '',
				'jsMode'     => 'classic',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Tailwind import should create a draft.' );
		$data    = $response->get_data();
		$post_id = (int) ( $data['postId'] ?? 0 );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertNotSame( $source_post_id, $post_id );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertNotSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
		$this->assertStringContainsString( 'post_id=' . $post_id, (string) ( $data['editUrl'] ?? '' ) );
	}

	public function test_create_from_import_creates_normal_draft_and_keeps_v3_cdn(): void {
		$admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source_post_id = $this->create_kayzart_post( $admin_id );
		$custom_head    = '<script>tailwind.config = { theme: { extend: {} } }</script>' . "\n"
			. '<script src="https://cdn.tailwindcss.com"></script>';

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			array(
				'post_id'    => $source_post_id,
				'mode'       => 'normal',
				'html'       => '<div class="text-sm">Tailwind v3</div>',
				'customHead' => $custom_head,
				'css'        => '.custom { color: red; }',
				'js'         => '',
				'jsMode'     => 'classic',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Normal import should create a draft.' );
		$post_id = (int) ( $response->get_data()['postId'] ?? 0 );
		$this->assertGreaterThan( 0, $post_id );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
		$this->assertStringContainsString( 'cdn.tailwindcss.com', (string) get_post_meta( $post_id, '_kayzart_custom_head', true ) );
		$this->assertStringContainsString( 'tailwind.config', (string) get_post_meta( $post_id, '_kayzart_custom_head', true ) );
	}

	public function test_create_from_import_rejects_invalid_mode(): void {
		$admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source_post_id = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			array(
				'post_id' => $source_post_id,
				'mode'    => 'import',
				'html'    => '<div>Invalid</div>',
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Invalid mode should be rejected.' );
	}

	public function test_create_from_import_requires_permission(): void {
		$admin_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$source_post_id = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( 0 );

		$response = $this->dispatch_route(
			array(
				'post_id' => $source_post_id,
				'mode'    => 'normal',
				'html'    => '<div>Denied</div>',
			)
		);

		$this->assertNotSame( 200, $response->get_status(), 'Anonymous import should be rejected.' );
	}

	private function create_kayzart_post( int $author_id ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
		Post_Type::enable_for_post( $post_id );
		return $post_id;
	}

	private function dispatch_route( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1/create-from-import' );
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
