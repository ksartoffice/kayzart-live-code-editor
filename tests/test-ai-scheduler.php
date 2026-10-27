<?php
/**
 * Scheduler bundle and runtime compatibility tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Scheduler;

class Test_Kayzart_Ai_Scheduler extends WP_UnitTestCase {

	/** @dataProvider bundle_versions */
	public function test_bundle_selection( string $version, string $package ): void {
		$file = Ai_Scheduler::bundled_file( $version );
		$this->assertSame( KAYZART_PATH . 'vendor/' . $package . '/action-scheduler.php', $file );
		$this->assertFileExists( $file );
	}

	public function bundle_versions(): array {
		return array(
			array( '5.9', 'kayzart/action-scheduler-legacy' ),
			array( '6.4', 'kayzart/action-scheduler-legacy' ),
			array( '6.7.2', 'kayzart/action-scheduler-legacy' ),
			array( '6.8-RC1', 'kayzart/action-scheduler-legacy' ),
			array( '6.8', 'woocommerce/action-scheduler' ),
			array( '7.1.3', 'woocommerce/action-scheduler' ),
		);
	}

	public function test_real_bootstrap_loaded_the_selected_runtime(): void {
		global $wp_version;
		$modern = version_compare( $wp_version, '6.8', '>=' );
		$this->assertSame( $modern ? '4.0.0' : '3.6.4', ActionScheduler_Versions::instance()->latest_version() );
		$this->assertSame( $modern, Ai_Scheduler::supports_unique_job_actions() );
		$source = wp_normalize_path( ( new ReflectionClass( 'ActionScheduler' ) )->getFileName() );
		$bundle = wp_normalize_path( dirname( realpath( Ai_Scheduler::bundled_file( $wp_version ) ) ) ) . '/';
		$this->assertStringStartsWith( $bundle, $source );
		if ( 'db' === getenv( 'KAYZART_TEST_AS_STORE' ) ) {
			$this->assertInstanceOf( ActionScheduler_DBStore::class, ActionScheduler::store() );
		}
	}
}
