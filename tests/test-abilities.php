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
		$this->assertSame( Snapshot::hash( Snapshot::for_post( $post_id ) ), $page['baseHash'] );
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
		$this->assertSame( 'Created the first draft.', $items[0]['agentSummary'] );
		$this->assertFalse( $items[0]['beforeAvailable'], 'A created page has no earlier state to restore.' );
		$this->assertTrue( $items[0]['detailsAvailable'] );
		$this->assertSame( 'update', $items[1]['agentAction'] );
		$this->assertSame( 'Renamed the heading.', $items[1]['agentSummary'] );
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

	public function test_title_only_update_is_not_recorded(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Title',
				'html'  => '<p>Hi</p>',
			)
		);
		$page    = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'title'    => 'Renamed',
			)
		);

		$this->assertCount( 1, ( new Ai_Timeline_Store() )->list_for_post( $page['id'] )['items'] );
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
