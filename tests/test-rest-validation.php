<?php
/**
 * REST validation tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Limits;
use KayzArt\Post_Type;

class Test_Rest_Validation extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_import_rejects_invalid_version(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload               = $this->get_import_payload_base();
		$payload['version']    = 2;
		$response              = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Invalid import version should fail.' );
	}

	public function test_import_rejects_invalid_tailwind_enabled_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                    = $this->get_import_payload_base();
		$payload['tailwindEnabled'] = 'yes';
		$response                   = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'tailwindEnabled must be boolean.' );
	}

	public function test_import_rejects_invalid_external_scripts_url(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                    = $this->get_import_payload_base();
		$payload['externalScripts'] = array( 'http://example.com/script.js' );
		$response                   = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External scripts must be https URLs.' );
	}

	public function test_import_rejects_invalid_js_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload        = $this->get_import_payload_base();
		$payload['js']  = array( 'alert(1)' );
		$response       = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'JavaScript must be string when provided.' );
	}

	public function test_import_rejects_invalid_js_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload           = $this->get_import_payload_base();
		$payload['jsMode'] = 'esm';
		$response          = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'jsMode must be one of auto/classic/module.' );
	}

	public function test_import_rejects_invalid_generated_css_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload               = $this->get_import_payload_base();
		$payload['generatedCss'] = array( 'body { color: red; }' );
		$response              = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'generatedCss must be string when provided.' );
	}

	public function test_import_rejects_invalid_shadow_dom_enabled_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                    = $this->get_import_payload_base();
		$payload['shadowDomEnabled'] = 'yes';
		$response                   = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'shadowDomEnabled must be boolean.' );
	}

	public function test_import_rejects_invalid_shortcode_enabled_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                    = $this->get_import_payload_base();
		$payload['shortcodeEnabled'] = 1;
		$response                   = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'shortcodeEnabled must be boolean.' );
	}

	public function test_import_rejects_invalid_single_page_enabled_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                     = $this->get_import_payload_base();
		$payload['singlePageEnabled'] = 'true';
		$response                    = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'singlePageEnabled must be boolean.' );
	}

	public function test_import_rejects_invalid_live_highlight_enabled_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                       = $this->get_import_payload_base();
		$payload['liveHighlightEnabled'] = 'false';
		$response                      = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'liveHighlightEnabled must be boolean.' );
	}

	public function test_import_rejects_invalid_external_styles_type(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                   = $this->get_import_payload_base();
		$payload['externalStyles'] = 'https://example.com/style.css';
		$response                  = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'externalStyles must be an array.' );
	}

	public function test_import_rejects_invalid_external_styles_url(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$payload                   = $this->get_import_payload_base();
		$payload['externalStyles'] = array( 'http://example.com/style.css' );
		$response                  = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => $payload,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External styles must be https URLs.' );
	}

	public function test_settings_rejects_non_array_updates(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => 'nope',
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Updates payload must be array.' );
	}

	public function test_settings_rejects_external_scripts_over_limit(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'externalScripts' => array(
						'https://example.com/1.js',
						'https://example.com/2.js',
						'https://example.com/3.js',
						'https://example.com/4.js',
						'https://example.com/5.js',
						'https://example.com/6.js',
						'https://example.com/7.js',
						'https://example.com/8.js',
						'https://example.com/9.js',
						'https://example.com/10.js',
						'https://example.com/11.js',
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External scripts should respect the max limit.' );
	}

	public function test_settings_rejects_external_scripts_invalid_url(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'externalScripts' => array( 'http://example.com/app.js' ),
				),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External scripts must be https URLs.' );
	}

	public function test_settings_rejects_external_styles_invalid_url(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'externalStyles' => array( 'javascript:alert(1)' ),
				),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External styles must be https URLs.' );
	}

	public function test_settings_rejects_external_styles_over_limit(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'externalStyles' => array(
						'https://example.com/1.css',
						'https://example.com/2.css',
						'https://example.com/3.css',
						'https://example.com/4.css',
						'https://example.com/5.css',
						'https://example.com/6.css',
						'https://example.com/7.css',
						'https://example.com/8.css',
						'https://example.com/9.css',
						'https://example.com/10.css',
						'https://example.com/11.css',
					),
				),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'External styles should respect the max limit.' );
	}

	public function test_settings_slug_is_sanitized_before_save(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'slug' => 'My Slug!!',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Slug updates should be accepted.' );

		$data = $response->get_data();
		$this->assertSame( 'my-slug', $data['settings']['slug'] ?? null, 'Slug should be sanitized.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'my-slug', (string) $post->post_name, 'Stored post_name should match sanitized slug.' );
	}

	public function test_settings_slug_is_made_unique_by_wordpress(): void {
		$admin_id         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$existing_post_id = $this->create_kayzart_post( $admin_id );
		$target_post_id   = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		wp_update_post(
			array(
				'ID'          => $existing_post_id,
				'post_name'   => 'duplicate-slug',
				'post_status' => 'publish',
			)
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $target_post_id,
				'updates' => array(
					'slug'   => 'duplicate-slug',
					'status' => 'publish',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Duplicate slug update should succeed.' );

		$data          = $response->get_data();
		$resolved_slug = (string) ( $data['settings']['slug'] ?? '' );
		$this->assertNotSame( 'duplicate-slug', $resolved_slug, 'WordPress should resolve duplicate slugs.' );
		$this->assertSame( 1, preg_match( '/^duplicate-slug(?:-\d+)?$/', $resolved_slug ) );

		$post = get_post( $target_post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( $resolved_slug, (string) $post->post_name );
	}

	public function test_compile_tailwind_rejects_html_over_limit(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/compile-tailwind',
			array(
				'post_id' => $post_id,
				'html'    => str_repeat( 'a', Limits::MAX_TAILWIND_HTML_BYTES + 1 ),
				'css'     => $this->get_tailwind_css_base(),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Tailwind compile should reject oversized HTML.' );
	}

	public function test_compile_tailwind_rejects_css_over_limit(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/compile-tailwind',
			array(
				'post_id' => $post_id,
				'html'    => '<div class="text-sm"></div>',
				'css'     => $this->build_tailwind_css_of_size( Limits::MAX_TAILWIND_CSS_BYTES + 1 ),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Tailwind compile should reject oversized CSS.' );
	}

	public function test_compile_tailwind_accepts_exact_limit_sizes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/compile-tailwind',
			array(
				'post_id' => $post_id,
				'html'    => str_repeat( 'a', Limits::MAX_TAILWIND_HTML_BYTES ),
				'css'     => $this->build_tailwind_css_of_size( Limits::MAX_TAILWIND_CSS_BYTES ),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Tailwind compile should accept exact-limit HTML/CSS.' );
	}

	public function test_save_rejects_invalid_settings_updates_and_preserves_content(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Before save',
			)
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => 'After save',
				'css'             => '',
				'tailwindEnabled' => false,
				'settingsUpdates' => array(
					'externalScripts' => array( 'http://example.com/invalid.js' ),
				),
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Invalid settings updates should fail save.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Before save', (string) $post->post_content, 'Content should stay unchanged when settings validation fails.' );
	}

	public function test_save_rejects_invalid_js_mode(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => '<p>Invalid mode</p>',
				'css'             => '',
				'js'              => 'console.log("x");',
				'jsMode'          => 'esm',
				'tailwindEnabled' => false,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Invalid jsMode should fail save.' );
	}

	public function test_save_rejects_tailwind_input_over_limit_and_preserves_content(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Before save',
			)
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => str_repeat( 'a', Limits::MAX_TAILWIND_HTML_BYTES + 1 ),
				'css'             => $this->get_tailwind_css_base(),
				'tailwindEnabled' => true,
			)
		);

		$this->assertSame( 400, $response->get_status(), 'Save should reject oversized Tailwind input.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Before save', (string) $post->post_content, 'Content should stay unchanged when Tailwind input is oversized.' );
	}

	public function test_save_strips_xss_from_html_for_author(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id' => $post_id,
				'html'    => '<p>Safe</p><script>alert(1)</script><img src="x" onerror="alert(1)"><a href="javascript:alert(1)">x</a>',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Author saves should succeed without JS capability.' );

		$post    = get_post( $post_id );
		$content = $post ? (string) $post->post_content : '';

		$this->assertStringNotContainsString( '<script', $content, 'Script tags should be stripped.' );
		$this->assertStringNotContainsString( 'onerror', $content, 'Event handler attributes should be stripped.' );
		$this->assertStringNotContainsString( 'javascript:', $content, 'javascript: URLs should be stripped.' );
	}

	public function test_save_strips_style_breakout_from_css_for_author(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id' => $post_id,
				'html'    => '<p>CSS test</p>',
				'css'     => '</style><script>alert("test2");</script>body{color:red;}',
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Author saves should succeed without JS capability.' );

		$stored_css = (string) get_post_meta( $post_id, '_kayzart_css', true );
		$this->assertStringNotContainsString( '</style', $stored_css, 'CSS should not contain closing style tags.' );
		$this->assertStringContainsString( '&lt;/style', $stored_css, 'Closing style tags should be escaped.' );
	}

	public function test_frontend_escapes_style_breakout_in_css_output(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_shadow_dom', '1' );
		update_post_meta( $post_id, '_kayzart_css', '</style><script>alert("test2");</script>body{color:red;}' );

		global $wp_query;
		$original_wp_query = $wp_query ?? null;
		$wp_query          = new WP_Query();
		$wp_query->queried_object_id = $post_id;
		$wp_query->queried_object    = $post;

		$output = Frontend::filter_content( (string) $post->post_content );

		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}

		$this->assertStringNotContainsString( '</style><script', $output, 'Inline scripts should not be injected via CSS.' );
		$this->assertStringContainsString( '&lt;/style', $output, 'Output should escape closing style tags.' );
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

	private function get_import_payload_base(): array {
		return array(
			'version'         => 1,
			'html'            => '<p>Import</p>',
			'css'             => '',
			'tailwindEnabled' => false,
		);
	}

	private function get_tailwind_css_base(): string {
		return "@tailwind base;\n@tailwind components;\n@tailwind utilities;\n";
	}

	private function build_tailwind_css_of_size( int $bytes ): string {
		$base = $this->get_tailwind_css_base();
		if ( strlen( $base ) >= $bytes ) {
			return substr( $base, 0, $bytes );
		}

		$pad = $bytes - strlen( $base );
		if ( $pad < 4 ) {
			return $base . str_repeat( 'a', $pad );
		}

		return $base . '/*' . str_repeat( 'a', $pad - 4 ) . '*/';
	}

}



