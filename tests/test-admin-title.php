<?php
/**
 * Admin title tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Post_Type;

class Test_Admin_Title extends WP_UnitTestCase {
	private array $original_get = array();
	private int $admin_id = 0;

	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->original_get = $_GET;
	}

	protected function tearDown(): void {
		$_GET = $this->original_get;
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_filter_admin_title_replaces_left_side_for_kayzart_editor(): void {
		$post_id = $this->create_kayzart_post( 'Foo' );
		set_current_screen( 'admin_page_' . Admin::MENU_SLUG );

		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$filtered = Admin::filter_admin_title(
			'KayzArt &lsaquo; Test Site - WordPress',
			'KayzArt'
		);

		$this->assertSame( 'KayzArt Live Code Editor: Foo &lsaquo; Test Site - WordPress', $filtered );
	}

	public function test_filter_admin_title_uses_untitled_fallback_for_empty_post_title(): void {
		$post_id = $this->create_kayzart_post( '' );
		set_current_screen( 'admin_page_' . Admin::MENU_SLUG );

		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$filtered = Admin::filter_admin_title(
			'KayzArt &lsaquo; Test Site - WordPress',
			'KayzArt'
		);

		$this->assertSame( 'KayzArt Live Code Editor: Untitled &lsaquo; Test Site - WordPress', $filtered );
	}

	public function test_filter_admin_title_supports_utf8_separator_suffix(): void {
		$post_id = $this->create_kayzart_post( 'Foo' );
		set_current_screen( 'admin_page_' . Admin::MENU_SLUG );

		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$filtered = Admin::filter_admin_title(
			'KayzArt ' . "\xE2\x80\xB9" . ' Test Site - WordPress',
			'KayzArt'
		);

		$this->assertSame( 'KayzArt Live Code Editor: Foo ' . "\xE2\x80\xB9" . ' Test Site - WordPress', $filtered );
	}

	public function test_filter_admin_title_does_not_change_other_admin_pages(): void {
		set_current_screen( 'dashboard' );

		$original = 'Settings &lsaquo; Test Site - WordPress';
		$filtered = Admin::filter_admin_title( $original, 'Settings' );

		$this->assertSame( $original, $filtered );
	}

	public function test_filter_admin_title_returns_left_label_when_suffix_is_not_available(): void {
		$post_id = $this->create_kayzart_post( 'Foo' );
		set_current_screen( 'admin_page_' . Admin::MENU_SLUG );

		$_GET['post_id'] = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$filtered = Admin::filter_admin_title( 'KayzArt', 'KayzArt' );

		$this->assertSame( 'KayzArt Live Code Editor: Foo', $filtered );
	}

	public function test_maybe_suppress_editor_notices_sets_non_null_global_title(): void {
		$post_id = $this->create_kayzart_post( 'Foo' );
		set_current_screen( 'admin_page_' . Admin::MENU_SLUG );

		$_GET['post_id']  = (string) $post_id;
		$_GET['_wpnonce'] = wp_create_nonce( Admin::EDITOR_PAGE_NONCE_ACTION );

		$GLOBALS['title'] = null;

		$screen = get_current_screen();
		Admin::maybe_suppress_editor_notices( $screen );

		$this->assertIsString( $GLOBALS['title'] );
		$this->assertSame( 'KayzArt Live Code Editor: Foo', $GLOBALS['title'] );
	}

	private function create_kayzart_post( string $title ): int {
		return (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $title,
			)
		);
	}
}
