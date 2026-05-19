<?php
/**
 * Tests for KayzArt upgrade routines.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;
use KayzArt\Upgrader;

class Test_Upgrader extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		delete_option( Upgrader::OPTION_TAILWIND_REMOVED_MIGRATED );
	}

	protected function tearDown(): void {
		delete_option( Upgrader::OPTION_TAILWIND_REMOVED_MIGRATED );
		parent::tearDown();
	}

	public function test_tailwind_migration_moves_generated_css_to_normal_css(): void {
		$post_id = $this->create_kayzart_post();

		update_post_meta( $post_id, '_kayzart_css', '@import "tailwindcss";' );
		update_post_meta( $post_id, '_kayzart_tailwind', '1' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		update_post_meta( $post_id, '_kayzart_generated_css', '.text-sm{font-size:.875rem;}' );

		Upgrader::maybe_migrate_tailwind_posts();

		$this->assertSame( '.text-sm{font-size:.875rem;}', get_post_meta( $post_id, '_kayzart_css', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_tailwind_locked', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
	}

	public function test_tailwind_migration_keeps_css_when_generated_css_is_empty(): void {
		$post_id = $this->create_kayzart_post();

		update_post_meta( $post_id, '_kayzart_css', 'body{color:red;}' );
		update_post_meta( $post_id, '_kayzart_tailwind', '1' );
		update_post_meta( $post_id, '_kayzart_generated_css', '' );

		Upgrader::maybe_migrate_tailwind_posts();

		$this->assertSame( 'body{color:red;}', get_post_meta( $post_id, '_kayzart_css', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_tailwind', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_kayzart_generated_css', true ) );
	}

	public function test_tailwind_migration_handles_marked_pages(): void {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );
		update_post_meta( $page_id, '_kayzart_tailwind', '1' );
		update_post_meta( $page_id, '_kayzart_generated_css', '.page{display:block;}' );

		Upgrader::maybe_migrate_tailwind_posts();

		$this->assertSame( '.page{display:block;}', get_post_meta( $page_id, '_kayzart_css', true ) );
		$this->assertSame( '', get_post_meta( $page_id, '_kayzart_tailwind', true ) );
	}

	public function test_tailwind_migration_ignores_unmarked_pages(): void {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		update_post_meta( $page_id, '_kayzart_tailwind', '1' );
		update_post_meta( $page_id, '_kayzart_generated_css', '.page{display:block;}' );

		Upgrader::maybe_migrate_tailwind_posts();

		$this->assertSame( '', get_post_meta( $page_id, '_kayzart_css', true ) );
		$this->assertSame( '1', get_post_meta( $page_id, '_kayzart_tailwind', true ) );
	}

	private function create_kayzart_post(): int {
		return (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::POST_TYPE,
			)
		);
	}
}
