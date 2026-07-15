<?php
/**
 * Uninstall behavior tests for KayzArt.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Setup;
use KayzArt\Post_Type;

class Test_Uninstall extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
	}

	protected function tearDown(): void {
		delete_option( 'kayzart_delete_on_uninstall' );
		delete_option( 'kayzart_post_slug' );
		delete_option( 'kayzart_shortcode_allowlist' );
		delete_option( 'kayzart_flush_rewrite' );
		parent::tearDown();
	}

	public function test_uninstall_keeps_data_when_legacy_delete_option_is_disabled(): void {
		$kayzart_post_id = $this->create_post( Post_Type::POST_TYPE );
		$normal_post_id  = $this->create_post( 'post' );

		update_option( 'kayzart_delete_on_uninstall', '0' );
		update_option( 'kayzart_post_slug', 'kayzart-custom' );
		update_option( 'kayzart_shortcode_allowlist', "gallery\ncontact-form-7" );
		update_option( 'kayzart_flush_rewrite', '1' );

		$this->run_uninstall_script();

		$this->assertInstanceOf( WP_Post::class, get_post( $kayzart_post_id ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $normal_post_id ) );
		$this->assertSame( '0', get_option( 'kayzart_delete_on_uninstall', '' ) );
		$this->assertSame( 'kayzart-custom', get_option( 'kayzart_post_slug', '' ) );
		$this->assertSame( "gallery\ncontact-form-7", get_option( 'kayzart_shortcode_allowlist', '' ) );
		$this->assertSame( '1', get_option( 'kayzart_flush_rewrite', '' ) );
	}

	public function test_uninstall_keeps_data_when_legacy_delete_option_is_enabled(): void {
		$kayzart_post_id = $this->create_post( Post_Type::POST_TYPE );
		$normal_post_id   = $this->create_post( 'post' );

		update_option( 'kayzart_delete_on_uninstall', '1' );
		update_option( 'kayzart_post_slug', 'kayzart-custom' );
		update_option( 'kayzart_shortcode_allowlist', "gallery\ncontact-form-7" );
		update_option( 'kayzart_flush_rewrite', '1' );

		$this->run_uninstall_script();

		$this->assertInstanceOf( WP_Post::class, get_post( $kayzart_post_id ), 'KayzArt posts must remain.' );
		$this->assertInstanceOf( WP_Post::class, get_post( $normal_post_id ), 'Non-KayzArt posts must remain.' );
		$this->assertSame( '1', get_option( 'kayzart_delete_on_uninstall', '' ) );
		$this->assertSame( 'kayzart-custom', get_option( 'kayzart_post_slug', '' ) );
		$this->assertSame( "gallery\ncontact-form-7", get_option( 'kayzart_shortcode_allowlist', '' ) );
		$this->assertSame( '1', get_option( 'kayzart_flush_rewrite', '' ) );
	}

	/**
	 * AI job data and its capability follow the plugin data-retention policy.
	 */
	public function test_uninstall_keeps_ai_jobs_schema_and_capability(): void {
		global $wpdb;

		Ai_Setup::activate();
		$table_name = Ai_Setup::get_jobs_table_name();
		$wpdb->insert(
			$table_name,
			array(
				'job_uuid'    => wp_generate_uuid4(),
				'post_id'     => 1,
				'user_id'     => 1,
				'request_id'  => 'request-uninstall-test',
				'status'      => 'pending',
				'payload_json' => '{}',
				'events_json' => '[]',
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		$this->run_uninstall_script();

		$this->assertSame( '1', get_option( Ai_Setup::DB_VERSION_OPTION ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertTrue( get_role( 'administrator' )->has_cap( Ai_Setup::CAPABILITY ) );

		$wpdb->delete(
			$table_name,
			array( 'request_id' => 'request-uninstall-test' ),
			array( '%s' )
		);
	}

	private function create_post( string $post_type ): int {
		$author_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		return (int) self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'post_author' => $author_id,
			)
		);
	}

	private function run_uninstall_script(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require KAYZART_PATH . 'uninstall.php';
	}
}
