<?php
/**
 * AI schema and capability setup tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Setup;

/**
 * Verify installation and upgrades for the local AI job infrastructure.
 */
class Test_Kayzart_Ai_Setup extends WP_UnitTestCase {

	/**
	 * Reset the AI schema before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Ai_Setup::get_jobs_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( Ai_Setup::DB_VERSION_OPTION );

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			$administrator->remove_cap( Ai_Setup::CAPABILITY );
		}
	}

	/**
	 * Restore the installed schema for other tests.
	 */
	protected function tearDown(): void {
		Ai_Setup::activate();
		parent::tearDown();
	}

	/**
	 * Activation installs the complete schema and administrator capability.
	 */
	public function test_activation_creates_schema_and_grants_only_administrators(): void {
		global $wpdb;

		Ai_Setup::activate();

		$table_name = Ai_Setup::get_jobs_table_name();
		$this->assertSame( $table_name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) );
		$this->assertSame( Ai_Setup::DB_VERSION, get_option( Ai_Setup::DB_VERSION_OPTION ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Ai_Setup::DB_VERSION_OPTION, wp_load_alloptions() );

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame(
			array(
				'job_uuid',
				'post_id',
				'user_id',
				'request_id',
				'status',
				'payload_json',
				'snapshot_json',
				'events_json',
				'usage_json',
				'error',
				'created_at',
				'updated_at',
			),
			$columns
		);

		$indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . $table_name, 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'PRIMARY', $indexes );
		$this->assertContains( 'user_request', $indexes );
		$this->assertContains( 'post_status', $indexes );
		$this->assertContains( 'user_created', $indexes );

		$this->assertTrue( get_role( 'administrator' )->has_cap( Ai_Setup::CAPABILITY ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Ai_Setup::CAPABILITY ) );
	}

	/**
	 * Upgrade runs once and skips writes when the version already matches.
	 */
	public function test_upgrade_repairs_missing_schema_and_capability_once(): void {
		global $wpdb;

		Ai_Setup::maybe_upgrade();
		$table_name = Ai_Setup::get_jobs_table_name();
		$this->assertSame( $table_name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Ai_Setup::CAPABILITY ) );

		get_role( 'administrator' )->remove_cap( Ai_Setup::CAPABILITY );
		Ai_Setup::maybe_upgrade();
		$this->assertFalse(
			get_role( 'administrator' )->has_cap( Ai_Setup::CAPABILITY ),
			'A matching schema version must not perform upgrade writes on normal requests.'
		);
	}

	/**
	 * Repeated activation is safe.
	 */
	public function test_activation_is_idempotent(): void {
		Ai_Setup::activate();
		Ai_Setup::activate();

		$this->assertSame( Ai_Setup::DB_VERSION, get_option( Ai_Setup::DB_VERSION_OPTION ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Ai_Setup::CAPABILITY ) );
	}
}
