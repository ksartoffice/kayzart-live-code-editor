<?php
/**
 * Preview/nonce tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;
use KayzArt\Preview;

class KayzArt_Die_Exception extends Exception {
}

class Test_Preview extends WP_UnitTestCase {
	private string $wp_die_message = '';
	private ?WP_Query $original_wp_query = null;
	private ?WP_Query $original_wp_the_query = null;

	protected function setUp(): void {
		parent::setUp();
		global $wp_query, $wp_the_query;
		$this->original_wp_query     = $wp_query ?? null;
		$this->original_wp_the_query = $wp_the_query ?? null;
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		$this->reset_preview_state();
		unset( $GLOBALS['post'] );
		$this->restore_query_globals();
		parent::tearDown();
	}

	public function test_preview_requires_post_id(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $admin_id );
		$this->set_preview_query_vars( null, 'token' );

		$message = $this->capture_wp_die(
			function () {
				Preview::maybe_handle_preview();
			}
		);

		$this->assertStringContainsString( __( 'post_id is required.', 'kayzart-live-code-editor'), $message );
	}

	public function test_preview_denies_invalid_token(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$this->set_preview_query_vars( $post_id, 'invalid' );

		$message = $this->capture_wp_die(
			function () {
				Preview::maybe_handle_preview();
			}
		);

		$this->assertStringContainsString( __( 'Invalid preview token.', 'kayzart-live-code-editor'), $message );
	}

	public function test_preview_denies_user_without_edit_permission(): void {
		$author_id     = self::factory()->user->create( array( 'role' => 'author' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$post_id       = $this->create_kayzart_post( $author_id );

		wp_set_current_user( $subscriber_id );
		$token = wp_create_nonce( 'kayzart_preview_' . $post_id );
		$this->set_preview_query_vars( $post_id, $token );

		$message = $this->capture_wp_die(
			function () {
				Preview::maybe_handle_preview();
			}
		);

		$this->assertStringContainsString( __( 'Permission denied.', 'kayzart-live-code-editor'), $message );
	}

	public function test_preview_denies_non_kayzart_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $admin_id,
			)
		);

		wp_set_current_user( $admin_id );
		$token = wp_create_nonce( 'kayzart_preview_' . $post_id );
		$this->set_preview_query_vars( (int) $post_id, $token );

		$message = $this->capture_wp_die(
			function () {
				Preview::maybe_handle_preview();
			}
		);

		$this->assertStringContainsString( __( 'Invalid post type.', 'kayzart-live-code-editor'), $message );
	}

	public function test_preview_registers_nocache_header_filter_for_valid_request(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		wp_set_current_user( $admin_id );
		$token = wp_create_nonce( 'kayzart_preview_' . $post_id );
		$this->set_preview_query_vars( $post_id, $token );

		Preview::maybe_handle_preview();

		$this->assertNotFalse(
			has_filter( 'nocache_headers', array( Preview::class, 'filter_nocache_headers' ) )
		);
	}

	public function test_preview_forces_expected_nocache_headers(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$headers = apply_filters( 'nocache_headers', array() );

		$this->assertSame( 'no-cache, must-revalidate, max-age=0, no-store, private', $headers['Cache-Control'] ?? '' );
		$this->assertSame( 'no-cache', $headers['Pragma'] ?? '' );
		$this->assertSame( 'Wed, 11 Jan 1984 05:00:00 GMT', $headers['Expires'] ?? '' );
	}

	public function test_preview_headers_include_frame_ancestors_policy(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );

		$headers = apply_filters( 'wp_headers', array() );

		$this->assertArrayHasKey( 'Content-Security-Policy', $headers );
		$this->assertStringContainsString( "frame-ancestors 'self'", $headers['Content-Security-Policy'] );
		$this->assertStringContainsString(
			$this->build_origin( admin_url() ),
			$headers['Content-Security-Policy']
		);
	}

	public function test_enqueue_assets_sets_strict_allowed_origin(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		Preview::enqueue_assets();

		$scripts    = wp_scripts();
		$registered = $scripts->registered['kayzart-preview'] ?? null;
		$this->assertNotNull( $registered, 'Preview script should be registered.' );
		$before_inline = is_object( $registered ) && isset( $registered->extra['before'] ) ? $registered->extra['before'] : array();
		$this->assertNotEmpty( $before_inline, 'Preview script should include inline payload.' );

		$inline = implode( "\n", $before_inline );
		$this->assertMatchesRegularExpression( '/window\\.KAYZART_PREVIEW = (.+);/', $inline );

		preg_match( '/window\\.KAYZART_PREVIEW = (.+);/', $inline, $matches );
		$this->assertArrayHasKey( 1, $matches );
		$payload = json_decode( $matches[1], true );
		$this->assertIsArray( $payload );

		$allowed_origin = (string) ( $payload['allowedOrigin'] ?? '' );
		$this->assertSame( $this->build_origin( admin_url() ), $allowed_origin );
		$parts = wp_parse_url( $allowed_origin );
		$this->assertTrue( empty( $parts['path'] ), 'allowedOrigin should not include a path.' );
		$this->assertTrue( empty( $parts['query'] ), 'allowedOrigin should not include a query string.' );
		$this->assertTrue( empty( $parts['fragment'] ), 'allowedOrigin should not include a fragment.' );
	}

	public function test_preview_filter_registered_with_high_priority(): void {
		$priority = has_filter( 'the_content', array( Preview::class, 'filter_content' ) );
		if ( false === $priority ) {
			Preview::init();
			$priority = has_filter( 'the_content', array( Preview::class, 'filter_content' ) );
		}

		$this->assertSame( 999999, $priority );
	}

	public function test_filter_content_skips_target_post_outside_loop(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );

		$actual = Preview::filter_content( '<p>Hello</p>' );

		$this->assertSame( '<p>Hello</p>', $actual );
	}

	public function test_filter_content_wraps_target_post_in_main_loop(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_main_loop_context( true );

		$actual = Preview::filter_content( '<p>Hello</p>' );

		$this->assertSame( $this->wrap_with_markers( '<p>Hello</p>', $post_id ), $actual );
	}

	public function test_filter_content_skips_non_main_query_loop(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_secondary_loop_context();

		$actual = Preview::filter_content( '<p>Hello</p>' );

		$this->assertSame( '<p>Hello</p>', $actual );
	}

	public function test_filter_content_waits_for_main_loop_when_called_outside_loop_first(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );

		$outside_loop = Preview::filter_content( '<p>Outside</p>' );
		$this->set_main_loop_context( true );
		$inside_loop = Preview::filter_content( '<p>Inside</p>' );

		$this->assertSame( '<p>Outside</p>', $outside_loop );
		$this->assertSame( $this->wrap_with_markers( '<p>Inside</p>', $post_id ), $inside_loop );
	}

	public function test_filter_content_skips_non_target_post(): void {
		$admin_id        = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$target_post_id  = $this->create_kayzart_post( $admin_id );
		$another_post_id = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $target_post_id, $admin_id );
		$this->set_global_post( $another_post_id );
		$this->set_main_loop_context( true );

		$actual = Preview::filter_content( '<p>Other</p>' );

		$this->assertSame( '<p>Other</p>', $actual );
	}

	public function test_filter_content_wraps_each_main_loop_call_without_shared_state(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_main_loop_context( true );

		$first  = Preview::filter_content( '<p>First</p>' );
		$second = Preview::filter_content( '<p>Second</p>' );

		$this->assertSame( $this->wrap_with_markers( '<p>First</p>', $post_id ), $first );
		$this->assertSame( $this->wrap_with_markers( '<p>Second</p>', $post_id ), $second );
	}

	public function test_filter_content_respects_existing_markers(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_main_loop_context( true );

		$already_marked = $this->wrap_with_markers( '<p>Marked</p>', $post_id );
		$first          = Preview::filter_content( $already_marked );
		$second         = Preview::filter_content( '<p>Later</p>' );

		$this->assertSame( $already_marked, $first );
		$this->assertSame( $this->wrap_with_markers( '<p>Later</p>', $post_id ), $second );
	}

	public function test_filter_content_skips_nested_the_content_calls(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_main_loop_context( true );

		$inner_had_markers = null;
		$nested_filter     = static function ( string $content ): string {
			if ( false === strpos( $content, '[kayzart-nested]' ) ) {
				return $content;
			}

			apply_filters( 'the_content', '<p>Inner</p>' );
			return str_replace( '[kayzart-nested]', '', $content );
		};
		$probe_filter      = static function ( string $content ) use ( &$inner_had_markers ): string {
			global $wp_current_filter;
			$depth = 0;
			foreach ( (array) $wp_current_filter as $hook_name ) {
				if ( 'the_content' === (string) $hook_name ) {
					++$depth;
				}
			}

			if ( 2 === $depth ) {
				$inner_had_markers = false !== strpos( $content, 'data-kayzart-marker="start"' );
			}

			return $content;
		};

		add_filter( 'the_content', $nested_filter, 12 );
		add_filter( 'the_content', $probe_filter, 1000000 );
		try {
			$actual = apply_filters( 'the_content', '[kayzart-nested]<p>Outer</p>' );
		} finally {
			remove_filter( 'the_content', $nested_filter, 12 );
			remove_filter( 'the_content', $probe_filter, 1000000 );
		}

		$this->assertFalse( $inner_had_markers ?? true );
		$this->assertSame( $this->wrap_with_markers( '<p>Outer</p>', $post_id ), $actual );
	}

	public function test_filter_content_wraps_when_markers_belong_to_different_post(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id );

		$this->start_preview_request( $post_id, $admin_id );
		$this->set_global_post( $post_id );
		$this->set_main_loop_context( true );

		$other_post_marked = $this->wrap_with_markers( '<p>Marked</p>', $post_id + 1 );

		$actual = Preview::filter_content( $other_post_marked );

		$this->assertSame( $this->wrap_with_markers( $other_post_marked, $post_id ), $actual );
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

	private function set_preview_query_vars( ?int $post_id, ?string $token ): void {
		global $wp_query, $wp_the_query;
		if ( ! $wp_query ) {
			$wp_query = new WP_Query();
		}
		$wp_the_query = $wp_query;

		$wp_query->set( 'kayzart_preview', '1' );
		$wp_query->set( 'post_id', $post_id ? (string) $post_id : '' );
		$wp_query->set( 'token', $token ?? '' );
	}

	private function start_preview_request( int $post_id, int $user_id ): void {
		wp_set_current_user( $user_id );
		$token = wp_create_nonce( 'kayzart_preview_' . $post_id );
		$this->set_preview_query_vars( $post_id, $token );
		Preview::maybe_handle_preview();
	}

	private function set_global_post( int $post_id ): void {
		$post = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$GLOBALS['post'] = $post;
	}

	private function set_main_loop_context( bool $in_loop ): void {
		global $wp_query, $wp_the_query;
		if ( ! $wp_query ) {
			$wp_query = new WP_Query();
		}
		$wp_the_query          = $wp_query;
		$wp_query->in_the_loop = $in_loop;
	}

	private function set_secondary_loop_context(): void {
		global $wp_query, $wp_the_query;
		$wp_the_query          = new WP_Query();
		$wp_query              = new WP_Query();
		$wp_query->in_the_loop = true;
	}

	private function reset_preview_state(): void {
		$state = array(
			'post_id'    => null,
			'is_preview' => false,
		);

		foreach ( $state as $property_name => $value ) {
			$property = new ReflectionProperty( Preview::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		remove_filter( 'wp_headers', array( Preview::class, 'filter_preview_headers' ) );
		remove_filter( 'nocache_headers', array( Preview::class, 'filter_nocache_headers' ) );
	}

	private function restore_query_globals(): void {
		global $wp_query, $wp_the_query;
		if ( null !== $this->original_wp_query ) {
			$wp_query = $this->original_wp_query;
		} else {
			unset( $wp_query );
		}

		if ( null !== $this->original_wp_the_query ) {
			$wp_the_query = $this->original_wp_the_query;
		} else {
			unset( $wp_the_query );
		}
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
		throw new KayzArt_Die_Exception();
	}

	private function capture_wp_die( callable $callback ): string {
		$this->wp_die_message = '';
		add_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );

		try {
			$callback();
			$this->fail( 'Expected wp_die to be called.' );
		} catch ( KayzArt_Die_Exception $e ) {
			// Expected.
		} finally {
			remove_filter( 'wp_die_handler', array( $this, 'provide_wp_die_handler' ) );
		}

		return $this->wp_die_message;
	}

	private function wrap_with_markers( string $content, int $post_id ): string {
		return '<span data-kayzart-marker="start" data-kayzart-post-id="' . $post_id . '" aria-hidden="true" hidden></span>'
			. $content
			. '<span data-kayzart-marker="end" data-kayzart-post-id="' . $post_id . '" aria-hidden="true" hidden></span>';
	}

	private function build_origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . (string) $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (string) $parts['port'];
		}
		return $origin;
	}
}


