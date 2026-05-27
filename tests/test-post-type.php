<?php
/**
 * Tests for the KayzArt post type.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;

class Test_Post_Type extends WP_UnitTestCase {
	public function test_post_type_is_registered() {
		$this->assertTrue( post_type_exists( Post_Type::POST_TYPE ) );
	}

	public function test_post_type_uses_legacy_admin_labels_and_allows_creation() {
		$post_type = get_post_type_object( Post_Type::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( __( '旧KayzArt', 'kayzart-live-code-editor' ), $post_type->label );
		$this->assertSame( __( '旧KayzArt', 'kayzart-live-code-editor' ), $post_type->labels->name );
		$this->assertSame( __( '旧KayzArt', 'kayzart-live-code-editor' ), $post_type->labels->singular_name );
		$this->assertSame( __( '旧KayzArt一覧', 'kayzart-live-code-editor' ), $post_type->labels->all_items );
		$this->assertSame( 'edit_posts', $post_type->cap->create_posts );
	}

	public function test_is_kayzart_post_accepts_marked_posts_only_except_legacy_cpt(): void {
		$kayzart_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::POST_TYPE,
			)
		);
		$page_id    = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		$post_id    = (int) self::factory()->post->create(
			array(
				'post_type' => 'post',
			)
		);

		$this->assertTrue( Post_Type::is_kayzart_post( $kayzart_id ) );
		$this->assertFalse( Post_Type::is_kayzart_post( $page_id ) );
		$this->assertFalse( Post_Type::is_kayzart_post( $post_id ) );

		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );

		$this->assertTrue( Post_Type::is_kayzart_post( $page_id ) );
		$this->assertTrue( Post_Type::is_kayzart_page( $page_id ) );
	}

	public function test_enabled_post_types_default_to_page_and_include_legacy_cpt_when_posts_exist(): void {
		delete_option( \KayzArt\Admin::OPTION_ENABLED_POST_TYPES );
		$this->assertSame( array( Post_Type::PAGE_TYPE ), Post_Type::get_enabled_post_types() );

		self::factory()->post->create(
			array(
				'post_type' => Post_Type::POST_TYPE,
			)
		);

		$this->assertContains( Post_Type::PAGE_TYPE, Post_Type::get_enabled_post_types() );
		$this->assertContains( Post_Type::POST_TYPE, Post_Type::get_enabled_post_types() );
	}

	public function test_selectable_post_types_exclude_legacy_cpt_when_no_legacy_posts_exist(): void {
		$post_types = Post_Type::get_selectable_post_types();

		$this->assertArrayHasKey( Post_Type::PAGE_TYPE, $post_types );
		$this->assertArrayNotHasKey( Post_Type::POST_TYPE, $post_types );
	}

	public function test_selectable_post_types_include_legacy_cpt_when_legacy_posts_exist(): void {
		self::factory()->post->create(
			array(
				'post_type' => Post_Type::POST_TYPE,
			)
		);

		$post_types = Post_Type::get_selectable_post_types();

		$this->assertArrayHasKey( Post_Type::POST_TYPE, $post_types );
	}

	public function test_sanitize_enabled_post_types_drops_legacy_cpt_when_no_legacy_posts_exist(): void {
		$this->assertSame( array( Post_Type::PAGE_TYPE ), Post_Type::sanitize_enabled_post_types( array( Post_Type::POST_TYPE ) ) );
	}

	public function test_add_post_states_marks_kayzart_pages_as_lp(): void {
		$page_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		$page    = get_post( $page_id );
		$this->assertInstanceOf( WP_Post::class, $page );

		$states = Post_Type::add_post_states( array(), $page );
		$this->assertArrayNotHasKey( 'kayzart_lp', $states );

		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );

		$states = Post_Type::add_post_states( array(), $page );
		$this->assertSame( __( 'Landing page', 'kayzart-live-code-editor' ), $states['kayzart_lp'] ?? '' );
	}

	public function test_row_action_uses_landing_page_edit_label_for_marked_pages(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$page_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		update_post_meta( $page_id, Post_Type::ENABLED_META, '1' );

		$page = get_post( $page_id );
		$this->assertInstanceOf( WP_Post::class, $page );

		$actions = Post_Type::add_kayzart_row_action( array(), $page );

		$this->assertArrayHasKey( 'kayzart_edit', $actions );
		$this->assertStringContainsString( esc_html__( 'Edit landing page', 'kayzart-live-code-editor' ), $actions['kayzart_edit'] );
	}

	public function test_row_action_allows_unmarked_legacy_cpt_posts(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$post_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::POST_TYPE,
			)
		);
		$post    = get_post( $post_id );
		$this->assertInstanceOf( WP_Post::class, $post );
		$this->assertSame( '', get_post_meta( $post_id, Post_Type::ENABLED_META, true ) );

		$actions = Post_Type::add_kayzart_row_action( array(), $post );

		$this->assertArrayHasKey( 'kayzart_edit', $actions );
		$this->assertStringContainsString( esc_html__( 'Edit landing page', 'kayzart-live-code-editor' ), $actions['kayzart_edit'] );
	}

	public function test_row_action_ignores_unmarked_pages(): void {
		$user_id = (int) self::factory()->user->create(
			array(
				'role' => 'administrator',
			)
		);
		wp_set_current_user( $user_id );

		$page_id = (int) self::factory()->post->create(
			array(
				'post_type' => Post_Type::PAGE_TYPE,
			)
		);
		$page    = get_post( $page_id );
		$this->assertInstanceOf( WP_Post::class, $page );

		$actions = Post_Type::add_kayzart_row_action( array(), $page );

		$this->assertArrayNotHasKey( 'kayzart_edit', $actions );
	}
}


