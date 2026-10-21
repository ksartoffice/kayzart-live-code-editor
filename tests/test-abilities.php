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
use KayzArt\Agent_Working_Copy;
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
		$this->assertFalse( wp_get_ability( 'kayzart/update-page' )->get_meta()['annotations']['destructive'], 'Updates are proposals; the page is not overwritten.' );
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
		$this->assertSame( 'proposed', $result['state'] );
		$this->assertNotEmpty( $result['message'] );
		$this->assertSame( 'proposed', $result['page']['state'] );
		$this->assertSame( '<h1>New heading</h1><p>Body</p>', $result['page']['html'] );
		$this->assertSame( 'h1{color:blue;}', $result['page']['css'] );
		$this->assertSame( 'After', $result['page']['title'] );
		$this->assertSame( $page['id'], $result['page']['id'], 'The agent keeps addressing the page, not its working copy.' );
		$this->assertNotSame( $page['baseHash'], $result['page']['baseHash'] );
		$this->assertIsInt( $result['revisionId'] );
		$this->assertNotSame( $created['revisionId'], $result['revisionId'] );

		$saved = get_post( $page['id'] );
		$this->assertSame( 'Before', $saved->post_title, 'The page itself is not changed by an update.' );
		$this->assertSame( '<h1>Old heading</h1><p>Body</p>', $saved->post_content );
		$this->assertSame( 'h1{color:red;}', get_post_meta( $page['id'], '_kayzart_css', true ) );
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

	public function test_agent_edit_snapshots_are_kept_after_the_working_copy_is_gone(): void {
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
		wp_update_post(
			array(
				'ID'           => $page['id'],
				'post_content' => '<p>Second</p>',
			)
		);
		Agent_Working_Copy::merged( $page['id'], array( (int) $activity['id'] ) );
		$this->assertSame( 0, Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() ), 'The working copy is gone after the merge.' );

		$this->assertSame( '<p>First</p>', $store->get_snapshot( $activity, 'before' )['html'] );
		$this->assertSame( '<p>Second</p>', $store->get_snapshot( $activity, 'after' )['html'] );
		$this->assertTrue( $store->update_application( (int) $activity['id'], 'reverted' ) );
		$this->assertSame( 'reverted', $store->get( (int) $activity['id'] )['application_status'] );
	}

	public function test_built_in_ai_sees_the_latest_merged_agent_edit(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title'   => 'Context',
				'html'    => '<h1>Hello</h1><p>Body</p>',
				'summary' => 'Created the draft.',
			)
		);
		$page    = $created['page'];
		$store   = new Ai_Timeline_Store();
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
		$pending = $store->recent_context( $page['id'], Snapshot::for_post( $page['id'] ), 1 );
		$this->assertSame( 'Created the draft.', $pending[0]['summary'], 'A proposal is not part of the page until it is merged.' );

		$items = $store->list_for_post( $page['id'] )['items'];
		wp_update_post(
			array(
				'ID'           => $page['id'],
				'post_content' => '<h1>Welcome aboard</h1><p>Body</p>',
			)
		);
		$this->assertSame( 1, Agent_Working_Copy::merged( $page['id'], array( (int) $items[1]['id'] ) ) );
		$current = Snapshot::for_post( $page['id'] );

		$context = $store->recent_context( $page['id'], $current, 1 );

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
		$this->assertSame( 'Title', get_post( $page['id'] )->post_title, 'Titles are proposed like sources.' );
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

	public function test_updates_build_on_one_working_copy_per_agent(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Copy',
				'html'  => '<p>One</p>',
			)
		);
		$page    = $created['page'];
		$this->assertSame( 'saved', $created['state'] );
		$this->assertSame( 'saved', $page['state'] );

		$first = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Two</p>',
			)
		);
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );
		$copy_id = Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() );
		$this->assertGreaterThan( 0, $copy_id );
		$this->assertSame( Agent_Working_Copy::STATUS, get_post_status( $copy_id ) );
		$this->assertSame( $page['id'], Agent_Working_Copy::page_of( $copy_id ) );

		$read = Agent_Pages::get_page( $page['id'] );
		$this->assertSame( 'proposed', $read['state'] );
		$this->assertSame( '<p>Two</p>', $read['html'] );
		$this->assertSame( $first['page']['baseHash'], $read['baseHash'] );

		$this->assertWPError(
			Agent_Pages::update_page(
				array(
					'id'       => $page['id'],
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Stale</p>',
				)
			),
			'The baseHash of the saved page no longer matches once a proposal exists.'
		);

		$second = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $read['baseHash'],
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Two',
						'to'     => 'Three',
					),
				),
			)
		);
		$this->assertIsArray( $second, is_wp_error( $second ) ? $second->get_error_message() : '' );
		$this->assertSame( '<p>Three</p>', $second['page']['html'] );
		$this->assertSame( $copy_id, Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() ), 'One working copy per page and agent.' );
		$this->assertSame( '<p>One</p>', get_post( $page['id'] )->post_content );
	}

	public function test_a_working_copy_starts_at_the_page_version_with_its_settings(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title'   => 'Tailwind copy',
				'html'    => '<main class="flex p-4">Hi</main>',
				'head'    => '<meta name="description" content="Copy">',
				'cssMode' => 'tailwind',
			)
		);
		$page_id = $created['page']['id'];
		update_post_meta( $page_id, '_wp_page_template', 'blank.php' );

		$started = Agent_Working_Copy::start( $page_id, Agent_Pages::agent_key() );

		$this->assertIsArray( $started );
		$this->assertSame( Agent_Working_Copy::version( $page_id ), $started['base'] );
		$this->assertSame( Agent_Working_Copy::version( $page_id ), Agent_Working_Copy::version( $started['id'] ) );
		$this->assertSame( '1', get_post_meta( $started['id'], '_kayzart_tailwind', true ) );
		$this->assertSame( get_post_meta( $page_id, '_kayzart_generated_css', true ), get_post_meta( $started['id'], '_kayzart_generated_css', true ) );
		$this->assertSame( 'blank.php', get_post_meta( $started['id'], '_wp_page_template', true ) );
		$this->assertSame( '', get_post_meta( $started['id'], Agent_Preview::META_KEY, true ) );
	}

	public function test_working_copies_stay_out_of_lists_the_editor_and_direct_access(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Hidden',
				'html'  => '<p>One</p>',
			)
		);
		$page = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Two</p>',
			)
		);
		$copy_id = Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() );

		$listed = Agent_Pages::list_pages( array() );
		$this->assertSame( array( $page['id'] ), array_column( $listed['pages'], 'id' ) );
		$this->assertNotContains( $copy_id, get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => -1 ) ) );
		$this->assertFalse( Agent_Pages::is_agent_page( $copy_id ) );
		$this->assertWPError( Agent_Pages::get_page( $copy_id ) );
		$this->assertFalse( Agent_Pages::can_edit( $copy_id ) );

		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'post_id', $copy_id );
		$this->assertFalse( \KayzArt\Rest::permission_check( $request ), 'The editor REST routes refuse working copies.' );

		$this->assertSame( array(), ( new Ai_Timeline_Store() )->list_for_post( $copy_id )['items'], 'History is kept on the page.' );
	}

	public function test_a_working_copy_starts_over_when_a_person_saved_something_else(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Rebase',
				'html'  => '<p>One</p>',
			)
		);
		$page     = $created['page'];
		$proposed = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Agent</p>',
			)
		);
		$copy_id  = Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() );
		wp_update_post(
			array(
				'ID'           => $page['id'],
				'post_content' => '<p>Person</p>',
			)
		);

		$this->assertSame( 0, Agent_Working_Copy::current( $page['id'], Agent_Pages::agent_key() ) );
		$stale = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $proposed['page']['baseHash'],
				'html'     => '<p>Agent again</p>',
			)
		);
		$this->assertWPError( $stale );
		$this->assertSame( 'kayzart_page_changed', $stale->get_error_code() );

		$read = Agent_Pages::get_page( $page['id'] );
		$this->assertSame( 'saved', $read['state'] );
		$this->assertSame( '<p>Person</p>', $read['html'] );

		$restarted = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $read['baseHash'],
				'edits'    => array(
					array(
						'target' => 'html',
						'from'   => 'Person',
						'to'     => 'Person and agent',
					),
				),
			)
		);
		$this->assertIsArray( $restarted, is_wp_error( $restarted ) ? $restarted->get_error_message() : '' );
		$this->assertSame( '<p>Person and agent</p>', $restarted['page']['html'] );
		$this->assertSame( $copy_id, Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() ), 'The copy is reset in place.' );

		$items = ( new Ai_Timeline_Store() )->list_for_post( $page['id'] )['items'];
		$this->assertSame( $items[1]['proposal']['agent'], $items[2]['proposal']['agent'] );
		$this->assertNotSame( $items[1]['proposal']['chain'], $items[2]['proposal']['chain'], 'A restarted copy begins a new chain.' );
	}

	public function test_an_agent_continues_after_its_proposal_was_saved_as_is(): void {
		wp_set_current_user( $this->admin() );
		$created  = Agent_Pages::create_page(
			array(
				'title' => 'Merged',
				'html'  => '<p>One</p>',
			)
		);
		$page     = $created['page'];
		$proposed = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Two</p>',
			)
		);
		// The person merges the proposal in the editor and saves it unchanged.
		wp_update_post(
			array(
				'ID'           => $page['id'],
				'post_content' => '<p>Two</p>',
			)
		);
		Agent_Working_Copy::delete( Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() ) );

		$read = Agent_Pages::get_page( $page['id'] );
		$this->assertSame( 'saved', $read['state'] );
		$this->assertSame( $proposed['page']['baseHash'], $read['baseHash'], 'The saved page has the version the agent last saw.' );

		$next = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $proposed['page']['baseHash'],
				'html'     => '<p>Three</p>',
			)
		);
		$this->assertIsArray( $next, is_wp_error( $next ) ? $next->get_error_message() : '' );
		$this->assertSame( '<p>Three</p>', $next['page']['html'] );
	}

	public function test_each_agent_has_its_own_working_copy(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Two agents',
				'html'  => '<p>One</p>',
			)
		);
		$page    = $created['page'];
		$name    = 'Claude Code';
		$label   = static function () use ( &$name ) {
			return 'local:' . $name;
		};
		add_filter( 'kayzart_agent_client_label', $label );
		try {
			Agent_Pages::update_page(
				array(
					'id'       => $page['id'],
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Claude</p>',
				)
			);
			$claude = Agent_Pages::agent_key();
			$name   = 'Codex';
			$this->assertSame( 'saved', Agent_Pages::get_page( $page['id'] )['state'], 'Another agent does not see the first agent\'s proposal.' );
			$codex = Agent_Pages::update_page(
				array(
					'id'       => $page['id'],
					'baseHash' => $page['baseHash'],
					'html'     => '<p>Codex</p>',
				)
			);
			$this->assertIsArray( $codex, is_wp_error( $codex ) ? $codex->get_error_message() : '' );
			$this->assertNotSame( $claude, Agent_Pages::agent_key() );
			$this->assertNotSame( Agent_Working_Copy::find( $page['id'], $claude ), Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() ) );
			$name = 'Claude Code';
			$this->assertSame( '<p>Claude</p>', Agent_Pages::get_page( $page['id'] )['html'] );
		} finally {
			remove_filter( 'kayzart_agent_client_label', $label );
		}
	}

	public function test_preview_link_shows_the_agents_proposal(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Preview proposal',
				'html'  => '<p>Saved</p>',
			)
		);
		$page = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Proposed</p>',
			)
		);
		$copy_id = Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() );

		$link = Agent_Pages::preview_link( $page['id'] );
		$this->assertSame( 'proposed', $link['state'] );

		wp_set_current_user( 0 );
		$this->go_to( $link['url'] );
		$this->assertSame( $copy_id, get_queried_object_id() );
		$this->assertSame( '<p>Proposed</p>', $GLOBALS['wp_query']->posts[0]->post_content );
	}

	public function test_proposals_are_recorded_as_pending_on_the_page(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Pending',
				'html'  => '<p>One</p>',
			)
		);
		$page    = $created['page'];
		$first   = Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Two</p>',
			)
		);
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $first['page']['baseHash'],
				'html'     => '<p>Three</p>',
			)
		);

		$items = ( new Ai_Timeline_Store() )->list_for_post( $page['id'] )['items'];

		$this->assertSame( 'applied', $items[0]['applicationStatus'], 'A new draft is written directly.' );
		$this->assertNull( $items[0]['proposal'] );
		$this->assertSame( array( 'pending', 'pending' ), array( $items[1]['applicationStatus'], $items[2]['applicationStatus'] ) );
		$this->assertSame( $items[1]['proposal']['chain'], $items[2]['proposal']['chain'], 'Updates on one working copy form a chain.' );
		$this->assertSame( $items[1]['afterHash'], $items[2]['beforeHash'] );
	}

	public function test_deleting_a_page_deletes_its_working_copies(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Deleted',
				'html'  => '<p>One</p>',
			)
		);
		$page = $created['page'];
		Agent_Pages::update_page(
			array(
				'id'       => $page['id'],
				'baseHash' => $page['baseHash'],
				'html'     => '<p>Two</p>',
			)
		);
		$copy_id = Agent_Working_Copy::find( $page['id'], Agent_Pages::agent_key() );

		wp_delete_post( $page['id'], true );

		$this->assertNull( get_post( $copy_id ) );
	}

	public function test_saving_a_merged_proposal_applies_its_chain_and_removes_the_copy(): void {
		wp_set_current_user( $this->admin() );
		list( $page_id, $items ) = $this->page_with_proposals( array( '<p>Two</p>', '<p>Three</p>' ) );
		$copy_id                 = Agent_Working_Copy::find( $page_id, Agent_Pages::agent_key() );
		// The person merged the newest proposal and saved it unchanged.
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>Three</p>',
			)
		);

		$this->assertSame( 2, Agent_Working_Copy::merged( $page_id, array( $items[2]['id'] ) ) );

		$store = new Ai_Timeline_Store();
		$this->assertSame( 'applied', $store->get( $items[1]['id'] )['application_status'], 'An earlier proposal on the chain is included.' );
		$this->assertSame( 'applied', $store->get( $items[2]['id'] )['application_status'] );
		$this->assertNull( get_post( $copy_id ), 'Nothing is left in the copy beyond the saved page.' );
		$this->assertSame( 0, Agent_Working_Copy::merged( $page_id, array( $items[2]['id'] ) ), 'Settling twice changes nothing.' );
	}

	public function test_a_copy_with_newer_proposals_continues_from_the_saved_page(): void {
		wp_set_current_user( $this->admin() );
		list( $page_id, $items ) = $this->page_with_proposals( array( '<p>Two</p>', '<p>Three</p>' ) );
		$copy_id                 = Agent_Working_Copy::find( $page_id, Agent_Pages::agent_key() );
		// The person merged the first proposal and saved it while the agent went on.
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>Two</p>',
			)
		);

		$this->assertSame( 1, Agent_Working_Copy::merged( $page_id, array( $items[1]['id'] ) ) );

		$store = new Ai_Timeline_Store();
		$this->assertSame( 'pending', $store->get( $items[2]['id'] )['application_status'] );
		$this->assertSame( $copy_id, Agent_Working_Copy::current( $page_id, Agent_Pages::agent_key() ), 'The newer proposal stays current.' );
		$this->assertSame( '<p>Three</p>', Agent_Pages::get_page( $page_id )['html'] );
	}

	public function test_a_merged_proposal_changed_before_saving_leaves_the_copy_out_of_date(): void {
		wp_set_current_user( $this->admin() );
		list( $page_id, $items ) = $this->page_with_proposals( array( '<p>Two</p>', '<p>Three</p>' ) );
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => '<p>Two, edited by the person</p>',
			)
		);

		Agent_Working_Copy::merged( $page_id, array( $items[1]['id'] ) );

		$this->assertSame( 'applied', ( new Ai_Timeline_Store() )->get( $items[1]['id'] )['application_status'] );
		$this->assertSame( 0, Agent_Working_Copy::current( $page_id, Agent_Pages::agent_key() ) );
		$this->assertSame( '<p>Two, edited by the person</p>', Agent_Pages::get_page( $page_id )['html'] );
	}

	public function test_discarding_a_proposal_discards_its_chain_and_copy(): void {
		wp_set_current_user( $this->admin() );
		list( $page_id, $items ) = $this->page_with_proposals( array( '<p>Two</p>', '<p>Three</p>' ) );
		$copy_id                 = Agent_Working_Copy::find( $page_id, Agent_Pages::agent_key() );

		$this->assertTrue( Agent_Working_Copy::discard( $page_id, $items[1]['id'] ) );

		$store = new Ai_Timeline_Store();
		$this->assertSame( 'discarded', $store->get( $items[1]['id'] )['application_status'] );
		$this->assertSame( 'discarded', $store->get( $items[2]['id'] )['application_status'] );
		$this->assertNull( get_post( $copy_id ) );
		$this->assertSame( 'saved', Agent_Pages::get_page( $page_id )['state'] );
		$this->assertFalse( Agent_Working_Copy::discard( $page_id, $items[1]['id'] ), 'Only pending proposals can be discarded.' );
		$this->assertFalse( Agent_Working_Copy::discard( $page_id, $items[0]['id'] ), 'A direct write is not a proposal.' );
	}

	public function test_proposal_status_changes_only_through_merge_or_discard(): void {
		wp_set_current_user( $this->admin() );
		list( , $items ) = $this->page_with_proposals( array( '<p>Two</p>' ) );
		$store           = new Ai_Timeline_Store();

		$this->assertFalse( $store->update_application( $items[1]['id'], 'applied' ) );
		$this->assertFalse( $store->update_application( $items[1]['id'], 'reverted' ) );
		$this->assertSame( 'pending', $store->get( $items[1]['id'] )['application_status'] );
	}

	public function test_timeline_routes_for_proposals(): void {
		wp_set_current_user( $this->admin() );
		list( $page_id, $items ) = $this->page_with_proposals( array( '<p>Two</p>' ) );
		$nonce                   = wp_create_nonce( 'wp_rest' );
		do_action( 'rest_api_init' );

		$state = new WP_REST_Request( 'GET', '/kayzart/v1/ai/timeline/state' );
		$state->set_header( 'X-WP-Nonce', $nonce );
		$state->set_param( 'post_id', $page_id );
		$polled = rest_get_server()->dispatch( $state )->get_data();
		$before = $polled['signature'];
		$this->assertNotSame( '', $before );
		$this->assertLessThan( 60, $polled['agentIdleSeconds'], 'The agent just wrote to the page.' );

		$quiet = new WP_REST_Request( 'GET', '/kayzart/v1/ai/timeline/state' );
		$quiet->set_header( 'X-WP-Nonce', $nonce );
		$quiet->set_param( 'post_id', Agent_Pages::create_page( array( 'title' => 'Quiet', 'html' => '<p>Hi</p>' ) )['page']['id'] );
		$this->assertLessThan( 60, rest_get_server()->dispatch( $quiet )->get_data()['agentIdleSeconds'], 'Creating a page is agent work too.' );
		delete_option( Agent_Pages::SEEN_OPTION );
		$quiet->set_param( 'post_id', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		$this->assertNull( rest_get_server()->dispatch( $quiet )->get_data()['agentIdleSeconds'], 'No agent ever worked on this page.' );

		$discard = new WP_REST_Request( 'POST', '/kayzart/v1/ai/timeline/' . $items[1]['id'] . '/discard' );
		$discard->set_header( 'X-WP-Nonce', $nonce );
		$response = rest_get_server()->dispatch( $discard );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'discarded', $response->get_data()['item']['applicationStatus'] );
		$this->assertSame( 409, rest_get_server()->dispatch( $discard )->get_status() );
		$this->assertNotSame( $before, rest_get_server()->dispatch( $state )->get_data()['signature'], 'The signature changes with the history.' );

		$merged = new WP_REST_Request( 'POST', '/kayzart/v1/ai/timeline/merged' );
		$merged->set_header( 'X-WP-Nonce', $nonce );
		$merged->set_param( 'post_id', $page_id );
		$merged->set_param( 'ids', array( $items[1]['id'] ) );
		$response = rest_get_server()->dispatch( $merged );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['applied'], 'A discarded proposal is not applied later.' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$state->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $state )->get_status() );
	}

	public function test_the_editor_checks_on_a_timer_once_agents_are_used(): void {
		delete_option( Agent_Working_Copy::IN_USE_OPTION );
		wp_set_current_user( $this->admin() );
		$this->assertSame( 'focus', Agent_Working_Copy::proposal_checks(), 'Without the MCP Adapter or an agent write, the editor checks when the person comes back.' );

		Agent_Pages::create_page(
			array(
				'title' => 'First agent page',
				'html'  => '<p>Hi</p>',
			)
		);
		$this->assertSame( 'poll', Agent_Working_Copy::proposal_checks() );
		delete_option( Agent_Working_Copy::IN_USE_OPTION );

		$checks = static function () {
			return 'nonsense';
		};
		add_filter( 'kayzart_agent_proposal_checks', $checks );
		try {
			$this->assertSame( 'focus', Agent_Working_Copy::proposal_checks(), 'An unknown value falls back to focus.' );
		} finally {
			remove_filter( 'kayzart_agent_proposal_checks', $checks );
		}
	}

	public function test_an_agent_reading_counts_as_at_work(): void {
		wp_set_current_user( $this->admin() );
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Read first',
				'html'  => '<p>Hi</p>',
			)
		);
		$page_id = $created['page']['id'];
		delete_option( Agent_Pages::SEEN_OPTION );
		delete_post_meta( $page_id, Agent_Pages::SEEN_META );
		$this->assertNull( Agent_Pages::seen_seconds_ago( $page_id ) );

		Agent_Pages::get_page( $page_id );

		$this->assertLessThan( 5, Agent_Pages::seen_seconds_ago( $page_id ) );
		$this->assertNotSame( '', get_post_meta( $page_id, Agent_Pages::SEEN_META, true ) );
		$started = Agent_Working_Copy::start( $page_id, Agent_Pages::agent_key() );
		$this->assertSame( '', get_post_meta( $started['id'], Agent_Pages::SEEN_META, true ), 'The working copy does not carry it.' );

		delete_post_meta( $page_id, Agent_Pages::SEEN_META );
		Agent_Pages::guidelines( 'normal' );
		$this->assertLessThan( 5, Agent_Pages::seen_seconds_ago( $page_id ), 'Reading the guidelines counts for every page.' );
	}

	/**
	 * Create a page and save successive proposals to it.
	 *
	 * @param array<int,string> $htmls Proposed html, in order.
	 * @return array{0:int,1:array} Page ID and its history items.
	 */
	private function page_with_proposals( array $htmls ): array {
		$created = Agent_Pages::create_page(
			array(
				'title' => 'Proposals',
				'html'  => '<p>One</p>',
			)
		);
		$hash    = $created['page']['baseHash'];
		foreach ( $htmls as $html ) {
			$result = Agent_Pages::update_page(
				array(
					'id'       => $created['page']['id'],
					'baseHash' => $hash,
					'html'     => $html,
				)
			);
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
			$hash = $result['page']['baseHash'];
		}
		return array( $created['page']['id'], ( new Ai_Timeline_Store() )->list_for_post( $created['page']['id'] )['items'] );
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
