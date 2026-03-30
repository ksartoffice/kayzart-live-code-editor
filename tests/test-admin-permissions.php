<?php
/**
 * Admin route permission tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Post_Type;

class KayzArt_Admin_Die_Exception extends Exception {
}

class KayzArt_Admin_Redirect_Exception extends Exception {
	public string $location;

	public function __construct( string $location ) {
		$this->location = $location;
		parent::__construct( $location );
	}
}

class Test_Admin_Permissions extends WP_UnitTestCase {
	private string $wp_die_message = '';

	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
		unset( $GLOBALS['typenow'] );
		parent::tearDown();
	}

	public function test_action_redirect_denies_user_without_edit_permission(): void {
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id       = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $subscriber_id );

		$original_get       = $_GET;
		$_GET['post_id']    = (string) $post_id;
		$_GET['_wpnonce']   = wp_create_nonce( Admin::REDIRECT_NONCE_ACTION );

		$message = $this->capture_wp_die(
			function () {
				Admin::action_redirect();
			}
		);

		$_GET = $original_get;

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
	}

	public function test_action_redirect_requires_valid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$original_get    = $_GET;
		$_GET['post_id'] = (string) $post_id;

		$message = $this->capture_wp_die(
			function () {
				Admin::action_redirect();
			}
		);

		$_GET = $original_get;

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor' ), $message );
	}

	public function test_action_redirect_redirects_with_valid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );

		$original_get = $_GET;
		$_GET         = array(
			'post_id'  => (string) $post_id,
			'_wpnonce' => wp_create_nonce( Admin::REDIRECT_NONCE_ACTION ),
		);

		$location = $this->capture_redirect(
			function () {
				Admin::action_redirect();
			}
		);

		$_GET = $original_get;

		$this->assertSame( Post_Type::get_editor_url( $post_id ), $location );
	}

	public function test_maybe_redirect_new_post_denies_user_without_create_posts(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $subscriber_id );
		$this->set_new_post_screen_context( Post_Type::POST_TYPE );

		$original_get         = $_GET;
		$_GET['_wpnonce']     = wp_create_nonce( Admin::NEW_POST_NONCE_ACTION );

		$message = $this->capture_wp_die(
			function () {
				Admin::maybe_redirect_new_post();
			}
		);

		$_GET = $original_get;

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
	}

	public function test_maybe_redirect_new_post_without_nonce_is_denied(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$this->set_new_post_screen_context( Post_Type::POST_TYPE );

		$before       = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array();

		$message = $this->capture_wp_die(
			function () {
				Admin::maybe_redirect_new_post();
			}
		);

		$_GET = $original_get;
		$after = $this->get_kayzart_post_ids();

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
		$this->assertSame( $before, $after, 'load-post-new should not create drafts directly.' );
	}

	public function test_maybe_redirect_new_post_rejects_invalid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$this->set_new_post_screen_context( Post_Type::POST_TYPE );

		$before       = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array(
			'_wpnonce'  => 'invalid-nonce',
		);

		$message = $this->capture_wp_die(
			function () {
				Admin::maybe_redirect_new_post();
			}
		);

		$_GET = $original_get;
		$after = $this->get_kayzart_post_ids();

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
		$this->assertSame( $before, $after, 'Invalid nonce access must not create drafts.' );
	}

	public function test_maybe_redirect_new_post_valid_nonce_redirects_to_action_without_creating_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$this->set_new_post_screen_context( Post_Type::POST_TYPE );

		$before       = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array(
			'_wpnonce'  => wp_create_nonce( Admin::NEW_POST_NONCE_ACTION ),
		);

		$location = $this->capture_redirect(
			function () {
				Admin::maybe_redirect_new_post();
			}
		);

		$_GET = $original_get;
		$after = $this->get_kayzart_post_ids();

		$this->assertStringNotContainsString( '&amp;', $location );
		$parts = wp_parse_url( $location );
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}

		$this->assertSame( $before, $after, 'load-post-new should not create drafts directly.' );
		$this->assertSame( Admin::NEW_POST_ACTION, $query['action'] ?? '' );
		$this->assertSame( Post_Type::POST_TYPE, $query['post_type'] ?? '' );
		$this->assertNotEmpty( $query['_wpnonce'] ?? '' );
	}

	public function test_maybe_redirect_new_post_ignores_non_kayzart_context(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->set_new_post_screen_context( 'post' );

		$before       = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array();

		Admin::maybe_redirect_new_post();

		$_GET = $original_get;
		$after = $this->get_kayzart_post_ids();

		$this->assertSame( $before, $after, 'Non-KayzArt post-new context should be ignored.' );
	}

	public function test_action_create_new_post_requires_valid_nonce(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		$before       = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array(
			'post_type' => Post_Type::POST_TYPE,
		);

		$message = $this->capture_wp_die(
			function () {
				Admin::action_create_new_post();
			}
		);

		$_GET = $original_get;
		$after = $this->get_kayzart_post_ids();

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
		$this->assertSame( $before, $after, 'Action without nonce must not create drafts.' );
	}

	public function test_action_create_new_post_creates_draft_and_redirects_to_editor(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );

		$before_ids   = $this->get_kayzart_post_ids();
		$original_get = $_GET;
		$_GET         = array(
			'post_type' => Post_Type::POST_TYPE,
			'_wpnonce'  => wp_create_nonce( Admin::NEW_POST_NONCE_ACTION ),
		);

		$location = $this->capture_redirect(
			function () {
				Admin::action_create_new_post();
			}
		);

		$_GET = $original_get;
		$after_ids = $this->get_kayzart_post_ids();
		$created   = array_values( array_diff( $after_ids, $before_ids ) );

		$this->assertCount( 1, $created, 'Exactly one draft should be created.' );
		$created_id = (int) $created[0];
		$created    = get_post( $created_id );

		$this->assertInstanceOf( WP_Post::class, $created );
		$this->assertSame( Post_Type::POST_TYPE, $created->post_type );
		$this->assertSame( 'draft', $created->post_status );
		$this->assertSame( $admin_id, (int) $created->post_author );
		$this->assertSame( Post_Type::get_editor_url( $created_id ), $location );
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

	private function get_kayzart_post_ids(): array {
		return get_posts(
			array(
				'post_type'              => Post_Type::POST_TYPE,
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

	private function set_new_post_screen_context( string $post_type ): void {
		set_current_screen( 'post' );
		$screen = get_current_screen();
		if ( $screen instanceof WP_Screen ) {
			$screen->post_type = $post_type;
		}
		$GLOBALS['typenow'] = $post_type;
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
			$this->fail( 'Expected redirect to be called.' );
		} catch ( KayzArt_Admin_Redirect_Exception $e ) {
			return $e->location;
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter );
		}
	}
}
