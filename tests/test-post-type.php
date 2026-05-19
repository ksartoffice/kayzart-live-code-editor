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

	public function test_post_type_uses_lp_admin_labels() {
		$post_type = get_post_type_object( Post_Type::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertSame( __( 'KayzArt LP', 'kayzart-live-code-editor' ), $post_type->label );
		$this->assertSame( __( 'KayzArt LP', 'kayzart-live-code-editor' ), $post_type->labels->name );
		$this->assertSame( __( 'KayzArt LP', 'kayzart-live-code-editor' ), $post_type->labels->singular_name );
		$this->assertSame( __( 'LP list', 'kayzart-live-code-editor' ), $post_type->labels->all_items );
		$this->assertSame( __( 'Create New LP', 'kayzart-live-code-editor' ), $post_type->labels->add_new_item );
	}
}


