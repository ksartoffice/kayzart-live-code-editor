<?php
/**
 * Persistent user-facing AI edit timeline.
 *
 * @package KayzArt
 */

namespace KayzArt;

// Parameter types are part of each method signature; concise comments keep this storage class readable.
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores durable AI edit, save, and restore activities. */
class Ai_Timeline_Store {
	const PAGE_SIZE     = 50;
	const CONTEXT_LIMIT = 10;

	/** Create the durable prompt entry for a job. */
	public function create_ai_edit( array $job, array $payload ) {
		global $wpdb;
		$existing = $this->get_by_job( (string) $job['job_uuid'] );
		if ( $existing ) {
			return $existing;
		}
		$now      = self::now();
		$snapshot = self::snapshot_from_payload( $payload );
		$inserted = $wpdb->insert(
			Ai_Setup::get_timeline_table_name(),
			array(
				'activity_uuid'      => wp_generate_uuid4(),
				'post_id'            => (int) $job['post_id'],
				'user_id'            => (int) $job['user_id'],
				'activity_type'      => 'ai_edit',
				'job_uuid'           => (string) $job['job_uuid'],
				'request_id'         => (string) $job['request_id'],
				'prompt'             => isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '',
				'context_json'       => wp_json_encode( self::display_contexts( $payload ) ),
				'execution_status'   => (string) $job['status'],
				'application_status' => 'not_applied',
				'changed_targets'    => '[]',
				'before_hash'        => self::editor_hash( $snapshot ),
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return $this->get_by_job( (string) $job['job_uuid'] );
		}
		return $this->get_by_job( (string) $job['job_uuid'] );
	}

	/** Update a job-backed activity's execution state. */
	public function update_execution( string $job_uuid, string $status ): bool {
		global $wpdb;
		$result = $wpdb->update(
			Ai_Setup::get_timeline_table_name(),
			array(
				'execution_status' => $status,
				'updated_at'       => self::now(),
			),
			array( 'job_uuid' => $job_uuid ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		return false !== $result;
	}

	/** Store a completed edit's lightweight durable result. */
	public function complete( string $job_uuid, array $before, array $after, string $summary ): bool {
		global $wpdb;
		$result = $wpdb->update(
			Ai_Setup::get_timeline_table_name(),
			array(
				'execution_status' => 'completed',
				'changed_targets'  => wp_json_encode( self::changed_targets( $before, $after ) ),
				'before_hash'      => self::editor_hash( $before ),
				'after_hash'       => self::editor_hash( $after ),
				'summary'          => self::truncate( $summary, 512 ),
				'updated_at'       => self::now(),
			),
			array( 'job_uuid' => $job_uuid ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
		return false !== $result;
	}

	/** Persist a save checkpoint once per WordPress revision. */
	public function record_save( int $post_id, int $user_id, int $revision_id ) {
		global $wpdb;
		$existing = $this->get_by_revision( $revision_id );
		if ( $existing ) {
			return $existing;
		}
		$now = self::now();
		$wpdb->insert(
			Ai_Setup::get_timeline_table_name(),
			array(
				'activity_uuid' => wp_generate_uuid4(),
				'post_id'       => $post_id,
				'user_id'       => $user_id,
				'activity_type' => 'save',
				'revision_id'   => $revision_id,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
		return $this->get_by_revision( $revision_id );
	}

	/** Record an explicit restoration of a retained edit snapshot. */
	public function record_restore( array $source, int $user_id, string $target ) {
		global $wpdb;
		$now = self::now();
		$wpdb->insert(
			Ai_Setup::get_timeline_table_name(),
			array(
				'activity_uuid'      => wp_generate_uuid4(),
				'post_id'            => (int) $source['post_id'],
				'user_id'            => $user_id,
				'activity_type'      => 'restore',
				'source_activity_id' => (int) $source['id'],
				'restore_target'     => $target,
				'application_status' => 'after' === $target ? 'applied' : 'reverted',
				'created_at'         => $now,
				'updated_at'         => $now,
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $this->get( (int) $wpdb->insert_id );
	}

	/** Update whether an edit is currently applied or reverted. */
	public function update_application( int $id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'applied', 'reverted' ), true ) ) {
			return false;
		}
		$result = $wpdb->update(
			Ai_Setup::get_timeline_table_name(),
			array(
				'application_status' => $status,
				'updated_at'         => self::now(),
			),
			array(
				'id'            => $id,
				'activity_type' => 'ai_edit',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);
		return false !== $result;
	}

	/** Get one activity row. */
	public function get( int $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_timeline_table_name() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Get the timeline row for a job. */
	public function get_by_job( string $job_uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_timeline_table_name() . ' WHERE job_uuid = %s', $job_uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Get the timeline row for a revision. */
	public function get_by_revision( int $revision_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_timeline_table_name() . ' WHERE revision_id = %d', $revision_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** List a stable chronological page, newest page first. */
	public function list_for_post( int $post_id, int $before = 0 ): array {
		global $wpdb;
		$table = Ai_Setup::get_timeline_table_name();
		$jobs  = Ai_Setup::get_jobs_table_name();
		$where = $before > 0 ? $wpdb->prepare( 't.post_id = %d AND t.id < %d', $post_id, $before ) : $wpdb->prepare( 't.post_id = %d', $post_id );
		$rows  = $wpdb->get_results( "SELECT t.*, j.job_uuid AS retained_job_uuid FROM {$table} t LEFT JOIN {$jobs} j ON j.job_uuid = t.job_uuid WHERE {$where} ORDER BY t.id DESC LIMIT 51", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$more  = count( $rows ) > self::PAGE_SIZE;
		$rows  = array_slice( $rows, 0, self::PAGE_SIZE );
		$next  = $more && ! empty( $rows ) ? (int) end( $rows )['id'] : null;
		$rows  = array_reverse( $rows );
		return array(
			'items'      => array_map( array( $this, 'to_response' ), $rows ),
			'hasMore'    => $more,
			'nextCursor' => $next,
		);
	}

	/** Return a retained before/after snapshot, or null after job cleanup. */
	public function get_snapshot( array $activity, string $target ) {
		$job = ! empty( $activity['job_uuid'] ) ? ( new Ai_Job_Store() )->get( (string) $activity['job_uuid'] ) : null;
		if ( ! $job ) {
			return null;
		}
		if ( 'after' === $target ) {
			$data = json_decode( (string) $job['snapshot_json'], true );
			return is_array( $data ) ? $data : null;
		}
		$payload = json_decode( (string) $job['payload_json'], true );
		return is_array( $payload ) ? self::snapshot_from_payload( $payload ) : null;
	}

	/** Recent lightweight successful context for the next AI request. */
	public function recent_context( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT prompt, changed_targets, application_status, summary, created_at FROM ' . Ai_Setup::get_timeline_table_name() . " WHERE post_id = %d AND activity_type = 'ai_edit' AND execution_status = 'completed' ORDER BY id DESC LIMIT 10", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_reverse(
			array_map(
				static function ( array $row ): array {
					return array(
						'prompt'            => self::truncate( (string) $row['prompt'], 1024 ),
						'summary'           => self::truncate( (string) $row['summary'], 512 ),
						'changedTargets'    => self::decode_array( $row['changed_targets'] ),
						'applicationStatus' => (string) $row['application_status'],
						'createdAt'         => mysql_to_rfc3339( (string) $row['created_at'] ),
					);
				},
				$rows
			)
		);
	}

	/** Delete timeline data only when a post is permanently deleted. */
	public function delete_for_post( int $post_id ): int {
		global $wpdb;
		$result = $wpdb->delete( Ai_Setup::get_timeline_table_name(), array( 'post_id' => $post_id ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/** Public REST representation without durable internal summary. */
	public function to_response( array $row ): array {
		$user               = get_userdata( (int) $row['user_id'] );
		$source             = ! empty( $row['source_activity_id'] ) ? $this->get( (int) $row['source_activity_id'] ) : null;
		$revision_available = ! empty( $row['revision_id'] ) && (bool) wp_get_post_revision( (int) $row['revision_id'] );
		return array(
			'id'                => (int) $row['id'],
			'activityId'        => (string) $row['activity_uuid'],
			'type'              => (string) $row['activity_type'],
			'jobId'             => $row['job_uuid'] ? (string) $row['job_uuid'] : null,
			'requestId'         => $row['request_id'] ? (string) $row['request_id'] : null,
			'prompt'            => $row['prompt'] ? (string) $row['prompt'] : null,
			'contexts'          => self::decode_array( $row['context_json'] ),
			'executionStatus'   => $row['execution_status'] ? (string) $row['execution_status'] : null,
			'applicationStatus' => $row['application_status'] ? (string) $row['application_status'] : null,
			'changedTargets'    => self::decode_array( $row['changed_targets'] ),
			'beforeHash'        => $row['before_hash'] ? (string) $row['before_hash'] : null,
			'afterHash'         => $row['after_hash'] ? (string) $row['after_hash'] : null,
			'revisionId'        => $row['revision_id'] ? (int) $row['revision_id'] : null,
			'sourceActivityId'  => $source ? (int) $source['id'] : null,
			'sourcePrompt'      => $source && $source['prompt'] ? (string) $source['prompt'] : null,
			'restoreTarget'     => $row['restore_target'] ? (string) $row['restore_target'] : null,
			'detailsAvailable'  => array_key_exists( 'retained_job_uuid', $row )
				? ! empty( $row['retained_job_uuid'] )
				: ( ! empty( $row['job_uuid'] ) && (bool) ( new Ai_Job_Store() )->get( (string) $row['job_uuid'] ) ),
			'canPoll'           => ! empty( $row['job_uuid'] ) && ( get_current_user_id() === (int) $row['user_id'] || current_user_can( 'manage_options' ) ),
			'revisionAvailable' => $revision_available,
			'author'            => array(
				'id'   => (int) $row['user_id'],
				'name' => $user instanceof \WP_User ? (string) $user->display_name : __( 'Deleted user', 'kayzart-live-code-editor' ),
			),
			'createdAt'         => mysql_to_rfc3339( (string) $row['created_at'] ),
			'updatedAt'         => mysql_to_rfc3339( (string) $row['updated_at'] ),
		);
	}

	/** Normalize the editable snapshot fields from an agent payload. */
	private static function snapshot_from_payload( array $payload ): array {
		return array(
			'html'       => isset( $payload['html'] ) ? (string) $payload['html'] : '',
			'customHead' => isset( $payload['customHead'] ) ? (string) $payload['customHead'] : '',
			'css'        => isset( $payload['css'] ) ? (string) $payload['css'] : '',
			'js'         => isset( $payload['js'] ) ? (string) $payload['js'] : '',
			'jsMode'     => isset( $payload['jsMode'] ) && 'module' === $payload['jsMode'] ? 'module' : 'classic',
		);
	}

	/** Keep only small context labels permanently. */
	private static function display_contexts( array $payload ): array {
		$contexts = ! empty( $payload['selectedContexts'] ) && is_array( $payload['selectedContexts'] ) ? $payload['selectedContexts'] : array();
		return array_map(
			static function ( array $context ): array {
				return array(
					'lcId'    => isset( $context['lcId'] ) ? self::truncate( (string) $context['lcId'], 128 ) : '',
					'tagName' => isset( $context['tagName'] ) ? self::truncate( (string) $context['tagName'], 64 ) : '',
					'text'    => isset( $context['text'] ) ? self::truncate( trim( (string) $context['text'] ), 160 ) : '',
				);
			},
			$contexts
		);
	}

	/** Determine which editor targets changed. */
	private static function changed_targets( array $before, array $after ): array {
		$targets = array();
		foreach ( array(
			'html'       => 'html',
			'customHead' => 'head',
			'css'        => 'css',
		) as $key => $label ) {
			if ( (string) $before[ $key ] !== (string) $after[ $key ] ) {
				$targets[] = $label;
			}
		}
		if ( (string) $before['js'] !== (string) $after['js'] || (string) $before['jsMode'] !== (string) $after['jsMode'] ) {
			$targets[] = 'js';
		}
		return $targets;
	}

	/** Compute the hash used by the browser editor and Agent tools. */
	private static function editor_hash( array $snapshot ): string {
		return Ai_Tools::compute_base_hash(
			isset( $snapshot['html'] ) ? (string) $snapshot['html'] : '',
			isset( $snapshot['customHead'] ) ? (string) $snapshot['customHead'] : '',
			isset( $snapshot['css'] ) ? (string) $snapshot['css'] : '',
			isset( $snapshot['js'] ) ? (string) $snapshot['js'] : ''
		);
	}

	/** Decode a JSON array safely. */
	private static function decode_array( $value ): array {
		$data = json_decode( (string) $value, true );
		return is_array( $data ) ? $data : array();
	}

	/** Byte-bound stored display text while preserving valid UTF-8 where possible. */
	private static function truncate( string $value, int $bytes ): string {
		if ( strlen( $value ) <= $bytes ) {
			return $value;
		}
		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $bytes, 'UTF-8' );
		}
		return wp_check_invalid_utf8( substr( $value, 0, $bytes ), true );
	}

	/** Current UTC SQL datetime. */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
