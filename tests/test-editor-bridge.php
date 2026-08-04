<?php
/**
 * Editor bridge behavior tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Editor_Bridge;
use KayzArt\Post_Type;

class Test_Editor_Bridge extends WP_UnitTestCase {
	private array $original_get = array();
	private array $original_post = array();

	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$this->original_get = $_GET;
		$this->original_post = $_POST;
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		$_POST = $this->original_post;
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
		$this->reset_assets();
		parent::tearDown();
	}

	public function test_resolve_post_id_uses_global_post_with_edit_permission(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id          = $this->create_enabled_post( Post_Type::POST_TYPE );
		$GLOBALS['post']  = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $GLOBALS['post'] );
		$this->assertSame( $post_id, $this->invoke_private_int_method( 'resolve_post_id' ) );
	}

	public function test_resolve_post_id_ignores_request_post_fallback(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$post_id      = $this->create_enabled_post( Post_Type::POST_TYPE );
		$_GET['post'] = (string) $post_id;

		$this->assertSame( 0, $this->invoke_private_int_method( 'resolve_post_id' ) );
	}

	public function test_enqueue_classic_assets_enqueues_only_for_kayzart_classic_editor(): void {
		$post_id         = $this->create_enabled_post( Post_Type::POST_TYPE );
		$GLOBALS['post'] = get_post( $post_id );

		set_current_screen( 'post' );
		$screen                  = get_current_screen();
		$screen->post_type       = Post_Type::POST_TYPE;
		$screen->is_block_editor = false;

		Editor_Bridge::enqueue_classic_assets( 'post.php' );

		$this->assertTrue( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Editor_Bridge::STYLE_HANDLE, 'enqueued' ) );

		$this->reset_assets();
		Editor_Bridge::enqueue_classic_assets( 'edit.php' );
		$this->assertFalse( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_enqueue_block_assets_runs_only_for_kayzart_screen(): void {
		$post_id         = $this->create_enabled_post( Post_Type::POST_TYPE );
		$GLOBALS['post'] = get_post( $post_id );

		set_current_screen( 'post' );
		$screen            = get_current_screen();
		$screen->post_type = Post_Type::POST_TYPE;

		Editor_Bridge::enqueue_block_assets();
		$this->assertTrue( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Editor_Bridge::STYLE_HANDLE, 'enqueued' ) );

		$this->reset_assets();
		$screen->post_type = 'post';
		Editor_Bridge::enqueue_block_assets();
		$this->assertFalse( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_enqueue_block_assets_runs_for_enabled_marked_page(): void {
		$page_id = $this->create_enabled_post( Post_Type::PAGE_TYPE );
		$GLOBALS['post'] = get_post( $page_id );

		set_current_screen( 'post' );
		$screen            = get_current_screen();
		$screen->post_type = Post_Type::PAGE_TYPE;

		Editor_Bridge::enqueue_block_assets();
		$this->assertTrue( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Editor_Bridge::STYLE_HANDLE, 'enqueued' ) );

		$this->reset_assets();
		update_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES, array( 'post' ) );
		Editor_Bridge::enqueue_block_assets();
		$this->assertFalse( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
	}

	public function test_enqueue_block_assets_skips_convertible_unmarked_page(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::PAGE_TYPE,
				'post_author' => $admin_id,
			)
		);
		$GLOBALS['post'] = get_post( $page_id );

		set_current_screen( 'post' );
		$screen            = get_current_screen();
		$screen->post_type = Post_Type::PAGE_TYPE;

		Editor_Bridge::enqueue_block_assets();
		$this->assertFalse( wp_script_is( Editor_Bridge::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Editor_Bridge::STYLE_HANDLE, 'enqueued' ) );
	}

	public function test_enqueue_assets_sets_nonce_protected_action_url(): void {
		$post_id         = $this->create_enabled_post( Post_Type::POST_TYPE );
		$GLOBALS['post'] = get_post( $post_id );

		set_current_screen( 'post' );
		$screen                  = get_current_screen();
		$screen->post_type       = Post_Type::POST_TYPE;
		$screen->is_block_editor = false;

		Editor_Bridge::enqueue_classic_assets( 'post.php' );

		$scripts    = wp_scripts();
		$registered = $scripts->registered[ Editor_Bridge::SCRIPT_HANDLE ] ?? null;
		$this->assertNotNull( $registered, 'Bridge script should be registered.' );
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$this->assertNotEmpty( $before_inline, 'Bridge data should be injected as inline script.' );

		$inline = implode( "\n", $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART_EDITOR = (.+);/', $inline );

		preg_match( '/window\\.KAYZART_EDITOR = (.+);/', $inline, $matches );
		$this->assertArrayHasKey( 1, $matches );

		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data );
		$this->assertIsString( $data['actionUrl'] ?? null );

		$parts = wp_parse_url( (string) $data['actionUrl'] );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		$this->assertSame( 'kayzart', $query['action'] ?? '' );
		$this->assertNotEmpty( $query['_wpnonce'] ?? '' );
		$this->assertTrue( (bool) ( $data['isManaged'] ?? false ) );
		$this->assertFalse( (bool) ( $data['canConvert'] ?? true ) );
		$this->assertArrayNotHasKey( 'convertUrl', $data );
		$this->assertNotEmpty( $data['viewUrl'] ?? '' );
		$this->assertSame( 'Managed by Kayzart', $data['labels']['eyebrow'] ?? '' );

		$preview_parts = wp_parse_url( (string) ( $data['previewUrl'] ?? '' ) );
		$preview_query = array();
		if ( ! empty( $preview_parts['query'] ) ) {
			parse_str( (string) $preview_parts['query'], $preview_query );
		}
		$this->assertSame( '1', (string) ( $preview_query['kayzart_preview'] ?? '' ) );
		$this->assertSame( (string) $post_id, (string) ( $preview_query['post_id'] ?? '' ) );
		$this->assertSame( 'wordpress_editor', $preview_query['kayzart_preview_context'] ?? '' );
		$this->assertNotEmpty( $preview_query['token'] ?? '' );
	}

	public function test_core_rest_guard_preserves_managed_content(): void {
		$post_id = $this->create_enabled_post( Post_Type::PAGE_TYPE );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Stored Kayzart HTML',
			)
		);
		$prepared               = new stdClass();
		$prepared->post_content = 'Gutenberg replacement';
		$request                = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_param( 'id', $post_id );

		$result = Editor_Bridge::preserve_managed_content( $prepared, $request );

		$this->assertSame( 'Stored Kayzart HTML', $result->post_content );
	}

	public function test_core_rest_guard_leaves_unmanaged_content_unchanged(): void {
		$post_id                 = $this->create_post( Post_Type::PAGE_TYPE );
		$prepared               = new stdClass();
		$prepared->post_content = 'Gutenberg replacement';
		$request                = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_param( 'id', $post_id );

		$result = Editor_Bridge::preserve_managed_content( $prepared, $request );

		$this->assertSame( 'Gutenberg replacement', $result->post_content );
	}

	public function test_core_rest_update_saves_title_but_not_managed_content(): void {
		$post_id = $this->create_enabled_post( Post_Type::PAGE_TYPE );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Before title',
				'post_content' => 'Stored Kayzart HTML',
			)
		);
		Editor_Bridge::register_rest_content_guards();

		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'title', 'Updated title' );
		$request->set_param( 'content', 'Gutenberg replacement' );
		$response = rest_do_request( $request );
		$post     = get_post( $post_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Updated title', $post->post_title );
		$this->assertSame( 'Stored Kayzart HTML', $post->post_content );
	}

	public function test_core_rest_guard_preserves_managed_content_after_post_type_is_disabled(): void {
		$post_type      = 'kz_review_type';
		$original_types = get_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES, null );
		register_post_type(
			$post_type,
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		try {
			update_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES, array( Post_Type::PAGE_TYPE, $post_type ) );
			$post_id = $this->create_enabled_post( $post_type );
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => 'Before title',
					'post_content' => 'Stored Kayzart HTML',
				)
			);

			update_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES, array( Post_Type::PAGE_TYPE ) );
			$this->assertTrue( Post_Type::is_kayzart_post( $post_id ) );
			$this->assertFalse( Post_Type::is_editor_enabled_post( $post_id ) );
			Editor_Bridge::register_rest_content_guards();

			$request = new WP_REST_Request( 'POST', '/wp/v2/' . $post_type . '/' . $post_id );
			$request->set_param( 'id', $post_id );
			$request->set_param( 'title', 'Updated title' );
			$request->set_param( 'content', 'Gutenberg replacement' );
			$controller = new WP_REST_Posts_Controller( $post_type );
			$response   = $controller->update_item( $request );
			$post       = get_post( $post_id );

			$this->assertNotWPError( $response );
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 200, $response->get_status() );
			$this->assertInstanceOf( WP_Post::class, $post );
			$this->assertSame( 'Updated title', $post->post_title );
			$this->assertSame( 'Stored Kayzart HTML', $post->post_content );
		} finally {
			remove_filter( 'rest_pre_insert_' . $post_type, array( Editor_Bridge::class, 'preserve_managed_content' ), 20 );
			unregister_post_type( $post_type );
			if ( null === $original_types ) {
				delete_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES );
			} else {
				update_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES, $original_types );
			}
		}
	}

	public function test_core_rest_guard_is_not_registered_for_non_rest_post_types(): void {
		$post_type = 'kz_nonrest_type';
		register_post_type(
			$post_type,
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_rest' => false,
			)
		);

		try {
			Editor_Bridge::register_rest_content_guards();
			$this->assertFalse( has_filter( 'rest_pre_insert_' . $post_type, array( Editor_Bridge::class, 'preserve_managed_content' ) ) );
		} finally {
			unregister_post_type( $post_type );
		}
	}

	public function test_classic_editor_update_saves_title_but_not_managed_content(): void {
		$post_id        = $this->create_enabled_post( Post_Type::PAGE_TYPE );
		$stored_content = '<script>const digits = /\d+/; const path = "C:\\\\temp"; const value = "\"quoted\"";</script>';
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Before title',
				'post_content' => wp_slash( $stored_content ),
			)
		);
		set_current_screen( 'post' );
		$_POST['action'] = 'editpost';

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_title'   => 'Updated title',
				'post_content' => wp_slash( 'Classic Editor replacement' ),
			)
		);
		$post = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( 'Updated title', $post->post_title );
		$this->assertSame( $stored_content, $post->post_content );
	}

	public function test_classic_editor_guard_leaves_unmanaged_content_unchanged(): void {
		$post_id = $this->create_post( Post_Type::PAGE_TYPE );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Original content',
			)
		);
		set_current_screen( 'post' );
		$_POST['action'] = 'editpost';

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Classic Editor replacement',
			)
		);

		$this->assertSame( 'Classic Editor replacement', get_post( $post_id )->post_content );
	}

	public function test_classic_editor_guard_ignores_other_update_actions(): void {
		$post_id = $this->create_enabled_post( Post_Type::PAGE_TYPE );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Stored Kayzart HTML',
			)
		);
		set_current_screen( 'post' );
		$_POST['action'] = 'kayzart_save';

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Kayzart REST replacement',
			)
		);

		$this->assertSame( 'Kayzart REST replacement', get_post( $post_id )->post_content );
	}

	private function create_post( string $post_type ): int {
		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $author_id );
		return (int) self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
	}

	private function create_enabled_post( string $post_type ): int {
		$post_id = $this->create_post( $post_type );
		Post_Type::enable_for_post( $post_id );

		return $post_id;
	}

	private function invoke_private_int_method( string $method_name ): int {
		$method = new ReflectionMethod( Editor_Bridge::class, $method_name );
		$method->setAccessible( true );
		return (int) $method->invoke( null );
	}

	private function get_inline_bridge_data(): array {
		$scripts    = wp_scripts();
		$registered = $scripts->registered[ Editor_Bridge::SCRIPT_HANDLE ] ?? null;
		$this->assertNotNull( $registered, 'Bridge script should be registered.' );

		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$inline        = implode( "\n", (array) $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART_EDITOR = (.+);/', $inline );
		preg_match( '/window\\.KAYZART_EDITOR = (.+);/', $inline, $matches );
		$this->assertNotEmpty( $matches[1] ?? '' );

		$data = json_decode( $matches[1], true );
		$this->assertIsArray( $data );
		return $data;
	}

	private function reset_assets(): void {
		wp_dequeue_script( Editor_Bridge::SCRIPT_HANDLE );
		wp_deregister_script( Editor_Bridge::SCRIPT_HANDLE );
		wp_dequeue_style( Editor_Bridge::STYLE_HANDLE );
		wp_deregister_style( Editor_Bridge::STYLE_HANDLE );
	}
}
