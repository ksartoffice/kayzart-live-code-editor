<?php
/**
 * Tests for the Kayzart "Duplicate landing page" admin action.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Post_Type;

class Test_Admin_Duplicate extends WP_UnitTestCase {
	private string $wp_die_message = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_action_duplicate_post_copies_content_and_kayzart_meta_without_setup_flag(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$content = '<section><h1>Hero</h1></section>';
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::PAGE_TYPE,
				'post_status'  => 'publish',
				'post_author'  => $admin_id,
				'post_content' => $content,
			)
		);
		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );
		update_post_meta( $page_id, '_kayzart_css', '.hero{color:red}' );
		update_post_meta( $page_id, '_kayzart_js', 'console.log(1)' );
		update_post_meta( $page_id, '_kayzart_js_mode', 'module' );
		update_post_meta( $page_id, '_kayzart_tailwind', '1' );
		update_post_meta( $page_id, '_kayzart_setup_required', '1' );

		$source_title = get_post( $page_id )->post_title;

		$before_ids   = $this->get_page_ids();
		$original_get = $_GET;
		$_GET         = array(
			'post_id'  => (string) $page_id,
			'_wpnonce' => wp_create_nonce( Admin::DUPLICATE_POST_NONCE_ACTION ),
		);

		$location = $this->capture_redirect(
			function () {
				Admin::action_duplicate_post();
			}
		);

		$_GET      = $original_get;
		$after_ids = $this->get_page_ids();
		$created   = array_values( array_diff( $after_ids, $before_ids ) );

		$this->assertCount( 1, $created, 'Exactly one duplicate draft should be created.' );
		$copy_id = (int) $created[0];
		$copy    = get_post( $copy_id );

		$this->assertInstanceOf( WP_Post::class, $copy );
		$this->assertSame( 'draft', $copy->post_status );
		$this->assertSame( $content, $copy->post_content );
		$this->assertSame(
			sprintf( __( '%s (copy)', 'kayzart-live-code-editor' ), $source_title ),
			$copy->post_title
		);

		$this->assertSame( '1', get_post_meta( $copy_id, Post_Type::ENABLED_META, true ) );
		$this->assertSame( '.hero{color:red}', get_post_meta( $copy_id, '_kayzart_css', true ) );
		$this->assertSame( 'console.log(1)', get_post_meta( $copy_id, '_kayzart_js', true ) );
		$this->assertSame( 'module', get_post_meta( $copy_id, '_kayzart_js_mode', true ) );
		$this->assertSame( '1', get_post_meta( $copy_id, '_kayzart_tailwind', true ) );

		$this->assertSame(
			'',
			get_post_meta( $copy_id, '_kayzart_setup_required', true ),
			'The transient setup flag must not be carried over to the copy.'
		);

		$this->assertStringContainsString( 'kayzart_duplicated=1', $location );
	}

	public function test_action_duplicate_post_requires_valid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$page_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::PAGE_TYPE,
				'post_author' => $admin_id,
			)
		);
		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );

		$before_ids      = $this->get_page_ids();
		$original_get    = $_GET;
		$_GET['post_id'] = (string) $page_id;

		$message = $this->capture_wp_die(
			function () {
				Admin::action_duplicate_post();
			}
		);

		$_GET      = $original_get;
		$after_ids = $this->get_page_ids();

		$this->assertSame( $before_ids, $after_ids, 'Action without a valid nonce must not create a copy.' );
		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor' ), $message );
	}

	public function test_action_duplicate_post_denies_user_without_edit_permission(): void {
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$page_id       = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::PAGE_TYPE,
				'post_author' => $author_id,
			)
		);
		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );

		wp_set_current_user( $subscriber_id );

		$before_ids   = $this->get_page_ids();
		$original_get = $_GET;
		$_GET         = array(
			'post_id'  => (string) $page_id,
			'_wpnonce' => wp_create_nonce( Admin::DUPLICATE_POST_NONCE_ACTION ),
		);

		$message = $this->capture_wp_die(
			function () {
				Admin::action_duplicate_post();
			}
		);

		$_GET      = $original_get;
		$after_ids = $this->get_page_ids();

		$this->assertSame( $before_ids, $after_ids, 'A user without edit permission must not create a copy.' );
		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor' ), $message );
	}

	public function test_row_action_offers_duplicate_for_managed_pages_only(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$unmanaged_id = (int) self::factory()->post->create( array( 'post_type' => Post_Type::PAGE_TYPE ) );
		$unmanaged    = get_post( $unmanaged_id );
		$this->assertInstanceOf( WP_Post::class, $unmanaged );
		$actions = Post_Type::add_kayzart_row_action( array(), $unmanaged );
		$this->assertArrayNotHasKey( 'kayzart_duplicate', $actions );

		$managed_id = (int) self::factory()->post->create( array( 'post_type' => Post_Type::PAGE_TYPE ) );
		update_post_meta( $managed_id, Post_Type::ENABLED_META, '1' );
		$managed = get_post( $managed_id );
		$this->assertInstanceOf( WP_Post::class, $managed );
		$actions = Post_Type::add_kayzart_row_action( array(), $managed );

		$this->assertArrayHasKey( 'kayzart_duplicate', $actions );
		$this->assertStringContainsString( esc_html__( 'Duplicate landing page', 'kayzart-live-code-editor' ), $actions['kayzart_duplicate'] );
		$this->assertStringContainsString( 'action=' . Admin::DUPLICATE_POST_ACTION, $actions['kayzart_duplicate'] );
	}

	private function get_page_ids(): array {
		return get_posts(
			array(
				'post_type'              => Post_Type::PAGE_TYPE,
				'post_status'            => 'any',
				'fields'                 => 'ids',
				'posts_per_page'         => -1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'suppress_filters'       => true,
			)
		);
	}

	public function provide_wp_die_handler( $handler ) {
		return array( $this, 'handle_wp_die' );
	}

	public function handle_wp_die( $message, $title = '', $args = array() ) {
		if ( is_wp_error( $message ) ) {
			$this->wp_die_message = $message->get_error_message();
		} else {
			$this->wp_die_message = (string) $message;
		}
		throw new KayzArt_Admin_Die_Exception();
	}

	private function capture_wp_die( callable $callback ): string {
		$this->wp_die_message = '';
		add_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );

		try {
			$callback();
			$this->fail( 'Expected wp_die to be called.' );
		} catch ( KayzArt_Admin_Die_Exception $e ) {
			// Expected.
		} finally {
			remove_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );
		}

		return $this->wp_die_message;
	}

	private function capture_redirect( callable $callback ): string {
		$redirect_filter = function ( $location ) {
			throw new KayzArt_Admin_Redirect_Exception( (string) $location );
		};
		add_filter( 'wp_redirect', $redirect_filter );

		try {
			$callback();
			$this->fail( 'Expected a redirect to be issued.' );
		} catch ( KayzArt_Admin_Redirect_Exception $e ) {
			return $e->location;
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter );
		}

		return '';
	}
}
