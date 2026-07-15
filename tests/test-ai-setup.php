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
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Ai_Setup::get_timeline_table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
		$timeline_table = Ai_Setup::get_timeline_table_name();
		$this->assertSame( $table_name, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) );
		$this->assertSame( $timeline_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $timeline_table ) ) );
		$this->assertSame( Ai_Setup::DB_VERSION, get_option( Ai_Setup::DB_VERSION_OPTION ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayNotHasKey( Ai_Setup::DB_VERSION_OPTION, wp_load_alloptions() );

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertEqualsCanonicalizing(
			array(
				'job_uuid',
				'post_id',
				'user_id',
				'request_id',
				'status',
				'cancel_requested',
				'payload_json',
				'snapshot_json',
				'events_json',
				'usage_json',
				'error',
				'created_at',
				'updated_at',
				'started_at',
				'finished_at',
				'deadline_at',
				'lock_key',
			),
			$columns
		);

		$indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . $table_name, 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'PRIMARY', $indexes );
		$this->assertContains( 'user_request', $indexes );
		$this->assertContains( 'active_post', $indexes );
		$this->assertContains( 'post_status', $indexes );
		$this->assertContains( 'user_created', $indexes );

		$timeline_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $timeline_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'id', $timeline_columns );
		$this->assertContains( 'activity_uuid', $timeline_columns );
		$this->assertContains( 'job_uuid', $timeline_columns );
		$this->assertContains( 'revision_id', $timeline_columns );
		$this->assertContains( 'source_activity_id', $timeline_columns );
		$timeline_indexes = $wpdb->get_col( 'SHOW INDEX FROM ' . $timeline_table, 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'PRIMARY', $timeline_indexes );
		$this->assertContains( 'job_uuid', $timeline_indexes );
		$this->assertContains( 'revision_id', $timeline_indexes );
		$this->assertContains( 'post_id_id', $timeline_indexes );

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

	/**
	 * A Phase 0 v1 table is upgraded in place to the current schema.
	 */
	public function test_v1_schema_is_upgraded_to_current(): void {
		global $wpdb;
		$table_name      = Ai_Setup::get_jobs_table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$wpdb->query(
			"CREATE TABLE {$table_name} (
				job_uuid char(36) NOT NULL,
				post_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				request_id varchar(64) NOT NULL,
				status varchar(20) NOT NULL,
				payload_json longtext NOT NULL,
				snapshot_json longtext NULL,
				events_json longtext NOT NULL,
				usage_json longtext NULL,
				error longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (job_uuid),
				UNIQUE KEY user_request (user_id, request_id)
			) {$charset_collate}"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( Ai_Setup::DB_VERSION_OPTION, '1', false );

		Ai_Setup::maybe_upgrade();

		$columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table_name ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'cancel_requested', $columns );
		$this->assertContains( 'deadline_at', $columns );
		$this->assertContains( 'lock_key', $columns );
		$this->assertSame( Ai_Setup::DB_VERSION, get_option( Ai_Setup::DB_VERSION_OPTION ) );
		$timeline_table = Ai_Setup::get_timeline_table_name();
		$this->assertSame( $timeline_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $timeline_table ) ) );
		$timeline_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $timeline_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'model', $timeline_columns );
		$this->assertContains( 'input_tokens', $timeline_columns );
		$this->assertContains( 'output_tokens', $timeline_columns );
	}
}
