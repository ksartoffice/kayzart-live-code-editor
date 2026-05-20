<?php
/**
 * REST permission tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Rest_Permissions extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_rest_routes_require_authentication(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( 0 );

		foreach ( $this->get_rest_routes_with_params( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 401, $response->get_status(), $route . ' should require auth.' );
		}
	}

	public function test_rest_routes_forbid_subscriber(): void {
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id       = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $subscriber_id );

		foreach ( $this->get_rest_routes_with_params( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 403, $response->get_status(), $route . ' should forbid subscribers.' );
		}
	}

	public function test_rest_routes_allow_author_for_editable_post(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		foreach ( $this->get_author_allowed_routes( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 200, $response->get_status(), $route . ' should allow authors.' );
		}
	}

	public function test_rest_routes_require_nonce_for_authorized_user(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id' => $post_id,
				'html'    => '<p>Test</p>',
			),
			false
		);
		$this->assertSame( 403, $response->get_status(), 'Authorized users must provide a valid REST nonce.' );
	}

	public function test_rest_routes_reject_param_nonce_without_header_nonce(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'  => $post_id,
				'html'     => '<p>Test</p>',
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			),
			false
		);
		$this->assertSame( 403, $response->get_status(), '_wpnonce parameter alone must not pass REST auth.' );
	}

	public function test_rest_routes_allow_editor_for_others_post(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $editor_id );

		foreach ( $this->get_author_allowed_routes( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 200, $response->get_status(), $route . ' should allow editors.' );
		}
	}

	public function test_rest_routes_forbid_author_for_others_post(): void {
		$author_id       = self::factory()->user->create( array( 'role' => 'author' ) );
		$other_author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id         = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $other_author_id );

		foreach ( $this->get_author_allowed_routes( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 403, $response->get_status(), $route . ' should forbid other authors.' );
		}
	}

	public function test_rest_routes_forbid_non_kayzart_post(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_non_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		foreach ( $this->get_rest_routes_with_params( $post_id ) as $route => $params ) {
			$response = $this->dispatch_route( $route, $params );
			$this->assertSame( 403, $response->get_status(), $route . ' should forbid non-kayzart posts.' );
		}
	}

	public function test_rest_routes_allow_enabled_unmarked_page_and_mark_it(): void {
		$admin_id        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$marked_page_id  = $this->create_page( $admin_id, true );
		$normal_page_id  = $this->create_page( $admin_id, false );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id' => $marked_page_id,
				'html'    => '<p>Page</p>',
			)
		);
		$this->assertSame( 200, $response->get_status(), 'Marked pages should be editable through KayzArt REST.' );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id' => $normal_page_id,
				'html'    => '<p>Page</p>',
			)
		);
		$this->assertSame( 200, $response->get_status(), 'Enabled unmarked pages should become KayzArt-managed through REST.' );
		$this->assertSame( '1', get_post_meta( $normal_page_id, Post_Type::ENABLED_META, true ) );
	}

	public function test_rest_save_requires_unfiltered_html_for_js_payload(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		$params = array(
			'post_id' => $post_id,
			'html'    => '<p>Test</p>',
			'js'      => 'console.log("x");',
		);

		wp_set_current_user( $author_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 403, $response->get_status(), 'Saving JS should require unfiltered_html.' );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 200, $response->get_status(), 'Admins should be able to save JS.' );
	}

	public function test_rest_save_requires_unfiltered_html_for_js_mode_payload(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		$params = array(
			'post_id'         => $post_id,
			'html'            => '<p>Test</p>',
			'css'             => '',
			'tailwindEnabled' => false,
			'jsMode'          => 'module',
		);

		wp_set_current_user( $author_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 403, $response->get_status(), 'Saving jsMode should require unfiltered_html.' );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 200, $response->get_status(), 'Admins should be able to save jsMode.' );
	}

	public function test_rest_save_ignores_legacy_shadow_setting_without_extra_permission(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		$params = array(
			'post_id'         => $post_id,
			'html'            => '<p>Test</p>',
			'css'             => '',
			'tailwindEnabled' => false,
			'settingsUpdates' => array(
				'shadowDomEnabled' => true,
			),
		);

		wp_set_current_user( $author_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 200, $response->get_status(), 'Legacy shadowDomEnabled should be ignored.' );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route( '/kayzart/v1/save', $params );
		$this->assertSame( 200, $response->get_status(), 'Admins should also ignore legacy shadowDomEnabled.' );
	}

	public function test_rest_settings_requires_unfiltered_html_for_js_related_updates(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		$updates = array(
			'externalScripts' => array( 'https://example.com/app.js' ),
			'externalStyles'  => array( 'https://example.com/app.css' ),
		);

		wp_set_current_user( $author_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => $updates,
			)
		);
		$this->assertSame( 403, $response->get_status(), 'Settings updates should require unfiltered_html.' );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => $updates,
			)
		);
		$this->assertSame( 200, $response->get_status(), 'Admins should be able to update JS settings.' );
	}

	public function test_rest_settings_forbid_publish_without_capability(): void {
		$contributor_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$post_id        = $this->create_kayzart_post( $contributor_id );

		wp_set_current_user( $contributor_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'status' => 'publish',
				),
			)
		);

		$this->assertSame( 403, $response->get_status(), 'Contributors should not be able to publish posts.' );
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

	private function create_non_kayzart_post( int $author_id ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
	}

	private function create_page( int $author_id, bool $kayzart_enabled ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::PAGE_TYPE,
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);

		if ( $kayzart_enabled ) {
			update_post_meta( $post_id, Post_Type::ENABLED_META, '1' );
		}

		return $post_id;
	}

	private function dispatch_route( string $route, array $params, bool $with_nonce = true ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( $with_nonce && get_current_user_id() > 0 ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		$response = rest_do_request( $request );
		if ( is_wp_error( $response ) ) {
			$this->fail( $response->get_error_message() );
		}
		return $response;
	}

	private function get_rest_routes_with_params( int $post_id ): array {
		return array(
			'/kayzart/v1/save' => array(
				'post_id' => $post_id,
				'html'    => '<p>Test</p>',
			),
			'/kayzart/v1/settings' => array(
				'post_id' => $post_id,
				'updates' => array(),
			),
		);
	}

	private function get_author_allowed_routes( int $post_id ): array {
		return $this->get_rest_routes_with_params( $post_id );
	}
}



