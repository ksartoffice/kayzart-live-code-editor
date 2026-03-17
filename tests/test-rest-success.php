<?php
/**
 * REST success path tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\External_Scripts;
use KayzArt\External_Styles;
use KayzArt\Post_Type;
use KayzArt\Rest_Save;
use KayzArt\Rest_Settings;

class Test_Rest_Success extends WP_UnitTestCase {
	private const SETTINGS_PAYLOAD_KEYS = array(
		'title',
		'slug',
		'status',
		'viewUrl',
		'templateMode',
		'defaultTemplateMode',
		'shadowDomEnabled',
		'shortcodeEnabled',
		'singlePageEnabled',
		'liveHighlightEnabled',
		'canEditJs',
		'externalScripts',
		'externalStyles',
		'externalScriptsMax',
		'externalStylesMax',
	);

	private const REMOVED_SETTINGS_PAYLOAD_KEYS = array(
		'visibility',
		'password',
		'dateLocal',
		'dateLabel',
		'author',
		'authors',
		'commentStatus',
		'pingStatus',
		'template',
		'format',
		'featuredImageId',
		'featuredImageUrl',
		'featuredImageAlt',
		'statusOptions',
		'templates',
		'formats',
		'canPublish',
		'canTrash',
	);

	protected function setUp(): void {
		parent::setUp();
		rest_get_server();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_save_updates_content_and_meta_for_admin_with_js(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$html = '<p>Hello KayzArt</p>';
		$css  = '</style>body{color:red;}';
		$js   = 'console.log("hello");';

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => $html,
				'css'             => $css,
				'js'              => $js,
				'jsMode'          => 'module',
				'tailwindEnabled' => false,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Save should succeed for admins with JS.' );
		$this->assertSame( true, $response->get_data()['ok'] ?? false, 'Response should include ok=true.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( $html, (string) $post->post_content, 'Post content should be saved.' );

		$expected_css = str_ireplace( '</style', '&lt;/style', $css );
		$this->assertSame( $expected_css, get_post_meta( $post_id, '_kayzart_css', true ) );
		$this->assertSame( $js, get_post_meta( $post_id, '_kayzart_js', true ) );
		$this->assertSame( 'module', get_post_meta( $post_id, '_kayzart_js_mode', true ) );

		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
	}

	public function test_save_allows_author_without_js(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $author_id );

		$html = '<p>Author content</p>';
		$css  = 'body{background:#fff;}';

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => $html,
				'css'             => $css,
				'tailwindEnabled' => false,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Save should succeed for authors without JS.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( $html, (string) $post->post_content, 'Post content should be saved.' );
		$this->assertSame( $css, get_post_meta( $post_id, '_kayzart_css', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_js', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
	}

	public function test_save_applies_settings_updates_and_returns_settings_payload(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => '<p>Save with settings updates</p>',
				'css'             => 'body{color:#111;}',
				'tailwindEnabled' => false,
				'settingsUpdates' => array(
					'templateMode'         => 'frame',
					'shadowDomEnabled'     => true,
					'shortcodeEnabled'     => true,
					'singlePageEnabled'    => false,
					'liveHighlightEnabled' => false,
					'externalScripts'      => array( 'https://example.com/runtime.js' ),
					'externalStyles'       => array( 'https://example.com/runtime.css' ),
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Save with settings updates should succeed.' );
		$data = $response->get_data();
		$this->assertSame( true, $data['ok'] ?? false, 'Response should include ok=true.' );
		$this->assertIsArray( $data['settings'] ?? null, 'Response should include settings payload.' );
		$this->assert_settings_payload_keys( $data['settings'] );

		$this->assertSame( 'frame', get_post_meta( $post_id, '_kayzart_template_mode', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_shadow_dom', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_shortcode_enabled', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_single_page_enabled', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_live_highlight', true ) );
		$this->assertSame(
			array( 'https://example.com/runtime.js' ),
			External_Scripts::get_external_scripts( $post_id )
		);
		$this->assertSame(
			array( 'https://example.com/runtime.css' ),
			External_Styles::get_external_styles( $post_id )
		);

		$this->assertSame( true, $data['settings']['shadowDomEnabled'] ?? null );
		$this->assertSame( false, $data['settings']['singlePageEnabled'] ?? null );
		$this->assertSame(
			array( 'https://example.com/runtime.js' ),
			$data['settings']['externalScripts'] ?? null
		);
	}

	public function test_settings_update_persists_metadata_and_post_fields(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$updates = array(
			'title'                => 'Updated KayzArt',
			'slug'                 => 'My Custom Slug!!',
			'status'               => 'pending',
			'visibility'           => 'public',
			'shadowDomEnabled'     => true,
			'shortcodeEnabled'     => true,
			'singlePageEnabled'    => false,
			'liveHighlightEnabled' => false,
			'externalScripts'      => array( 'https://example.com/app.js' ),
			'externalStyles'       => array( 'https://example.com/app.css' ),
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => $updates,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Settings update should succeed for admins.' );
		$data = $response->get_data();
		$this->assertSame( true, $data['ok'] ?? false, 'Response should include ok=true.' );
		$this->assertIsArray( $data['settings'] ?? null, 'Response should include settings payload.' );
		$this->assert_settings_payload_keys( $data['settings'] );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Updated KayzArt', (string) $post->post_title );
		$this->assertSame( 'my-custom-slug', (string) $post->post_name );
		$this->assertSame( 'pending', (string) $post->post_status );
		$this->assertSame( '', (string) $post->post_password );

		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_shadow_dom', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_shortcode_enabled', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_single_page_enabled', true ) );
		$this->assertSame( '0', get_post_meta( $post_id, '_kayzart_live_highlight', true ) );

		$this->assertSame(
			array( 'https://example.com/app.js' ),
			External_Scripts::get_external_scripts( $post_id )
		);
		$this->assertSame(
			array( 'https://example.com/app.css' ),
			External_Styles::get_external_styles( $post_id )
		);

		$this->assertSame( true, $data['settings']['shadowDomEnabled'] ?? null );
		$this->assertSame( true, $data['settings']['shortcodeEnabled'] ?? null );
		$this->assertSame( false, $data['settings']['singlePageEnabled'] ?? null );
		$this->assertSame( false, $data['settings']['liveHighlightEnabled'] ?? null );
		$this->assertSame( 'my-custom-slug', $data['settings']['slug'] ?? null );
		$this->assertSame( array( 'https://example.com/app.js' ), $data['settings']['externalScripts'] ?? null );
		$this->assertSame( array( 'https://example.com/app.css' ), $data['settings']['externalStyles'] ?? null );
	}

	public function test_settings_update_with_public_visibility_keeps_existing_password(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_password' => 'secret-pass',
			)
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'status'     => 'publish',
					'visibility' => 'public',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Public visibility updates should succeed.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'publish', (string) $post->post_status );
		$this->assertSame( 'secret-pass', (string) $post->post_password );
	}

	public function test_settings_update_with_private_visibility_clears_password_and_sets_private_status(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_password' => 'secret-pass',
			)
		);

		$response = $this->dispatch_route(
			'/kayzart/v1/settings',
			array(
				'post_id' => $post_id,
				'updates' => array(
					'visibility' => 'private',
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Private visibility updates should succeed.' );

		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'private', (string) $post->post_status );
		$this->assertSame( '', (string) $post->post_password );
	}

	public function test_build_settings_payload_returns_minimal_keys_only(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$settings = Rest_Settings::build_settings_payload( $post_id );

		$this->assertIsArray( $settings, 'Settings payload should be an array.' );
		$this->assert_settings_payload_keys( $settings );
		$this->assertArrayNotHasKey( 'authors', $settings, 'Authors should not be returned.' );
	}

	public function test_import_returns_minimal_settings_payload(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/import',
			array(
				'post_id' => $post_id,
				'payload' => array(
					'version'         => 1,
					'html'            => '<p>Imported</p>',
					'css'             => '',
					'tailwindEnabled' => false,
				),
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Import should succeed for admins.' );

		$data = $response->get_data();
		$this->assertSame( true, $data['ok'] ?? false, 'Response should include ok=true.' );
		$this->assertIsArray( $data['settingsData'] ?? null, 'Response should include settingsData payload.' );
		$this->assert_settings_payload_keys( $data['settingsData'] );
		$this->assertArrayNotHasKey( 'authors', $data['settingsData'], 'Authors should not be returned.' );
		$this->assertSame( 'auto', get_post_meta( $post_id, '_kayzart_js_mode', true ) );
	}

	public function test_save_compiles_tailwind_and_stores_generated_css(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$tailwind_css = "@tailwind base;\n@tailwind components;\n@tailwind utilities;";
		$html         = '<div class="text-sm">Tailwind</div>';

		$response = $this->dispatch_route(
			'/kayzart/v1/save',
			array(
				'post_id'         => $post_id,
				'html'            => $html,
				'css'             => $tailwind_css,
				'tailwindEnabled' => true,
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Tailwind save should succeed for admins.' );

		$generated_css = (string) get_post_meta( $post_id, '_kayzart_generated_css', true );
		$this->assertNotSame( '', $generated_css, 'Generated CSS should not be empty.' );
		$this->assertStringContainsString( '.text-sm', $generated_css, 'Generated CSS should include the expected utility.' );
		$this->assertStringContainsString( '@layer base {', $generated_css );
		$this->assertStringContainsString( ':host,', $generated_css );
		$this->assertStringContainsString( ':host ::backdrop{', $generated_css );
		$this->assertStringContainsString( '--tw-border-style: solid;', $generated_css );
		$this->assertStringContainsString( '--tw-gradient-position: initial;', $generated_css );
		$this->assertStringContainsString( '--tw-gradient-from-position: 0%;', $generated_css );
		$this->assertStringContainsString( '--tw-shadow-color: initial;', $generated_css );
		$this->assertStringContainsString( '--tw-ring-offset-color: #fff;', $generated_css );
		$this->assertStringContainsString( '--radius: 0.25rem;', $generated_css );

		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
	}

	public function test_compile_tailwind_response_includes_shadow_fallbacks(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$response = $this->dispatch_route(
			'/kayzart/v1/compile-tailwind',
			array(
				'post_id' => $post_id,
				'html'    => '<div class="text-sm">Tailwind</div>',
				'css'     => "@tailwind base;\n@tailwind components;\n@tailwind utilities;",
			)
		);

		$this->assertSame( 200, $response->get_status(), 'Tailwind compile should succeed for admins.' );
		$data = $response->get_data();
		$this->assertSame( true, $data['ok'] ?? false );
		$this->assertStringContainsString( ':host,', (string) ( $data['css'] ?? '' ) );
		$this->assertStringContainsString( ':host ::backdrop{', (string) ( $data['css'] ?? '' ) );
		$this->assertStringContainsString(
			'--tw-gradient-from-position: 0%;',
			(string) ( $data['css'] ?? '' )
		);
		$this->assertStringContainsString(
			'--tw-ring-offset-color: #fff;',
			(string) ( $data['css'] ?? '' )
		);
		$this->assertStringContainsString( '--radius: 0.25rem;', (string) ( $data['css'] ?? '' ) );
	}

	public function test_append_tailwind_shadow_fallbacks_is_idempotent(): void {
		$base_css = '.text-sm{font-size:.875rem;}';
		$once     = Rest_Save::append_tailwind_shadow_fallbacks( $base_css );
		$twice    = Rest_Save::append_tailwind_shadow_fallbacks( $once );

		$this->assertSame(
			1,
			substr_count( $twice, '@layer base {' ),
			'Fallback block should be injected only once.'
		);
		$this->assertSame(
			1,
			substr_count( $twice, '--tw-gradient-from-position: 0%;' ),
			'Fallback declarations should not be duplicated.'
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

	private function assert_settings_payload_keys( array $settings ): void {
		$expected_keys = self::SETTINGS_PAYLOAD_KEYS;
		sort( $expected_keys );
		$actual_keys = array_keys( $settings );
		sort( $actual_keys );

		$this->assertSame( $expected_keys, $actual_keys, 'Settings payload keys should match the minimal schema.' );

		foreach ( self::REMOVED_SETTINGS_PAYLOAD_KEYS as $removed_key ) {
			$this->assertArrayNotHasKey(
				$removed_key,
				$settings,
				sprintf( 'Legacy settings key "%s" should not exist.', $removed_key )
			);
		}
	}
}

