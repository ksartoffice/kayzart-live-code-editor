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

	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$this->original_get = $_GET;
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		unset( $GLOBALS['post'] );
		set_current_screen( 'front' );
		$this->reset_assets();
		parent::tearDown();
	}

	public function test_maybe_mark_setup_required_sets_meta_for_new_kayzart_post(): void {
		$post_id = $this->create_post( Post_Type::POST_TYPE );
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );

		delete_post_meta( $post_id, '_kayzart_setup_required' );
		Editor_Bridge::maybe_mark_setup_required( $post_id, $post, false );

		$this->assertSame( '1', get_post_meta( $post_id, '_kayzart_setup_required', true ) );
	}

	public function test_maybe_mark_setup_required_skips_updates_and_non_kayzart_posts(): void {
		$kayzart_id = $this->create_post( Post_Type::POST_TYPE );
		$kayzart    = get_post( $kayzart_id );
		$this->assertInstanceOf( WP_Post::class, $kayzart );

		delete_post_meta( $kayzart_id, '_kayzart_setup_required' );
		Editor_Bridge::maybe_mark_setup_required( $kayzart_id, $kayzart, true );
		$this->assertSame( '', get_post_meta( $kayzart_id, '_kayzart_setup_required', true ) );

		$normal_id = $this->create_post( 'post' );
		$normal    = get_post( $normal_id );
		$this->assertInstanceOf( WP_Post::class, $normal );

		delete_post_meta( $normal_id, '_kayzart_setup_required' );
		Editor_Bridge::maybe_mark_setup_required( $normal_id, $normal, false );
		$this->assertSame( '', get_post_meta( $normal_id, '_kayzart_setup_required', true ) );
	}

	public function test_resolve_post_id_uses_global_post_with_edit_permission(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$first_id = $this->create_post( Post_Type::POST_TYPE );
		$_GET['post'] = (string) $first_id;
		$this->assertSame( 0, $this->invoke_private_int_method( 'resolve_post_id' ) );

		unset( $_GET['post'] );

		$second_id        = $this->create_post( Post_Type::POST_TYPE );
		$GLOBALS['post']  = get_post( $second_id );
		$this->assertInstanceOf( WP_Post::class, $GLOBALS['post'] );
		$this->assertSame( $second_id, $this->invoke_private_int_method( 'resolve_post_id' ) );
	}

	public function test_enqueue_classic_assets_enqueues_only_for_kayzart_classic_editor(): void {
		$post_id = $this->create_post( Post_Type::POST_TYPE );
		$_GET['post'] = (string) $post_id;

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

	public function test_enqueue_assets_sets_nonce_protected_action_url(): void {
		$post_id = $this->create_post( Post_Type::POST_TYPE );
		$_GET['post'] = (string) $post_id;

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
	}

	private function create_post( string $post_type ): int {
		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		return (int) self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_author' => $author_id,
			)
		);
	}

	private function invoke_private_int_method( string $method_name ): int {
		$method = new ReflectionMethod( Editor_Bridge::class, $method_name );
		$method->setAccessible( true );
		return (int) $method->invoke( null );
	}

	private function reset_assets(): void {
		wp_dequeue_script( Editor_Bridge::SCRIPT_HANDLE );
		wp_deregister_script( Editor_Bridge::SCRIPT_HANDLE );
		wp_dequeue_style( Editor_Bridge::STYLE_HANDLE );
		wp_deregister_style( Editor_Bridge::STYLE_HANDLE );
	}
}
