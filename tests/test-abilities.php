<?php
/**
 * Abilities API (MCP) tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Abilities;
use KayzArt\Admin;
use KayzArt\Agent_Checks;
use KayzArt\Agent_Preview;
use KayzArt\Agent_Pages;
use KayzArt\Ai_Timeline_Store;
use KayzArt\Post_Type;
use KayzArt\Save_Lock;
use KayzArt\Snapshot;
use KayzArt\Tailwind_Compiler;

class KayzArt_Abilities_Redirect_Exception extends Exception {
	public $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

class Test_Abilities extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ! Abilities::is_supported() ) {
			$this->markTestSkipped( 'The Abilities API requires WordPress 6.9 or later.' );
		}
		delete_option( Admin::OPTION_ENABLED_POST_TYPES );
		Snapshot::register_revision_support();
	}

	protected function tearDown(): void {
		delete_option( Admin::OPTION_ENABLED_POST_TYPES );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_abilities_are_registered_for_mcp(): void {
		$expected = array(
			'kayzart/get-guidelines' => true,
			'kayzart/list-pages'     => true,
			'kayzart/get-page'       => true,
			'kayzart/create-page'    => false,
			'kayzart/update-page'    => false,
		);
		foreach ( $expected as $name => $readonly ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' should be registered.' );
			$this->assertSame( Abilities::CATEGORY, $ability->get_category() );
			$meta = $ability->get_meta();
			$this->assertTrue( $meta['mcp']['public'] ?? false, $name . ' should be exposed over MCP.' );
			$this->assertSame( $readonly, $meta['annotations']['readonly'] );
		}
		$this->assertTrue( wp_get_ability( 'kayzart/update-page' )->get_meta()['annotations']['destructive'] );
	}

	public function test_guidelines_include_rules_fonts_and_targets(): void {
		wp_set_current_user( $this->admin() );

		$result = wp_get_ability( 'kayzart/get-guidelines' )->execute( array( 'cssMode' => 'tailwind' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'tailwind', $result['cssMode'] );
		$this->assertStringContainsString( 'Tailwind mode rules', $result['rules'] );
		$this->assertStringContainsString( 'Do not create or preserve <script> tags', $result['rules'] );
		$this->assertStringContainsString( 'build interaction without it', $result['rules'] );
		$this->assertNotEmpty( $result['fonts']['system'] );
		$this->assertSame( array( 'html', 'css', 'head' ), $result['editableTargets'] );
	}

	public function test_guidelines_run_without_input(): void {
		wp_set_current_user( $this->admin() );

		$result = wp_get_ability( 'kayzart/get-guidelines' )->execute();

		$this->assertIsArray( $result );
		$this->assertSame( 'normal', $result['cssMode'] );
		$this->assertStringNotContainsString( 'Tailwind mode rules', $result['rules'] );
	}

	public function test_create_page_saves_a_draft_with_revision(): void {
		wp_set_current_user( $this->admin() );

		$result = wp_get_ability( 'kayzart/create-page' )->execute(
			array(
				'title' => 'Spring sale',
				'html'  => '<section class="hero"><h1>Spring sale</h1></section>',
				'css'   => '.hero{padding:4rem;}',
				'head'  => '<meta name="description" content="Sale">',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$page    = $result['page'];
		$post_id = $page['id'];
		$post    = get_post( $post_id );

		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( Post_Type::PAGE_TYPE, $post->post_type );
		$this->assertSame( 'Spring sale', $post->post_title );
		$this->assertTrue( Post_Type::is_kayzart_enabled_post( $post_id ) );
		$this->assertSame( '<section class="hero"><h1>Spring sale</h1></section>', $post->post_content );
		$this->assertSame( '.hero{padding:4rem;}', get_post_meta( $post_id, '_kayzart_css', true ) );
		$this->assertSame( '<meta name="description" content="Sale">', $page['head'] );
		$this->assertSame( 'normal', $page['cssMode'] );
		$this->assertSame( 64, strlen( $page['baseHash'] ) );
		$this->assertSame( Admin::get_open_editor_url( $post_id ), $page['editUrl'] );
		$this->assertIsInt( $result['revisionId'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertArrayNotHasKey( '_kayzart_setup_required', get_post_meta( $post_id ) );
	}

	public function test_create_page_compiles_tailwind_on_the_server(): void {
		wp_set_current_user( $this->admin() );

		$result = Agent_Pages::create_page(
			array(
				'title'   => 'Tailwind page',
				'html'    => '<main class="flex p-4"><p class="text-ink">Hi</p></main>',
				'css'     => "@import \"tailwindcss\";\n@theme {\n  --color-ink: #111;\n}\n",
				'cssMode' => 'tailwind',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$post_id = $result['page']['id'];
		$this->assertSame( 'tailwind', $result['page']['cssMode'] );
		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$generated = (string) get_post_meta( $post_id, '_kayzart_generated_css', true );
		$this->assertStringContainsString( '.flex', $generated );
		$this->assertStringContainsString( '.text-ink', $generated );
		$this->assertContains( 'p-4', Tailwind_Compiler::decode_candidates( get_post_meta( $post_id, Tailwind_Compiler::CANDIDATES_META_KEY, true ) ) );
	}

	public function test_create_page_in_tailwind_mode_defaults_the_css_source(): void {
		wp_set_current_user( $this->admin() );

		$result = Agent_Pages::create_page(
			array(
				'title'   => 'Default source',
				'html'    => '<p class="font-bold">Hi</p>',
				'cssMode' => 'tailwind',
			)
		);

		$this->assertIsArray( $result );
		$this->assertStringContainsString( '@import "tailwindcss";', $result['page']['css'] );
	}

	public function test_create_page_rejects_unsafe_markup_without_creating_a_post(): void {
		wp_set_current_user( $this->admin() );
		$before = $this->count_pages();

		$result = Agent_Pages::create_page(
			array(
				'title' => 'Bad',
				'html'  => '<p>Hi</p><script>alert(1)</script>',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_unsafe_or_invalid_page', $result->get_error_code() );
		$this->assertNotEmpty( $result->get_error_data()['violations'] );
		$this->assertSame( $before, $this->count_pages() );
	}

	public function test_create_page_rejects_tailwind_css_without_import(): void {
		wp_set_current_user( $this->admin() );

		$result = Agent_Pages::create_page(
			array(
				'title'   => 'No import',
				'html'    => '<p class="flex">Hi</p>',
				'css'     => '@theme { --color-ink: #111; }',
				'cssMode' => 'tailwind',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_unsafe_or_invalid_page', $result->get_error_code() );
	}

	public function test_create_page_requires_ai_capability(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$ability = wp_get_ability( 'kayzart/create-page' );
		$result  = $ability->execute(
			array(
				'title' => 'Nope',
				'html'  => '<p>Hi</p>',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	public function test_logged_out_requests_are_refused(): void {
		wp_set_current_user( 0 );

		$this->assertWPError( wp_get_ability( 'kayzart/list-pages' )->execute( array() ) );
		$this->assertWPError( wp_get_ability( 'kayzart/get-guidelines' )->execute( array() ) );
	}

	public function test_head_is_read_only_without_unfiltered_html(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$deny_unfiltered = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny_unfiltered, 10, 2 );

		try {
			$this->assertSame( array( 'html', 'css' ), Agent_Pages::editable_targets() );
			$result = Agent_Pages::create_page(
				array(
					'title' => 'Head',
					'html'  => '<p>Hi</p>',
					'head'  => '<meta name="robots" content="noindex">',
				)
			);
			$this->assertWPError( $result );
			$this->assertSame( 'kayzart_target_read_only', $result->get_error_code() );

			$result = Agent_Pages::create_page(
				array(
					'title' => 'No head',
					'html'  => '<p>Hi</p>',
				)
			);
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		} finally {
			remove_filter( 'map_meta_cap', $deny_unfiltered, 10 );
		}
	}

	public function test_get_page_returns_sources_and_refuses_regular_pages(): void {
		$admin = $this->admin();
		wp_set_current_user( $admin );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Readable',
				'html'  => '<p>Read me</p>',
				'css'   => 'p{color:red;}',
			)
		);
		$post_id = $created['page']['id'];
		update_post_meta( $post_id, '_kayzart_js', 'console.log(1);' );

		$page = wp_get_ability( 'kayzart/get-page' )->execute( array( 'id' => $post_id ) );
		$this->assertIsArray( $page );
		$this->assertSame( '<p>Read me</p>', $page['html'] );
		$this->assertSame( 'p{color:red;}', $page['css'] );
		$this->assertSame( 'console.log(1);', $page['js'] );

		$regular = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
			)
		);
		$this->assertWPError( wp_get_ability( 'kayzart/get-page' )->execute( array( 'id' => $regular ) ) );
		$this->assertWPError( Agent_Pages::get_page( $regular ) );
	}

	public function test_list_pages_returns_only_kayzart_pages(): void {
		$admin = $this->admin();
		wp_set_current_user( $admin );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Listed',
				'html'  => '<p>Hi</p>',
			)
		);
		self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Regular page',
			)
		);

		$result = wp_get_ability( 'kayzart/list-pages' )->execute( array() );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $created['page']['id'], $result['pages'][0]['id'] );
		$this->assertSame( 'Listed', $result['pages'][0]['title'] );
		$this->assertArrayNotHasKey( 'html', $result['pages'][0] );
	}

	public function test_update_page_applies_edits_and_title(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Before',
				'html'  => '<h1>Old heading</h1><p>Body</p>',
				'css'   => 'h1{color:red;}',
			)
		);
		$page = $created['page'];

		$result = wp_get_ability( 'kayzart/update-page' )->execute(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'title'    => 'After',
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Old heading',
						'to'     => 'New heading',
					),
					array(
						'target' => 'css',
						'from'   => 'red',
						'to'     => 'blue',
					),
				),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<h1>New heading</h1><p>Body</p>', $result['page']['html'] );
		$this->assertSame( 'h1{color:blue;}', $result['page']['css'] );
		$this->assertSame( 'After', get_post( $page['id'] )->post_title );
		$this->assertNotSame( $page['baseHash'], $result['page']['baseHash'] );
		$this->assertIsInt( $result['revisionId'] );
		$this->assertNotSame( $created['revisionId'], $result['revisionId'] );
	}

	public function test_update_page_refuses_a_stale_base_hash(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Stale',
				'html'  => '<p>One</p>',
			)
		);
		$page = $created['page'];
		wp_update_post(
			array(
				'ID'           => $page['id'],
				'post_content' => '<p>Changed by a person</p>',
			)
		);

		$result = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Agent</p>',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_page_changed', $result->get_error_code() );
		$this->assertSame( '<p>Changed by a person</p>', get_post( $page['id'] )->post_content );
	}

	public function test_update_page_reports_failed_replacement_with_candidates(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Edits',
				'html'  => '<p>Hello world</p>',
			)
		);
		$page = $created['page'];

		$result = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Goodbye world',
						'to'     => 'Hi',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_edit_failed', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data()['editIndex'] );
		$this->assertSame( '<p>Hello world</p>', get_post( $page['id'] )->post_content );
	}

	public function test_update_page_keeps_javascript_read_only(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'JS',
				'html'  => '<p>Hi</p>',
			)
		);
		$page = $created['page'];

		$result = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'edits'    => array(
					array(
						'target' => 'js',
						'from'   => '',
						'to'     => 'alert(1)',
					),
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_target_read_only', $result->get_error_code() );

		$schema_result = wp_get_ability( 'kayzart/update-page' )->execute(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'js'       => 'alert(1)',
			)
		);
		$this->assertWPError( $schema_result );
		$this->assertSame( '', (string) get_post_meta( $page['id'], '_kayzart_js', true ) );
	}

	public function test_update_page_requires_a_change(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Nothing',
				'html'  => '<p>Hi</p>',
			)
		);

		$result = Agent_Pages::update_page(
			array(
				'id'       => $created['page']['id'],
				'baseHash' => $created['page']['baseHash'],
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_nothing_to_update', $result->get_error_code() );
	}

	public function test_open_editor_redirects_to_the_nonce_protected_editor(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Open',
				'html'  => '<p>Hi</p>',
			)
		);
		$post_id = $created['page']['id'];

		$original_get = $_GET;
		$_GET         = array( 'post_id' => (string) $post_id );
		$redirect     = static function ( $location ) {
			throw new KayzArt_Abilities_Redirect_Exception( (string) $location );
		};
		add_filter( 'wp_redirect', $redirect );
		try {
			Admin::action_open_editor();
			$this->fail( 'Expected a redirect.' );
		} catch ( KayzArt_Abilities_Redirect_Exception $e ) {
			$this->assertSame( Post_Type::get_editor_url( $post_id ), $e->location );
		} finally {
			remove_filter( 'wp_redirect', $redirect );
			$_GET = $original_get;
		}
	}

	public function test_open_editor_refuses_regular_pages(): void {
		$admin = $this->admin();
		wp_set_current_user( $admin );
		$regular = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
			)
		);

		$original_get = $_GET;
		$_GET         = array( 'post_id' => (string) $regular );
		try {
			$this->expectException( WPDieException::class );
			Admin::action_open_editor();
		} finally {
			$_GET = $original_get;
			$this->assertFalse( Post_Type::is_kayzart_enabled_post( $regular ) );
		}
	}

	public function test_new_abilities_are_registered(): void {
		$this->assertInstanceOf( WP_Ability::class, wp_get_ability( 'kayzart/get-preview-link' ) );
		$this->assertInstanceOf( WP_Ability::class, wp_get_ability( 'kayzart/list-media' ) );
		$this->assertFalse( wp_get_ability( 'kayzart/get-preview-link' )->get_meta()['annotations']['readonly'] );
	}

	public function test_preview_link_shows_the_draft_without_login(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Shared draft',
				'html'  => '<p>Preview me</p>',
			)
		);
		$post_id = $created['page']['id'];

		$link = wp_get_ability( 'kayzart/get-preview-link' )->execute( array( 'id' => $post_id ) );
		$this->assertIsArray( $link );
		$this->assertSame( Agent_Preview::DEFAULT_TTL, $link['expiresIn'] );
		$this->assertStringContainsString( Agent_Preview::QUERY_VAR . '=', $link['url'] );

		wp_set_current_user( 0 );
		$this->go_to( $link['url'] );

		$this->assertTrue( is_page() );
		$this->assertSame( $post_id, get_queried_object_id() );
		$this->assertCount( 1, $GLOBALS['wp_query']->posts );
		$this->assertSame( 'draft', $GLOBALS['wp_query']->posts[0]->post_status );
	}

	public function test_preview_link_is_invalid_after_expiry_or_reissue(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Expiring',
				'html'  => '<p>Hi</p>',
			)
		);
		$post_id = $created['page']['id'];

		$first = Agent_Pages::preview_link( $post_id );
		parse_str( (string) wp_parse_url( $first['url'], PHP_URL_QUERY ), $first_query );
		$first_token = $first_query[ Agent_Preview::QUERY_VAR ];
		$this->assertTrue( Agent_Preview::is_valid( $post_id, $first_token ) );
		$this->assertFalse( Agent_Preview::is_valid( $post_id, 'wrong' ) );

		$second = Agent_Pages::preview_link( $post_id );
		parse_str( (string) wp_parse_url( $second['url'], PHP_URL_QUERY ), $second_query );
		$second_token = $second_query[ Agent_Preview::QUERY_VAR ];
		$this->assertFalse( Agent_Preview::is_valid( $post_id, $first_token ), 'Reissuing should revoke the old link.' );
		$this->assertTrue( Agent_Preview::is_valid( $post_id, $second_token ) );

		$stored            = get_post_meta( $post_id, Agent_Preview::META_KEY, true );
		$stored['expires'] = time() - 1;
		update_post_meta( $post_id, Agent_Preview::META_KEY, $stored );
		$this->assertFalse( Agent_Preview::is_valid( $post_id, $second_token ) );

		wp_set_current_user( 0 );
		$this->go_to( $second['url'] );
		$this->assertCount( 0, $GLOBALS['wp_query']->posts, 'An expired link must not expose the draft.' );
		$this->expectException( WPDieException::class );
		Agent_Preview::maybe_handle_request();
	}

	public function test_preview_link_requires_edit_permission(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Private',
				'html'  => '<p>Hi</p>',
			)
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		$this->assertWPError( wp_get_ability( 'kayzart/get-preview-link' )->execute( array( 'id' => $created['page']['id'] ) ) );
	}

	public function test_list_media_returns_images_with_urls(): void {
		wp_set_current_user( $this->admin() );
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'hero-photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Hero photo',
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Team at work' );
		self::factory()->attachment->create_object(
			array(
				'file'           => 'manual.pdf',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Manual',
			)
		);

		$result = wp_get_ability( 'kayzart/list-media' )->execute( array( 'search' => 'Hero' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $attachment_id, $result['media'][0]['id'] );
		$this->assertSame( 'Team at work', $result['media'][0]['alt'] );
		$this->assertStringEndsWith( 'hero-photo.jpg', $result['media'][0]['url'] );
	}

	public function test_list_media_requires_upload_files(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$deny_upload = static function ( $caps, $cap ) {
			return 'upload_files' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny_upload, 10, 2 );
		try {
			$this->assertWPError( wp_get_ability( 'kayzart/list-media' )->execute( array() ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny_upload, 10 );
		}
	}

	public function test_write_result_reports_page_problems(): void {
		wp_set_current_user( $this->admin() );
		$uploads = wp_get_upload_dir();

		$result = Agent_Pages::create_page(
			array(
				'title'   => 'Problems',
				'html'    => '<nav><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav><section id="faq" class="flex flexx"><img src="' . $uploads['baseurl'] . '/missing/nowhere.jpg"></section>',
				'cssMode' => 'tailwind',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$warnings = implode( "\n", $result['warnings'] );
		$this->assertStringContainsString( 'flexx', $warnings );
		$this->assertStringNotContainsString( 'flex,', $warnings );
		$this->assertStringContainsString( '#pricing', $warnings );
		$this->assertStringNotContainsString( '#faq', $warnings );
		$this->assertStringContainsString( 'no alt attribute', $warnings );
		$this->assertStringContainsString( 'missing/nowhere.jpg', $warnings );
	}

	public function test_clean_page_has_no_warnings(): void {
		wp_set_current_user( $this->admin() );

		$result = Agent_Pages::create_page(
			array(
				'title'   => 'Clean',
				'html'    => '<div class="group md:flex hover:underline w-1/2 p-4.5 [&>p]:mt-2 bg-[#f8fafc] group-hover:underline"><a href="#top">Top</a><img src="https://example.com/a.jpg" alt=""></div>',
				'cssMode' => 'tailwind',
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array(), $result['warnings'] );
	}

	public function test_css_escape_matches_tailwind_selectors(): void {
		$this->assertSame( 'w-1\\/2', Agent_Checks::css_escape( 'w-1/2' ) );
		$this->assertSame( 'md\\:flex', Agent_Checks::css_escape( 'md:flex' ) );
		$this->assertSame( '\\!font-bold', Agent_Checks::css_escape( '!font-bold' ) );
		$this->assertSame( '\\31 0', Agent_Checks::css_escape( '10' ) );
	}

	public function test_agent_writes_are_recorded_as_agent_edits(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title'   => 'History',
				'html'    => '<h1>Old heading</h1>',
				'summary' => 'Created the first draft.',
			)
		);
		$page    = $created['page'];
		$updated = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'summary'  => 'Renamed the heading.',
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Old heading',
						'to'     => 'New heading',
					),
				),
			)
		);
		$this->assertIsArray( $updated );

		$items = ( new Ai_Timeline_Store() )->list_for_post( $page['id'] )['items'];

		$this->assertSame( array( 'agent_edit', 'agent_edit' ), array_column( $items, 'type' ), 'Agent saves replace the plain save rows.' );
		$this->assertSame( 'create', $items[0]['agentAction'] );
		$this->assertSame( 'Created the first draft.', $items[0]['summary'] );
		$this->assertFalse( $items[0]['beforeAvailable'], 'A created page has no earlier state to restore.' );
		$this->assertTrue( $items[0]['detailsAvailable'] );
		$this->assertSame( 'update', $items[1]['agentAction'] );
		$this->assertSame( 'Renamed the heading.', $items[1]['summary'] );
		$this->assertSame( 'MCP', $items[1]['agentClient'] );
		$this->assertSame( array( 'html' ), $items[1]['changedTargets'] );
		$this->assertSame( 1, $items[1]['changeStats']['html']['added'] );
		$this->assertTrue( $items[1]['beforeAvailable'] );
		$this->assertSame( $updated['revisionId'], $items[1]['revisionId'] );
	}

	public function test_agent_edit_snapshots_come_from_revisions_and_can_be_restored(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Restore',
				'html'  => '<p>First</p>',
			)
		);
		$page    = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Second</p>',
			)
		);
		$store    = new Ai_Timeline_Store();
		$activity = $store->get( $store->list_for_post( $page['id'] )['items'][1]['id'] );

		$this->assertSame( '<p>First</p>', $store->get_snapshot( $activity, 'before' )['html'] );
		$this->assertSame( '<p>Second</p>', $store->get_snapshot( $activity, 'after' )['html'] );
		$this->assertTrue( $store->update_application( (int) $activity['id'], 'reverted' ) );
		$this->assertSame( 'reverted', $store->get( (int) $activity['id'] )['application_status'] );
	}

	public function test_built_in_ai_sees_the_latest_agent_edit(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Context',
				'html'  => '<h1>Hello</h1><p>Body</p>',
			)
		);
		$page    = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'summary'  => 'Made the heading friendlier.',
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Hello',
						'to'     => 'Welcome aboard',
					),
				),
			)
		);
		$current = Snapshot::for_post( $page['id'] );

		$context = ( new Ai_Timeline_Store() )->recent_context( $page['id'], $current, 1 );

		$this->assertCount( 1, $context );
		$this->assertSame( 'external_agent', $context[0]['source'] );
		$this->assertSame( 'Made the heading friendlier.', $context[0]['summary'] );
		$this->assertFalse( $context[0]['detailsAvailable'] );
		$this->assertStringContainsString( 'Welcome aboard', wp_json_encode( $context[0]['editFootprint'] ) );
	}

	public function test_title_changes_are_recorded_with_their_previous_title(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Title',
				'html'  => '<p>Hi</p>',
			)
		);
		$page    = $created['page'];
		$renamed = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'title'    => 'Renamed',
				'summary'  => 'Renamed the page.',
			)
		);
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $renamed['page']['baseHash'],
				'title'    => 'Renamed again',
				'html'     => '<p>Changed too</p>',
			)
		);

		$items = ( new Ai_Timeline_Store() )->list_for_post( $page['id'] )['items'];

		$this->assertCount( 3, $items, 'A title-only update is recorded too.' );
		$this->assertNull( $items[0]['titleChange'] );
		$this->assertSame( 'agent_edit', $items[1]['type'] );
		$this->assertSame(
			array(
				'before' => 'Title',
				'after'  => 'Renamed',
			),
			$items[1]['titleChange']
		);
		$this->assertSame( array(), $items[1]['changedTargets'] );
		$this->assertSame( 'Renamed the page.', $items[1]['summary'] );
		$this->assertSame( array(), $items[1]['contexts'] );
		$this->assertSame( 'Renamed again', $items[2]['titleChange']['after'] );
		$this->assertSame( array( 'html' ), $items[2]['changedTargets'] );
	}

	public function test_editor_save_is_refused_when_a_submitted_setting_changed_elsewhere(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Settings',
				'html'  => '<p>Hi</p>',
			)
		);
		$post_id = $created['page']['id'];
		$version = \KayzArt\Rest_Save::current_version( $post_id );
		// Another tab switches the template mode while this editor still shows the old one.
		update_post_meta( $post_id, '_kayzart_template_mode', 'theme' );

		$stale = $this->editor_save_with_settings( $post_id, $version, array( 'templateMode' => 'standalone' ), array( 'templateMode' => 'default' ) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'theme', get_post_meta( $post_id, '_kayzart_template_mode', true ) );

		$title = $this->editor_save_with_settings( $post_id, $version, array( 'title' => 'Mine' ), array( 'title' => 'Not the saved title' ) );
		$this->assertSame( 409, $title->get_status(), 'Any submitted setting is compared, not only the sidebar ones.' );

		$current = $this->editor_save_with_settings( $post_id, $version, array( 'templateMode' => 'standalone' ), array( 'templateMode' => 'theme' ) );
		$this->assertSame( 200, $current->get_status() );
		$this->assertSame( 'standalone', get_post_meta( $post_id, '_kayzart_template_mode', true ) );

		$unrelated = $this->editor_save_with_settings( $post_id, $current->get_data()['version'], array(), array() );
		$this->assertSame( 200, $unrelated->get_status(), 'A save without settings ignores settings changes made elsewhere.' );
	}

	private function editor_save_with_settings( int $post_id, string $base_version, array $updates, array $base ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'html', '<p>Hi</p>' );
		$request->set_param( 'css', '' );
		$request->set_param( 'baseVersion', $base_version );
		if ( $updates ) {
			$request->set_param( 'settingsUpdates', $updates );
			$request->set_param( 'settingsBase', $base );
		}
		return \KayzArt\Rest_Save::save( $request );
	}

	public function test_local_agent_names_are_shown_in_the_viewer_language(): void {
		$this->assertSame( 'Claude Code (local)', Agent_Pages::display_label( 'local: Claude Code ' ) );
		$this->assertSame( 'Claude (local)', Agent_Pages::display_label( 'local:<b>Claude</b>' ) );
		$this->assertSame( 'Local agent', Agent_Pages::display_label( 'local:' ) );
		$this->assertSame( 'Local agent', Agent_Pages::display_label( 'local:<script>alert(1)</script>' ) );
		$this->assertSame( 'Claude Code', Agent_Pages::display_label( 'Claude Code' ), 'Application password names are shown as they are.' );

		$japanese = static function ( $translation, $text ) {
			return '%s (local)' === $text ? '%s（ローカル）' : $translation;
		};
		add_filter( 'gettext', $japanese, 10, 2 );
		try {
			$this->assertSame( 'Claude Code（ローカル）', Agent_Pages::display_label( 'local:Claude Code' ) );
		} finally {
			remove_filter( 'gettext', $japanese, 10 );
		}
	}

	public function test_client_label_can_be_filtered(): void {
		$this->assertSame( 'MCP', Agent_Pages::client_label() );
		$label = static function () {
			return 'Claude Code';
		};
		add_filter( 'kayzart_agent_client_label', $label );
		try {
			$this->assertSame( 'Claude Code', Agent_Pages::client_label() );
		} finally {
			remove_filter( 'kayzart_agent_client_label', $label );
		}
	}

	public function test_abilities_are_refused_without_required_extensions_or_when_ai_is_disabled(): void {
		wp_set_current_user( $this->admin() );
		foreach ( array( 'kayzart_ai_mbstring_present', 'kayzart_ai_dom_present', 'kayzart_ai_feature_enabled' ) as $filter ) {
			add_filter( $filter, '__return_false' );
			try {
				$this->assertFalse( Agent_Pages::can_use(), $filter . ' should disable agent abilities.' );
				$result = wp_get_ability( 'kayzart/create-page' )->execute(
					array(
						'title' => 'Blocked',
						'html'  => '<p>Hi</p>',
					)
				);
				$this->assertWPError( $result );
				$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
			} finally {
				remove_filter( $filter, '__return_false' );
			}
		}
		$this->assertTrue( Agent_Pages::can_use() );
	}

	public function test_update_page_refuses_a_stale_title(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Original title',
				'html'  => '<p>Hi</p>',
			)
		);
		$page    = $created['page'];
		wp_update_post(
			array(
				'ID'         => $page['id'],
				'post_title' => 'Title chosen by a person',
			)
		);

		$result = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'title'    => 'Agent title',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_page_changed', $result->get_error_code() );
		$this->assertSame( 'Title chosen by a person', get_post( $page['id'] )->post_title );
	}

	public function test_saves_wait_for_the_page_lock(): void {
		global $wpdb;
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Locked',
				'html'  => '<p>Before</p>',
			)
		);
		$page = $created['page'];
		// Another request is saving this page right now.
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'kayzart_save_lock_' . $page['id'],
				'option_value' => (string) time(),
				'autoload'     => 'no',
			)
		);
		add_filter( 'kayzart_save_lock_wait', '__return_zero' );
		try {
			$result = Agent_Pages::update_page(
				array(
					'id'       => $page['id'],
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Agent</p>',
				)
			);
			$this->assertWPError( $result );
			$this->assertSame( 'kayzart_page_busy', $result->get_error_code() );

			$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
			$request->set_param( 'post_id', $page['id'] );
			$request->set_param( 'html', '<p>Editor</p>' );
			$request->set_param( 'css', '' );
			$this->assertSame( 409, \KayzArt\Rest_Save::save( $request )->get_status(), 'Editor saves wait for the same lock.' );
			$this->assertSame( '<p>Before</p>', get_post( $page['id'] )->post_content );

			// A lock left behind by a crashed request expires.
			$wpdb->update(
				$wpdb->options,
				array( 'option_value' => (string) ( time() - Save_Lock::STALE_SECONDS - 5 ) ),
				array( 'option_name' => 'kayzart_save_lock_' . $page['id'] )
			);
			$result = Agent_Pages::update_page(
				array(
					'id'       => $page['id'],
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Agent</p>',
				)
			);
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			$this->assertSame( '<p>Agent</p>', get_post( $page['id'] )->post_content );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kayzart_save_lock_' . $page['id'] ) ), 'The lock is released after the save.' );
		} finally {
			remove_filter( 'kayzart_save_lock_wait', '__return_zero' );
		}
	}

	public function test_save_lock_is_reentrant_within_a_request(): void {
		$this->assertTrue( Save_Lock::acquire( 987 ) );
		$this->assertTrue( Save_Lock::acquire( 987 ) );
		Save_Lock::release( 987 );
		add_filter( 'kayzart_save_lock_wait', '__return_zero' );
		try {
			global $wpdb;
			$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kayzart_save_lock_987' ) ), 'The outer hold keeps the lock.' );
			Save_Lock::release( 987 );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kayzart_save_lock_987' ) ) );
		} finally {
			remove_filter( 'kayzart_save_lock_wait', '__return_zero' );
		}
	}

	public function test_list_pages_paginates_only_editable_pages(): void {
		update_option( Admin::OPTION_ENABLED_POST_TYPES, array( Post_Type::PAGE_TYPE, 'post' ) );
		Snapshot::register_revision_support();
		wp_set_current_user( $this->admin() );
		for ( $i = 0; $i < 3; $i++ ) {
			Agent_Pages::create_page(
				array(
					'title'    => 'Admin post ' . $i,
					'html'     => '<p>Admin</p>',
					'postType' => 'post',
				)
			);
		}
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( \KayzArt\Ai_Setup::CAPABILITY );
		wp_set_current_user( $author );
		$own = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$created = Agent_Pages::create_page(
				array(
					'title'    => 'Author post ' . $i,
					'html'     => '<p>Author</p>',
					'postType' => 'post',
				)
			);
			$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
			$own[] = $created['page']['id'];
		}

		$first  = Agent_Pages::list_pages( array( 'perPage' => 1 ) );
		$second = Agent_Pages::list_pages(
			array(
				'perPage' => 1,
				'page'    => 2,
			)
		);

		$this->assertSame( 2, $first['total'] );
		$this->assertSame( 2, $first['totalPages'] );
		$this->assertCount( 1, $first['pages'] );
		$this->assertCount( 1, $second['pages'] );
		$listed = array( $first['pages'][0]['id'], $second['pages'][0]['id'] );
		sort( $listed );
		sort( $own );
		$this->assertSame( $own, $listed );
	}

	public function test_editor_save_is_refused_after_an_agent_changed_the_page(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Shared',
				'html'  => '<p>First</p>',
			)
		);
		$page = $created['page'];
		// The editor opens the page and remembers this version.
		$editor_version = \KayzArt\Rest_Save::current_version( $page['id'] );

		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Agent</p>',
			)
		);

		$stale = $this->editor_save( $page['id'], '<p>Editor</p>', $editor_version );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'kayzart_page_changed', $stale->get_data()['code'] );
		$this->assertSame( \KayzArt\Rest_Save::current_version( $page['id'] ), $stale->get_data()['version'] );
		$this->assertSame( 'Shared', $stale->get_data()['settings']['title'] );

		$retried = $this->editor_save( $page['id'], '<p>Editor (retried)</p>', $stale->get_data()['version'] );
		$this->assertSame( 200, $retried->get_status(), 'A confirmed retry against the reported version succeeds without force.' );
		$editor_version = $stale->get_data()['version'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => Agent_Pages::get_page( $page['id'] )['baseHash'],
				'html'     => '<p>Agent</p>',
			)
		);
		$this->assertSame( '<p>Agent</p>', get_post( $page['id'] )->post_content, 'The agent edit survives.' );

		$forced = $this->editor_save( $page['id'], '<p>Editor</p>', $editor_version, true );
		$this->assertSame( 200, $forced->get_status() );
		$this->assertSame( '<p>Editor</p>', get_post( $page['id'] )->post_content );
		$this->assertSame( \KayzArt\Rest_Save::current_version( $page['id'] ), $forced->get_data()['version'] );

		$next = $this->editor_save( $page['id'], '<p>Editor again</p>', $forced->get_data()['version'] );
		$this->assertSame( 200, $next->get_status(), 'The version from the last save is current.' );
	}

	public function test_editor_save_without_a_version_is_not_checked(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Legacy client',
				'html'  => '<p>First</p>',
			)
		);

		$response = $this->editor_save( $created['page']['id'], '<p>Second</p>', null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '<p>Second</p>', get_post( $created['page']['id'] )->post_content );
	}

	public function test_setup_returns_the_new_version(): void {
		wp_set_current_user( $this->admin() );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		Post_Type::enable_for_post( $post_id );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );

		$request = new WP_REST_Request( 'POST', '/kayzart/v1/setup' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'mode', 'tailwind' );
		$response = \KayzArt\Rest_Setup::setup_mode( $request );

		$this->assertSame( \KayzArt\Rest_Save::current_version( $post_id ), $response->get_data()['snapshotVersion'] );
	}

	public function test_save_lock_release_leaves_a_successor_lock_alone(): void {
		global $wpdb;
		$this->assertTrue( Save_Lock::acquire( 654 ) );
		// The lock was treated as stale and another request took it over.
		$wpdb->update(
			$wpdb->options,
			array( 'option_value' => time() . ':successor' ),
			array( 'option_name' => 'kayzart_save_lock_654' )
		);

		Save_Lock::release( 654 );

		$this->assertSame( time() . ':successor', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kayzart_save_lock_654' ) ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'kayzart_save_lock_654' ) );
	}

	private function editor_save( int $post_id, string $html, ?string $base_version, bool $force = false ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'html', $html );
		$request->set_param( 'css', '' );
		if ( null !== $base_version ) {
			$request->set_param( 'baseVersion', $base_version );
		}
		if ( $force ) {
			$request->set_param( 'force', true );
		}
		return \KayzArt\Rest_Save::save( $request );
	}

	public function test_settings_writes_wait_for_the_page_lock(): void {
		global $wpdb;
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Settings lock',
				'html'  => '<p>Hi</p>',
			)
		);
		$post_id = $created['page']['id'];
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'kayzart_save_lock_' . $post_id,
				'option_value' => time() . ':agent',
				'autoload'     => 'no',
			)
		);
		add_filter( 'kayzart_save_lock_wait', '__return_zero' );
		try {
			$request = new WP_REST_Request( 'POST', '/kayzart/v1/settings' );
			$request->set_param( 'post_id', $post_id );
			$request->set_param( 'updates', array( 'title' => 'Renamed by a person' ) );
			$this->assertSame( 409, \KayzArt\Rest_Settings::update_settings( $request )->get_status() );
			$this->assertSame( 'Settings lock', get_post( $post_id )->post_title );

			$wpdb->delete( $wpdb->options, array( 'option_name' => 'kayzart_save_lock_' . $post_id ) );
			$this->assertSame( 200, \KayzArt\Rest_Settings::update_settings( $request )->get_status() );
			$this->assertSame( 'Renamed by a person', get_post( $post_id )->post_title );
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'kayzart_save_lock_' . $post_id ) ) );
		} finally {
			remove_filter( 'kayzart_save_lock_wait', '__return_zero' );
		}
	}

	public function test_list_media_hides_files_attached_to_posts_the_caller_cannot_read(): void {
		$admin = $this->admin();
		wp_set_current_user( $admin );
		$draft = (int) self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => $admin,
			)
		);
		$published = (int) self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $admin,
			)
		);
		$secret     = self::factory()->attachment->create_object(
			array(
				'file'           => 'secret-launch.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $draft,
				'post_author'    => $admin,
			)
		);
		$public     = self::factory()->attachment->create_object(
			array(
				'file'           => 'public-photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_parent'    => $published,
				'post_author'    => $admin,
			)
		);
		$unattached = self::factory()->attachment->create_object(
			array(
				'file'           => 'library-photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $admin,
			)
		);

		$all = Agent_Pages::list_media( array() );
		$this->assertSame( 3, $all['total'], 'Administrators see every file.' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		get_userdata( $author )->add_cap( \KayzArt\Ai_Setup::CAPABILITY );
		wp_set_current_user( $author );
		$own = self::factory()->attachment->create_object(
			array(
				'file'           => 'author-photo.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $author,
			)
		);

		$first  = Agent_Pages::list_media( array( 'perPage' => 2 ) );
		$second = Agent_Pages::list_media(
			array(
				'perPage' => 2,
				'page'    => 2,
			)
		);
		$ids    = array_merge( array_column( $first['media'], 'id' ), array_column( $second['media'], 'id' ) );
		sort( $ids );
		$expected = array( $public, $unattached, $own );
		sort( $expected );

		$this->assertSame( 3, $first['total'] );
		$this->assertSame( 2, $first['totalPages'] );
		$this->assertSame( $expected, $ids );
		$this->assertNotContains( $secret, $ids, 'A file attached to someone else\'s draft stays hidden.' );
	}

	public function test_setup_waits_for_the_page_lock(): void {
		global $wpdb;
		wp_set_current_user( $this->admin() );
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		Post_Type::enable_for_post( $post_id );
		update_post_meta( $post_id, '_kayzart_setup_required', '1' );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'kayzart_save_lock_' . $post_id,
				'option_value' => time() . ':agent',
				'autoload'     => 'no',
			)
		);
		add_filter( 'kayzart_save_lock_wait', '__return_zero' );
		try {
			$request = new WP_REST_Request( 'POST', '/kayzart/v1/setup' );
			$request->set_param( 'post_id', $post_id );
			$request->set_param( 'mode', 'tailwind' );
			$this->assertSame( 409, \KayzArt\Rest_Setup::setup_mode( $request )->get_status() );
			$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_setup_required', true ), 'Setup did not run while the page was locked.' );

			$wpdb->delete( $wpdb->options, array( 'option_name' => 'kayzart_save_lock_' . $post_id ) );
			$this->assertSame( 200, \KayzArt\Rest_Setup::setup_mode( $request )->get_status() );
			$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		} finally {
			remove_filter( 'kayzart_save_lock_wait', '__return_zero' );
		}
	}

	public function test_update_page_refuses_published_and_scheduled_pages(): void {
		wp_set_current_user( $this->admin() );
		foreach ( array( 'publish', 'future' ) as $status ) {
			$created = Agent_Pages::create_page(
				array(
					'title' => 'Live ' . $status,
					'html'  => '<p>Live</p>',
				)
			);
			$post_id = $created['page']['id'];
			wp_update_post(
				array(
					'ID'            => $post_id,
					'post_status'   => $status,
					'post_date'     => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : current_time( 'mysql' ),
					'post_date_gmt' => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : current_time( 'mysql', true ),
					'edit_date'     => true,
				)
			);
			$this->assertSame( $status, get_post_status( $post_id ) );
			$page = Agent_Pages::get_page( $post_id );
			$this->assertIsArray( $page, 'Agents can still read live pages.' );

			$result = wp_get_ability( 'kayzart/update-page' )->execute(
				array(
					'id'       => $post_id,
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Agent</p>',
				)
			);
			$this->assertWPError( $result, $status . ' pages must not be changed in place.' );
			$this->assertSame( 'kayzart_page_published', $result->get_error_code() );
			$this->assertStringContainsString( Admin::get_agent_live_updates_label(), $result->get_error_message(), 'The agent learns which setting to ask for.' );
			$this->assertSame( '<p>Live</p>', get_post( $post_id )->post_content );
			$this->assertFalse( $page['canUpdate'] );
			$this->assertSame( $result->get_error_message(), $page['updateBlockedReason'] );
		}
	}

	public function test_live_pages_can_be_updated_when_an_administrator_allows_it(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Live and allowed',
				'html'  => '<p>Live</p>',
			)
		);
		$post_id = $created['page']['id'];
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		update_option( Admin::OPTION_AGENT_LIVE_UPDATES, '1' );
		try {
			$page = Agent_Pages::get_page( $post_id );
			$this->assertTrue( $page['canUpdate'] );
			$this->assertNull( $page['updateBlockedReason'] );
			$this->assertTrue( Agent_Pages::guidelines( 'normal' )['publishedPages']['agentsCanUpdate'] );

			$result = Agent_Pages::update_page(
				array(
					'id'       => $post_id,
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Updated live</p>',
				)
			);
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			$this->assertSame( '<p>Updated live</p>', get_post( $post_id )->post_content );
			$this->assertSame( 'publish', get_post_status( $post_id ) );
		} finally {
			delete_option( Admin::OPTION_AGENT_LIVE_UPDATES );
		}
		$this->assertFalse( Agent_Pages::guidelines( 'normal' )['publishedPages']['agentsCanUpdate'], 'Off by default.' );
	}

	public function test_agent_settings_section_says_it_is_only_for_mcp_agents(): void {
		$this->assertSame( '0', Admin::sanitize_agent_live_updates( 'nope' ) );
		$this->assertSame( '1', Admin::sanitize_agent_live_updates( '1' ) );
		update_option( Admin::OPTION_AGENT_LIVE_UPDATES, '1' );
		$this->assertSame( '1', Admin::sanitize_agent_live_updates( null ), 'A field missing from the form keeps the stored choice.' );
		delete_option( Admin::OPTION_AGENT_LIVE_UPDATES );

		ob_start();
		Admin::render_agents_section();
		Admin::render_agent_live_updates_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'MCP Adapter', $html );
		$this->assertStringContainsString( 'do not change AI Edit in the Kayzart editor', $html );
		$this->assertStringContainsString( 'name="' . Admin::OPTION_AGENT_LIVE_UPDATES . '"', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'Off by default.' );
	}

	public function test_update_page_allows_private_and_pending_pages(): void {
		wp_set_current_user( $this->admin() );
		foreach ( array( 'private', 'pending' ) as $status ) {
			$created = Agent_Pages::create_page(
				array(
					'title' => 'Not live ' . $status,
					'html'  => '<p>Before</p>',
				)
			);
			$post_id = $created['page']['id'];
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => $status,
				)
			);
			$result = Agent_Pages::update_page(
				array(
					'id'       => $post_id,
					'baseHash' => Agent_Pages::get_page( $post_id )['baseHash'],
					'html'     => '<p>After</p>',
				)
			);
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			$this->assertSame( '<p>After</p>', get_post( $post_id )->post_content );
			$this->assertSame( $status, get_post_status( $post_id ), 'The status is left as it was.' );
		}
	}

	public function test_save_is_refused_when_its_lock_was_taken_over(): void {
		global $wpdb;
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Long save',
				'html'  => '<p>Before</p>',
			)
		);
		$post_id = $created['page']['id'];
		$name    = 'kayzart_save_lock_' . $post_id;

		// This request holds the lock, then outlives its lease and loses it to another request.
		$this->assertTrue( Save_Lock::acquire( $post_id ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => time() . ':successor' ), array( 'option_name' => $name ) );

		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'html', '<p>Late write</p>' );
		$request->set_param( 'css', '' );
		$response = \KayzArt\Rest_Save::save( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( '<p>Before</p>', get_post( $post_id )->post_content, 'The late writer does not overwrite the new owner.' );
		Save_Lock::release( $post_id );
		$this->assertSame( time() . ':successor', $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ) );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	}

	public function test_confirm_renews_the_lease_and_release_all_frees_held_locks(): void {
		global $wpdb;
		$this->assertFalse( Save_Lock::confirm( 321 ), 'Nothing to confirm without holding the lock.' );
		$this->assertTrue( Save_Lock::acquire( 321 ) );
		$this->assertTrue( Save_Lock::acquire( 321 ) );
		$name  = 'kayzart_save_lock_321';
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$token = substr( $value, strpos( $value, ':' ) + 1 );
		// Pretend the lease started long ago.
		$wpdb->update( $wpdb->options, array( 'option_value' => '1000:' . $token ), array( 'option_name' => $name ) );
		$reflection = new ReflectionProperty( Save_Lock::class, 'held' );
		$reflection->setAccessible( true );
		$held                 = $reflection->getValue();
		$held[321]['value']   = '1000:' . $token;
		$reflection->setValue( null, $held );

		$this->assertTrue( Save_Lock::confirm( 321 ) );
		$renewed = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$this->assertGreaterThan( time() - 5, (int) strtok( $renewed, ':' ), 'The lease restarts.' );
		$this->assertStringEndsWith( ':' . $token, $renewed, 'The owner token is kept.' );

		Save_Lock::release_all();
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ), 'Shutdown frees the lock even when nested.' );
		$this->assertFalse( Save_Lock::confirm( 321 ) );
	}

	private function admin(): int {
		return (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function count_pages(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'page',
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			)
		);
	}
}
