<?php
/**
 * Front-end rendering success tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Frontend;
use KayzArt\Admin;
use KayzArt\Post_Type;

class Test_Frontend_Output extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}

		if ( ! shortcode_exists( 'kayzart' ) ) {
			Frontend::init();
		}

		$this->reset_shortcode_state();
	}

	protected function tearDown(): void {
		$this->reset_shortcode_state();
		delete_option( Admin::OPTION_SHORTCODE_ALLOWLIST );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_filter_content_wraps_shadow_dom_with_assets(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_shadow_dom', '1' );
		update_post_meta( $post_id, '_kayzart_css', 'body{color:red;}' );
		update_post_meta( $post_id, '_kayzart_js', 'console.log("x");' );
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/app.css' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/app.js' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		$output            = Frontend::filter_content( (string) $post->post_content );
		$this->restore_query( $original_wp_query );

		$this->assertStringContainsString( '<kayzart-output data-post-id="' . $post_id . '">', $output );
		$this->assertStringContainsString( '<template shadowrootmode="open">', $output );
		$this->assertStringContainsString( 'https://example.com/app.css', $output );
		$this->assertStringContainsString( 'body{color:red;}', $output );
		$this->assertStringContainsString( '<p>KayzArt content</p>', $output );
		$this->assertStringNotContainsString( '<script src="https://example.com/app.js"></script>', $output );
		$this->assertStringNotContainsString( '<script id="cd-script">console.log("x");</script>', $output );
		$this->assertStringContainsString( 'data-kayzart-js="1"', $output );
		$this->assertStringContainsString( 'data-kayzart-js-mode="classic"', $output );
		$this->assertStringContainsString( '</template>', $output );
		$this->assertStringContainsString( '</kayzart-output>', $output );
	}

	public function test_shortcode_renders_shadow_dom_with_unique_ids(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shadow_dom', '1' );
		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		update_post_meta( $post_id, '_kayzart_css', 'body{background:#000;}' );
		update_post_meta( $post_id, '_kayzart_js', 'console.log("shortcode");' );
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/shortcode.css' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/shortcode.js' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		wp_set_current_user( $admin_id );

		$first  = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		$second = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertStringContainsString( '<kayzart-output data-post-id="' . $post_id . '">', $first );
		$this->assertStringContainsString( '<kayzart-output data-post-id="' . $post_id . '">', $second );
		$this->assertStringContainsString( 'https://example.com/shortcode.css', $first );
		$this->assertStringContainsString( 'https://example.com/shortcode.css', $second );
		$this->assertStringContainsString( 'body{background:#000;}', $first );
		$this->assertStringContainsString( 'body{background:#000;}', $second );
		$this->assertStringContainsString( 'id="cd-script-data-' . $post_id . '-1"', $first );
		$this->assertStringContainsString( 'id="cd-script-data-' . $post_id . '-2"', $second );
		$this->assertStringNotContainsString( '<script src="https://example.com/shortcode.js"></script>', $first );
		$this->assertStringNotContainsString( '<script src="https://example.com/shortcode.js"></script>', $second );
		$this->assertTrue( wp_script_is( 'kayzart-shadow-runtime', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'kayzart-ext-' . $post_id . '-0', 'enqueued' ) );
	}

	public function test_shortcode_non_shadow_outputs_payload_and_enqueues_runtime(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		update_post_meta( $post_id, '_kayzart_css', 'body{font-size:16px;}' );
		update_post_meta( $post_id, '_kayzart_js', 'console.log("inline");' );
		update_post_meta(
			$post_id,
			'_kayzart_external_styles',
			wp_json_encode( array( 'https://example.com/inline.css' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
		update_post_meta(
			$post_id,
			'_kayzart_external_scripts',
			wp_json_encode( array( 'https://example.com/inline.js' ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		wp_set_current_user( $admin_id );

		$first  = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		$second = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );

		$this->assertStringContainsString( 'https://example.com/inline.css', $first );
		$this->assertStringContainsString( 'body{font-size:16px;}', $first );
		$this->assertStringContainsString( '<p>KayzArt content</p>', $first );
		$this->assertStringNotContainsString( '<script src="https://example.com/inline.js"></script>', $first );
		$this->assertStringContainsString( 'data-kayzart-js="1"', $first );
		$this->assertStringContainsString( 'data-kayzart-js-mode="classic"', $first );

		$this->assertStringNotContainsString( 'https://example.com/inline.css', $second );
		$this->assertStringNotContainsString( 'body{font-size:16px;}', $second );
		$this->assertStringContainsString( '<p>KayzArt content</p>', $second );
		$this->assertStringNotContainsString( '<script src="https://example.com/inline.js"></script>', $second );
		$this->assertStringContainsString( 'data-kayzart-js="1"', $second );

		$external_handle = 'kayzart-ext-' . $post_id . '-0';
		$this->assertTrue( wp_script_is( $external_handle, 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'kayzart-shadow-runtime', 'enqueued' ) );
	}

	public function test_shortcode_embed_runs_only_allowlisted_shortcodes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '[allowed_probe] [blocked_probe]',
			)
		);
		update_option( Admin::OPTION_SHORTCODE_ALLOWLIST, "allowed_probe\n" );

		add_shortcode(
			'allowed_probe',
			static function (): string {
				return '<span class="allowed-probe">ok</span>';
			}
		);
		add_shortcode(
			'blocked_probe',
			static function (): string {
				return '<span class="blocked-probe">blocked</span>';
			}
		);

		try {
			wp_set_current_user( $admin_id );
			$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		} finally {
			remove_shortcode( 'allowed_probe' );
			remove_shortcode( 'blocked_probe' );
		}

		$this->assertStringContainsString( '<span class="allowed-probe">ok</span>', $output );
		$this->assertStringNotContainsString( '<span class="blocked-probe">blocked</span>', $output );
		$this->assertStringContainsString( '[blocked_probe]', $output );
	}

	public function test_shortcode_embed_with_empty_allowlist_keeps_shortcodes_as_text(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '[allowed_probe]',
			)
		);
		update_option( Admin::OPTION_SHORTCODE_ALLOWLIST, '' );

		add_shortcode(
			'allowed_probe',
			static function (): string {
				return '<span class="allowed-probe">ok</span>';
			}
		);

		try {
			wp_set_current_user( $admin_id );
			$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		} finally {
			remove_shortcode( 'allowed_probe' );
		}

		$this->assertStringContainsString( '[allowed_probe]', $output );
		$this->assertStringNotContainsString( '<span class="allowed-probe">ok</span>', $output );
	}

	public function test_shortcode_embed_allowlist_runs_nested_shortcodes_in_two_passes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '[kayzart_section]',
			)
		);
		update_option( Admin::OPTION_SHORTCODE_ALLOWLIST, "kayzart_section\ncontact-form-7" );

		add_shortcode(
			'kayzart_section',
			static function (): string {
				return '[contact-form-7 id="123"]';
			}
		);
		add_shortcode(
			'contact-form-7',
			static function ( $atts ): string {
				$id = isset( $atts['id'] ) ? (string) $atts['id'] : '';
				return '<form data-cf7-id="' . esc_attr( $id ) . '"></form>';
			}
		);

		try {
			wp_set_current_user( $admin_id );
			$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		} finally {
			remove_shortcode( 'kayzart_section' );
			remove_shortcode( 'contact-form-7' );
		}

		$this->assertStringContainsString( '<form data-cf7-id="123"></form>', $output );
		$this->assertStringNotContainsString( '[contact-form-7 id="123"]', $output );
	}

	public function test_shortcode_embed_stops_after_two_passes(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );

		update_post_meta( $post_id, '_kayzart_shortcode_enabled', '1' );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '[pass_one]',
			)
		);
		update_option( Admin::OPTION_SHORTCODE_ALLOWLIST, "pass_one\npass_two\npass_three" );

		add_shortcode(
			'pass_one',
			static function (): string {
				return '[pass_two]';
			}
		);
		add_shortcode(
			'pass_two',
			static function (): string {
				return '[pass_three]';
			}
		);
		add_shortcode(
			'pass_three',
			static function (): string {
				return '<span class="pass-three">ok</span>';
			}
		);

		try {
			wp_set_current_user( $admin_id );
			$output = do_shortcode( '[kayzart post_id="' . $post_id . '"]' );
		} finally {
			remove_shortcode( 'pass_one' );
			remove_shortcode( 'pass_two' );
			remove_shortcode( 'pass_three' );
		}

		$this->assertStringContainsString( '[pass_three]', $output );
		$this->assertStringNotContainsString( '<span class="pass-three">ok</span>', $output );
	}

	public function test_enqueue_css_preserves_tailwind_escaped_arbitrary_values(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = $this->create_kayzart_post( $admin_id, 'publish' );
		$post     = get_post( $post_id );

		$this->assertInstanceOf( WP_Post::class, $post );

		update_post_meta( $post_id, '_kayzart_tailwind', '1' );

		$generated_css = '.text-\\[2rem\\]{font-size:2rem;}';
		update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $generated_css ) );

		$original_wp_query = $this->set_query_for_post( $post_id, $post );
		Frontend::enqueue_css();
		$this->restore_query( $original_wp_query );

		$this->assertTrue( wp_style_is( 'kayzart', 'enqueued' ) );

		$styles       = wp_styles();
		$inline_rules = $styles->get_data( 'kayzart', 'after' );
		$this->assertIsArray( $inline_rules );
		$inline_css = implode( "\n", $inline_rules );

		$this->assertStringContainsString( $generated_css, $inline_css );
		$this->assertStringNotContainsString( '.text-[2rem]', $inline_css );
	}

	private function create_kayzart_post( int $author_id, string $status ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'    => Post_Type::POST_TYPE,
				'post_status'  => $status,
				'post_author'  => $author_id,
				'post_content' => '<p>KayzArt content</p>',
			)
		);
	}

	private function set_query_for_post( int $post_id, WP_Post $post ): ?WP_Query {
		global $wp_query;
		$original_wp_query = $wp_query ?? null;

		$wp_query                       = new WP_Query();
		$wp_query->queried_object_id    = $post_id;
		$wp_query->queried_object       = $post;
		$wp_query->is_singular          = true;
		$wp_query->is_single            = true;
		$wp_query->set( 'kayzart_preview', '' );

		return $original_wp_query;
	}

	private function restore_query( ?WP_Query $original_wp_query ): void {
		global $wp_query;
		if ( null !== $original_wp_query ) {
			$wp_query = $original_wp_query;
		} else {
			unset( $wp_query );
		}
	}

	private function reset_shortcode_state(): void {
		$instance_property = new ReflectionProperty( Frontend::class, 'shortcode_instance' );
		$instance_property->setAccessible( true );
		$instance_property->setValue( null, 0 );

		$assets_property = new ReflectionProperty( Frontend::class, 'shortcode_assets_loaded' );
		$assets_property->setAccessible( true );
		$assets_property->setValue( null, array() );

		$external_property = new ReflectionProperty( Frontend::class, 'external_script_handles' );
		$external_property->setAccessible( true );
		$external_property->setValue( null, array() );

		$runtime_property = new ReflectionProperty( Frontend::class, 'shadow_runtime_enqueued' );
		$runtime_property->setAccessible( true );
		$runtime_property->setValue( null, false );

		$style_count_property = new ReflectionProperty( Frontend::class, 'shadow_style_render_count' );
		$style_count_property->setAccessible( true );
		$style_count_property->setValue( null, 0 );
	}
}
