<?php
/**
 * Run with wp eval-file on the disposable scheduler site: seed, then verify.
 *
 * @package KayzArt
 */

if ( '1' !== getenv( 'KAYZART_SCHEDULER_E2E' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'This fixture requires a disposable scheduler CLI site.' );
}

$scheduler_store = ActionScheduler::store();
if ( 'seed' === ( $args[0] ?? '' ) ) {
	if ( '4.0.0' !== ActionScheduler_Versions::instance()->latest_version() ) {
		throw new RuntimeException( 'The seed must use the former Action Scheduler 4.0.0 dependency.' );
	}
	$scheduler_id = as_schedule_single_action( time() + DAY_IN_SECONDS, 'kayzart_scheduler_retained_fixture', array( 'retained-job' ), 'kayzart-scheduler-fixture' );
	if ( ! $scheduler_id ) {
		throw new RuntimeException( 'Could not seed an existing scheduled action.' );
	}
	update_option( 'kayzart_scheduler_retained_fixture', array( 'id' => $scheduler_id, 'schema' => get_option( 'schema-ActionScheduler_StoreSchema' ) ), false );
	echo "Retained action seeded with AS 4.0.0.\n";
	return;
}

$scheduler_seed = get_option( 'kayzart_scheduler_retained_fixture' );
if ( ! is_array( $scheduler_seed ) ) {
	throw new RuntimeException( 'Missing retained-action fixture.' );
}
$scheduler_id = (int) $scheduler_seed['id'];
try {
	if ( $scheduler_seed['schema'] !== get_option( 'schema-ActionScheduler_StoreSchema' ) || array( 'retained-job' ) !== $scheduler_store->fetch_action( $scheduler_id )->get_args() || 'pending' !== $scheduler_store->get_status( $scheduler_id ) ) {
		throw new RuntimeException( 'The existing schema or pending action was not preserved.' );
	}
	$scheduler_ran = false;
	add_action( 'kayzart_scheduler_retained_fixture', static function ( $marker ) use ( &$scheduler_ran ) {
		$scheduler_ran = 'retained-job' === $marker;
	} );
	ActionScheduler::runner()->process_action( $scheduler_id, 'Kayzart scheduler regression' );
	if ( ! $scheduler_ran || 'complete' !== $scheduler_store->get_status( $scheduler_id ) ) {
		throw new RuntimeException( 'The existing scheduled action did not run.' );
	}
	echo 'Retained action ran with AS ' . ActionScheduler_Versions::instance()->latest_version() . ".\n";
} finally {
	$scheduler_store->delete_action( $scheduler_id );
	delete_option( 'kayzart_scheduler_retained_fixture' );
}
