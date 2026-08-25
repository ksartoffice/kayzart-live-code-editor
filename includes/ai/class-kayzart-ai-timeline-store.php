<?php
/**
 * Persistent user-facing AI edit timeline.
 *
 * @package KayzArt
 */

namespace KayzArt;

// Parameter types are part of each method signature; concise comments keep this storage class readable.
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

/*
 * This class owns a custom table, so every read and write in it is a direct
 * query by definition. The timeline is written and re-read within a single edit
 * as it happens, so a cached read would show the user a stale activity list.
 *
 * Table names come from $wpdb->prefix through Ai_Setup and never from input;
 * every value is bound with $wpdb->prepare(). Plugin Check reads the name
 * through a method call and cannot see that, hence UnescapedDBParameter.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores durable AI edit, save, and restore activities. */
class Ai_Timeline_Store {
	const PAGE_SIZE                   = 50;
	const CONTEXT_LIMIT               = 10;
	const AI_HISTORY_PAGE_SIZE        = 10;
	const FOOTPRINT_MAX_CHANGES       = 2;
	const FOOTPRINT_MAX_CONTENT_CHARS = 600;
	const FOOTPRINT_MAX_JSON_BYTES    = 2400;
	const FOOTPRINT_CONTEXT_LINES     = 1;
	const FOOTPRINT_INLINE_CONTEXT    = 120;

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
	public function complete( string $job_uuid, array $before, array $after, string $summary, array $usage = array() ): bool {
		global $wpdb;
		$model         = isset( $usage['model'] ) ? self::truncate( (string) $usage['model'], 128 ) : '';
		$input_tokens  = isset( $usage['inputTokens'] ) ? max( 0, (int) $usage['inputTokens'] ) : 0;
		$output_tokens = isset( $usage['outputTokens'] ) ? max( 0, (int) $usage['outputTokens'] ) : 0;
		$result        = $wpdb->update(
			Ai_Setup::get_timeline_table_name(),
			array(
				'execution_status' => 'completed',
				'changed_targets'  => wp_json_encode( self::changed_targets( $before, $after ) ),
				'before_hash'      => self::editor_hash( $before ),
				'after_hash'       => self::editor_hash( $after ),
				'summary'          => self::truncate( $summary, 512 ),
				'model'            => '' !== $model ? $model : null,
				'input_tokens'     => $input_tokens,
				'output_tokens'    => $output_tokens,
				'updated_at'       => self::now(),
			),
			array( 'job_uuid' => $job_uuid ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
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
		$now      = self::now();
		$inserted = $wpdb->insert(
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
		if ( false === $inserted ) {
			return false;
		}
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
		$rows  = $wpdb->get_results( "SELECT t.*, j.job_uuid AS retained_job_uuid, j.payload_json AS retained_payload_json, j.snapshot_json AS retained_snapshot_json, j.execution_mode AS retained_execution_mode, j.started_at AS retained_started_at, j.finished_at AS retained_finished_at FROM {$table} t LEFT JOIN {$jobs} j ON j.job_uuid = t.job_uuid WHERE {$where} ORDER BY t.id DESC LIMIT 51", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
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
	public function recent_context( int $post_id, array $current_snapshot = array(), int $limit = self::CONTEXT_LIMIT ): array {
		$limit = max( 0, min( self::CONTEXT_LIMIT, $limit ) );
		if ( 0 === $limit ) {
			return array();
		}
		global $wpdb;
		$timeline = Ai_Setup::get_timeline_table_name();
		$jobs     = Ai_Setup::get_jobs_table_name();
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT t.activity_uuid, t.prompt, t.changed_targets, t.application_status, t.summary, t.created_at, j.job_uuid AS retained_job_uuid, j.payload_json AS retained_payload_json, j.snapshot_json AS retained_snapshot_json FROM {$timeline} t LEFT JOIN {$jobs} j ON j.job_uuid = t.job_uuid WHERE t.post_id = %d AND t.activity_type = 'ai_edit' AND t.execution_status = 'completed' ORDER BY t.id DESC LIMIT %d", $post_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$context  = array_map(
			static function ( array $row ): array {
				return array(
					'versionId'         => (string) $row['activity_uuid'],
					'prompt'            => self::truncate( (string) $row['prompt'], 1024 ),
					'summary'           => self::truncate( (string) $row['summary'], 512 ),
					'changedTargets'    => self::decode_array( $row['changed_targets'] ),
					'applicationStatus' => (string) $row['application_status'],
					'createdAt'         => mysql_to_rfc3339( (string) $row['created_at'] ),
					'detailsAvailable'  => ! empty( $row['retained_job_uuid'] ),
				);
			},
			$rows
		);
		if ( count( $rows ) > 0 && count( $current_snapshot ) > 0 ) {
			$before = json_decode( isset( $rows[0]['retained_payload_json'] ) ? (string) $rows[0]['retained_payload_json'] : '', true );
			$after  = json_decode( isset( $rows[0]['retained_snapshot_json'] ) ? (string) $rows[0]['retained_snapshot_json'] : '', true );
			if ( is_array( $before ) && is_array( $after ) ) {
				$footprint = self::build_edit_footprint( self::snapshot_from_payload( $before ), self::snapshot_from_payload( $after ), self::snapshot_from_payload( $current_snapshot ) );
				if ( count( $footprint ) > 0 ) {
					$context[0]['editFootprint'] = $footprint;
				}
			}
		}
		return array_reverse( $context );
	}

	/** Return completed AI edit metadata for the model-facing history tool. */
	public function list_ai_edits_for_tool( int $post_id, array $args ): array {
		global $wpdb;
		$requested = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (int) $args['limit'] : self::AI_HISTORY_PAGE_SIZE;
		$limit     = max( 1, min( self::AI_HISTORY_PAGE_SIZE, $requested ) );
		$before    = 0;
		if ( ! empty( $args['cursor'] ) ) {
			$cursor         = self::decode_history_cursor( (string) $args['cursor'] );
			$cursor_post_id = is_array( $cursor ) ? (int) ( $cursor['postId'] ?? 0 ) : 0;
			if ( ! is_array( $cursor ) || 'history_list' !== ( $cursor['type'] ?? '' ) || $cursor_post_id !== $post_id || (int) ( $cursor['nextBeforeId'] ?? 0 ) <= 0 ) {
				return array(
					'ok'    => false,
					'error' => 'AI edit history cursor is invalid or belongs to another post.',
				);
			}
			$before = (int) $cursor['nextBeforeId'];
		}
		$timeline = Ai_Setup::get_timeline_table_name();
		$jobs     = Ai_Setup::get_jobs_table_name();
		$where    = $before > 0
			? $wpdb->prepare( "t.post_id = %d AND t.id < %d AND t.activity_type = 'ai_edit' AND t.execution_status = 'completed'", $post_id, $before )
			: $wpdb->prepare( "t.post_id = %d AND t.activity_type = 'ai_edit' AND t.execution_status = 'completed'", $post_id );
		$rows     = $wpdb->get_results( $wpdb->prepare( "SELECT t.id, t.activity_uuid, t.prompt, t.changed_targets, t.application_status, t.summary, t.created_at, j.job_uuid AS retained_job_uuid FROM {$timeline} t LEFT JOIN {$jobs} j ON j.job_uuid = t.job_uuid WHERE {$where} ORDER BY t.id DESC LIMIT %d", $limit + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$has_more = count( $rows ) > $limit;
		$rows     = array_slice( $rows, 0, $limit );
		$next     = $has_more && ! empty( $rows )
			? self::encode_history_cursor(
				array(
					'type'         => 'history_list',
					'postId'       => $post_id,
					'nextBeforeId' => (int) end( $rows )['id'],
				)
			)
			: null;

		return array(
			'ok'         => true,
			'items'      => array_map(
				static function ( array $row ): array {
					return self::history_tool_metadata( $row, 256, 256 );
				},
				$rows
			),
			'hasMore'    => $has_more,
			'nextCursor' => $next,
		);
	}

	/** Return metadata or one bounded source page for a prior completed edit. */
	public function get_ai_edit_for_tool( int $post_id, array $args ): array {
		global $wpdb;
		$version_id = isset( $args['versionId'] ) ? trim( (string) $args['versionId'] ) : '';
		if ( '' === $version_id ) {
			return array(
				'ok'    => false,
				'error' => 'versionId is required.',
			);
		}
		$timeline = Ai_Setup::get_timeline_table_name();
		$jobs     = Ai_Setup::get_jobs_table_name();
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT t.activity_uuid, t.prompt, t.changed_targets, t.application_status, t.summary, t.created_at, j.job_uuid AS retained_job_uuid, j.payload_json AS retained_payload_json, j.snapshot_json AS retained_snapshot_json FROM {$timeline} t LEFT JOIN {$jobs} j ON j.job_uuid = t.job_uuid WHERE t.post_id = %d AND t.activity_uuid = %s AND t.activity_type = 'ai_edit' AND t.execution_status = 'completed' LIMIT 1", $post_id, $version_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return array(
				'ok'    => false,
				'error' => 'AI edit history item was not found.',
			);
		}

		$metadata = self::history_tool_metadata( $row );
		$snapshot = isset( $args['snapshot'] ) ? (string) $args['snapshot'] : '';
		$target   = isset( $args['target'] ) ? (string) $args['target'] : '';
		if ( '' === $snapshot && '' === $target && empty( $args['cursor'] ) ) {
			return array_merge( array( 'ok' => true ), $metadata );
		}
		if ( ! in_array( $snapshot, array( 'before', 'after' ), true ) || ! in_array( $target, array( 'html', 'head', 'css', 'js' ), true ) ) {
			return array(
				'ok'    => false,
				'error' => 'snapshot and target are required to read retained source.',
			);
		}
		if ( empty( $row['retained_job_uuid'] ) ) {
			return array(
				'ok'               => false,
				'error'            => 'AI edit source details are no longer available.',
				'detailsAvailable' => false,
			);
		}

		$raw_snapshot = json_decode( 'after' === $snapshot ? (string) $row['retained_snapshot_json'] : (string) $row['retained_payload_json'], true );
		if ( ! is_array( $raw_snapshot ) ) {
			return array(
				'ok'    => false,
				'error' => 'AI edit source details are invalid.',
			);
		}
		$normalized = self::snapshot_from_payload( $raw_snapshot );
		$key        = 'head' === $target ? 'customHead' : $target;
		$source     = isset( $normalized[ $key ] ) ? (string) $normalized[ $key ] : '';
		$hash       = hash( 'sha256', $source );
		$offset     = 0;
		if ( ! empty( $args['cursor'] ) ) {
			$cursor = self::decode_history_cursor( (string) $args['cursor'] );
			if ( ! is_array( $cursor ) || ( $cursor['versionId'] ?? '' ) !== $version_id || ( $cursor['snapshot'] ?? '' ) !== $snapshot || ( $cursor['target'] ?? '' ) !== $target || ( $cursor['contentHash'] ?? '' ) !== $hash ) {
				return array(
					'ok'    => false,
					'error' => 'History source cursor is invalid or stale.',
				);
			}
			$offset = max( 0, (int) ( $cursor['nextCharOffset'] ?? 0 ) );
		}
		$requested = isset( $args['maxChars'] ) && is_numeric( $args['maxChars'] ) ? (int) $args['maxChars'] : Ai_Tools::DEFAULT_READ_CHARS;
		$max_chars = max( 1, min( Ai_Tools::MAX_READ_CHARS, $requested ) );
		$content   = mb_substr( $source, $offset, $max_chars );
		$next      = $offset + mb_strlen( $content );
		$truncated = $next < mb_strlen( $source );

		return array_merge(
			array( 'ok' => true ),
			$metadata,
			array(
				'snapshot'    => $snapshot,
				'target'      => $target,
				'content'     => $content,
				'contentHash' => $hash,
				'truncated'   => $truncated,
				'nextCursor'  => $truncated ? self::encode_history_cursor(
					array(
						'versionId'      => $version_id,
						'snapshot'       => $snapshot,
						'target'         => $target,
						'contentHash'    => $hash,
						'nextCharOffset' => $next,
					)
				) : null,
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
		$revision_id        = ! empty( $row['revision_id'] ) ? (int) $row['revision_id'] : 0;
		$revision_available = $revision_id > 0 && (bool) wp_get_post_revision( $revision_id );
		$change_stats       = $this->change_stats_from_retained_job( $row );
		$duration_seconds   = $this->duration_from_retained_job( $row );
		$timeout_ms         = $this->timeout_from_retained_job( $row );
		$snapshot_modes     = $this->snapshot_modes_from_retained_job( $row );
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
			'changeStats'       => $change_stats,
			'durationSeconds'   => $duration_seconds,
			'timeoutMs'         => $timeout_ms,
			'model'             => isset( $row['model'] ) && '' !== (string) $row['model'] ? (string) $row['model'] : null,
			'inputTokens'       => isset( $row['input_tokens'] ) && null !== $row['input_tokens'] ? (int) $row['input_tokens'] : null,
			'outputTokens'      => isset( $row['output_tokens'] ) && null !== $row['output_tokens'] ? (int) $row['output_tokens'] : null,
			'beforeHash'        => $row['before_hash'] ? (string) $row['before_hash'] : null,
			'afterHash'         => $row['after_hash'] ? (string) $row['after_hash'] : null,
			'beforeJsMode'      => $snapshot_modes['before'],
			'afterJsMode'       => $snapshot_modes['after'],
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
			'createdAt'         => self::iso_time( $row['created_at'] ),
			'updatedAt'         => self::iso_time( $row['updated_at'] ),
		);
	}

	/** Return the before/after JavaScript modes while snapshots are retained. */
	private function snapshot_modes_from_retained_job( array $row ): array {
		$payload_json  = isset( $row['retained_payload_json'] ) ? (string) $row['retained_payload_json'] : '';
		$snapshot_json = isset( $row['retained_snapshot_json'] ) ? (string) $row['retained_snapshot_json'] : '';
		if ( '' === $payload_json && ! empty( $row['job_uuid'] ) ) {
			$job           = ( new Ai_Job_Store() )->get( (string) $row['job_uuid'] );
			$payload_json  = is_array( $job ) ? (string) $job['payload_json'] : '';
			$snapshot_json = is_array( $job ) ? (string) $job['snapshot_json'] : '';
		}
		$before = json_decode( $payload_json, true );
		$after  = json_decode( $snapshot_json, true );
		return array(
			'before' => is_array( $before ) ? self::snapshot_from_payload( $before )['jsMode'] : null,
			'after'  => is_array( $after ) ? self::snapshot_from_payload( $after )['jsMode'] : null,
		);
	}

	/** Return the retained job timeout without adding timeline schema columns. */
	private function timeout_from_retained_job( array $row ) {
		$has_joined_job = array_key_exists( 'retained_execution_mode', $row );
		$execution_mode = isset( $row['retained_execution_mode'] ) ? (string) $row['retained_execution_mode'] : '';
		if ( ! $has_joined_job && '' === $execution_mode && ! empty( $row['job_uuid'] ) ) {
			$job            = ( new Ai_Job_Store() )->get( (string) $row['job_uuid'] );
			$execution_mode = is_array( $job ) && isset( $job['execution_mode'] ) ? (string) $job['execution_mode'] : '';
		}
		if ( '' === $execution_mode ) {
			return null;
		}
		return ( 'stepwise' === $execution_mode ? Ai_Job_Store::STEPWISE_TIMEOUT_SECONDS : Ai_Job_Store::TIMEOUT_SECONDS ) * 1000;
	}

	/** Build display-only line counts while the full job payload remains retained. */
	private function change_stats_from_retained_job( array $row ) {
		if ( empty( $row['retained_job_uuid'] ) || empty( $row['retained_payload_json'] ) || empty( $row['retained_snapshot_json'] ) ) {
			return null;
		}
		$payload = json_decode( (string) $row['retained_payload_json'], true );
		$after   = json_decode( (string) $row['retained_snapshot_json'], true );
		if ( ! is_array( $payload ) || ! is_array( $after ) ) {
			return null;
		}
		$before  = self::snapshot_from_payload( $payload );
		$stats   = array();
		$targets = array(
			'html' => 'html',
			'head' => 'customHead',
			'css'  => 'css',
			'js'   => 'js',
		);
		foreach ( $targets as $label => $key ) {
			$before_value = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
			$after_value  = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';
			if ( $before_value !== $after_value ) {
				$stats[ $label ] = self::line_change_stats( $before_value, $after_value );
			}
		}
		if ( ( isset( $before['jsMode'] ) ? $before['jsMode'] : 'classic' ) !== ( isset( $after['jsMode'] ) ? $after['jsMode'] : 'classic' ) && ! isset( $stats['js'] ) ) {
			$stats['js'] = array(
				'added'   => 0,
				'removed' => 0,
			);
		}
		return $stats;
	}

	/** Return elapsed worker time while the job record is retained. */
	private function duration_from_retained_job( array $row ) {
		if ( empty( $row['retained_job_uuid'] ) || empty( $row['retained_started_at'] ) || empty( $row['retained_finished_at'] ) ) {
			return null;
		}
		$started  = strtotime( (string) $row['retained_started_at'] . ' UTC' );
		$finished = strtotime( (string) $row['retained_finished_at'] . ' UTC' );
		if ( false === $started || false === $finished || $finished < $started ) {
			return null;
		}
		return $finished - $started;
	}

	/** Build a bounded, current-source-validated footprint for the latest edit. */
	private static function build_edit_footprint( array $before, array $after, array $current ): array {
		$after_hash   = self::editor_hash( $after );
		$current_hash = self::editor_hash( $current );
		$hash_matches = hash_equals( $after_hash, $current_hash );
		$candidates   = array();
		$omitted      = 0;
		$targets      = array(
			'html' => 'html',
			'head' => 'customHead',
			'css'  => 'css',
			'js'   => 'js',
		);

		foreach ( $targets as $label => $key ) {
			$old_source = isset( $before[ $key ] ) ? (string) $before[ $key ] : '';
			$new_source = isset( $after[ $key ] ) ? (string) $after[ $key ] : '';
			if ( $old_source === $new_source ) {
				continue;
			}
			$built      = self::build_source_footprint_changes( $label, $old_source, $new_source );
			$candidates = array_merge( $candidates, $built['changes'] );
			$omitted   += $built['omitted'];
		}

		$before_mode = isset( $before['jsMode'] ) && 'module' === $before['jsMode'] ? 'module' : 'classic';
		$after_mode  = isset( $after['jsMode'] ) && 'module' === $after['jsMode'] ? 'module' : 'classic';
		if ( $before_mode !== $after_mode ) {
			$candidates[] = array(
				'target'          => 'jsMode',
				'kind'            => 'replace',
				'before'          => $before_mode,
				'after'           => $after_mode,
				'beforeHash'      => hash( 'sha256', $before_mode ),
				'afterHash'       => hash( 'sha256', $after_mode ),
				'startLineBefore' => 1,
				'startLineAfter'  => 1,
			);
		}

		$changes       = array();
		$content_chars = 0;
		foreach ( $candidates as $candidate ) {
			$target = (string) $candidate['target'];
			$valid  = $hash_matches;
			if ( ! $valid ) {
				if ( 'jsMode' === $target ) {
					$valid = isset( $current['jsMode'] ) && (string) $current['jsMode'] === (string) $candidate['after'];
				} else {
					$key            = 'head' === $target ? 'customHead' : $target;
					$current_source = isset( $current[ $key ] ) ? (string) $current[ $key ] : '';
					$needle         = (string) $candidate['after'];
					$valid          = '' !== $needle && 1 === substr_count( $current_source, $needle );
				}
			}
			$length = mb_strlen( (string) $candidate['before'] ) + mb_strlen( (string) $candidate['after'] );
			if ( ! $valid || count( $changes ) >= self::FOOTPRINT_MAX_CHANGES || $content_chars + $length > self::FOOTPRINT_MAX_CONTENT_CHARS ) {
				++$omitted;
				continue;
			}
			$changes[]      = $candidate;
			$content_chars += $length;
		}

		if ( 0 === count( $changes ) ) {
			return array();
		}
		$footprint    = array(
			'validation'     => $hash_matches ? 'snapshot_hash' : 'unique_after_match',
			'changes'        => $changes,
			'omittedChanges' => $omitted,
		);
		$change_count = count( $footprint['changes'] );
		$json_bytes   = strlen( (string) wp_json_encode( $footprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		while ( $change_count > 0 && $json_bytes > self::FOOTPRINT_MAX_JSON_BYTES ) {
			array_pop( $footprint['changes'] );
			++$footprint['omittedChanges'];
			$change_count = count( $footprint['changes'] );
			$json_bytes   = strlen( (string) wp_json_encode( $footprint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		return count( $footprint['changes'] ) > 0 ? $footprint : array();
	}

	/** Build exact line-oriented changes for one source target. */
	private static function build_source_footprint_changes( string $target, string $before, string $after ): array {
		$old_lines = self::source_lines( $before );
		$new_lines = self::source_lines( $after );
		$ranges    = self::line_change_ranges( self::line_diff_operations( $old_lines, $new_lines ) );
		$changes   = array();
		$omitted   = 0;

		foreach ( $ranges as $range ) {
			$change = self::line_footprint_change( $target, $old_lines, $new_lines, $range, self::FOOTPRINT_CONTEXT_LINES );
			$length = null === $change ? PHP_INT_MAX : mb_strlen( $change['before'] ) + mb_strlen( $change['after'] );
			if ( $length > self::FOOTPRINT_MAX_CONTENT_CHARS ) {
				$change = self::line_footprint_change( $target, $old_lines, $new_lines, $range, 0 );
				$length = null === $change ? PHP_INT_MAX : mb_strlen( $change['before'] ) + mb_strlen( $change['after'] );
			}
			if ( $length > self::FOOTPRINT_MAX_CONTENT_CHARS && count( $old_lines ) <= 1 && count( $new_lines ) <= 1 ) {
				$change = self::inline_footprint_change( $target, $before, $after );
				$length = null === $change ? PHP_INT_MAX : mb_strlen( $change['before'] ) + mb_strlen( $change['after'] );
			}
			if ( null === $change || $length > self::FOOTPRINT_MAX_CONTENT_CHARS ) {
				++$omitted;
				continue;
			}
			$changes[] = $change;
		}
		return array(
			'changes' => $changes,
			'omitted' => $omitted,
		);
	}

	/** Split a source into exact lines, retaining its original newline bytes. */
	private static function source_lines( string $source ): array {
		if ( '' === $source ) {
			return array();
		}
		$parts = preg_split( '/(\r\n|\n|\r)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return array( $source );
		}
		$lines = array();
		$count = count( $parts );
		for ( $index = 0; $index < $count; $index += 2 ) {
			$line = (string) $parts[ $index ];
			if ( isset( $parts[ $index + 1 ] ) ) {
				$line .= (string) $parts[ $index + 1 ];
			}
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	/** Return a Myers shortest edit script for two line arrays. */
	private static function line_diff_operations( array $old, array $new_lines ): array {
		$old_count = count( $old );
		$new_count = count( $new_lines );
		$max       = $old_count + $new_count;
		$vector    = array( 1 => 0 );
		$trace     = array();
		for ( $distance = 0; $distance <= $max; $distance++ ) {
			$trace[ $distance ] = $vector;
			for ( $diagonal = -$distance; $diagonal <= $distance; $diagonal += 2 ) {
				$down  = isset( $vector[ $diagonal + 1 ] ) ? $vector[ $diagonal + 1 ] : 0;
				$right = isset( $vector[ $diagonal - 1 ] ) ? $vector[ $diagonal - 1 ] : 0;
				$x     = ( -$distance === $diagonal || ( $distance !== $diagonal && $right < $down ) ) ? $down : $right + 1;
				$y     = $x - $diagonal;
				while ( $x < $old_count && $y < $new_count && $old[ $x ] === $new_lines[ $y ] ) {
					++$x;
					++$y;
				}
				$vector[ $diagonal ] = $x;
				if ( $x >= $old_count && $y >= $new_count ) {
					return self::backtrack_line_operations( $trace, $old, $new_lines, $distance );
				}
			}
		}
		return array();
	}

	/** Backtrack a Myers trace into ordered equal/insert/delete operations. */
	private static function backtrack_line_operations( array $trace, array $old, array $new_lines, int $distance ): array {
		$x          = count( $old );
		$y          = count( $new_lines );
		$operations = array();
		for ( $depth = $distance; $depth > 0; $depth-- ) {
			$vector   = $trace[ $depth ];
			$diagonal = $x - $y;
			$down     = isset( $vector[ $diagonal + 1 ] ) ? $vector[ $diagonal + 1 ] : 0;
			$right    = isset( $vector[ $diagonal - 1 ] ) ? $vector[ $diagonal - 1 ] : 0;
			$previous = ( -$depth === $diagonal || ( $depth !== $diagonal && $right < $down ) ) ? $diagonal + 1 : $diagonal - 1;
			$old_x    = isset( $vector[ $previous ] ) ? $vector[ $previous ] : 0;
			$old_y    = $old_x - $previous;
			while ( $x > $old_x && $y > $old_y ) {
				array_unshift( $operations, array( 'type' => 'equal' ) );
				--$x;
				--$y;
			}
			if ( $x === $old_x ) {
				array_unshift( $operations, array( 'type' => 'insert' ) );
				--$y;
			} else {
				array_unshift( $operations, array( 'type' => 'delete' ) );
				--$x;
			}
		}
		while ( $x > 0 && $y > 0 ) {
			array_unshift( $operations, array( 'type' => 'equal' ) );
			--$x;
			--$y;
		}
		return $operations;
	}

	/** Group line operations into changed ranges separated by more than two lines. */
	private static function line_change_ranges( array $operations ): array {
		$records   = array();
		$old_index = 0;
		$new_index = 0;
		foreach ( $operations as $operation ) {
			$type = isset( $operation['type'] ) ? (string) $operation['type'] : '';
			if ( 'equal' === $type ) {
				++$old_index;
				++$new_index;
			} elseif ( 'delete' === $type ) {
				$records[] = array(
					'oldStart' => $old_index,
					'oldEnd'   => $old_index + 1,
					'newStart' => $new_index,
					'newEnd'   => $new_index,
				);
				++$old_index;
			} elseif ( 'insert' === $type ) {
				$records[] = array(
					'oldStart' => $old_index,
					'oldEnd'   => $old_index,
					'newStart' => $new_index,
					'newEnd'   => $new_index + 1,
				);
				++$new_index;
			}
		}
		$ranges = array();
		foreach ( $records as $record ) {
			$last = count( $ranges ) - 1;
			if ( $last >= 0 && $record['oldStart'] - $ranges[ $last ]['oldEnd'] <= 2 && $record['newStart'] - $ranges[ $last ]['newEnd'] <= 2 ) {
				$ranges[ $last ]['oldEnd'] = max( $ranges[ $last ]['oldEnd'], $record['oldEnd'] );
				$ranges[ $last ]['newEnd'] = max( $ranges[ $last ]['newEnd'], $record['newEnd'] );
			} else {
				$ranges[] = $record;
			}
		}
		return $ranges;
	}

	/** Convert one changed line range to exact source snippets. */
	private static function line_footprint_change( string $target, array $old_lines, array $new_lines, array $range, int $context_lines ) {
		$old_start = max( 0, (int) $range['oldStart'] - $context_lines );
		$new_start = max( 0, (int) $range['newStart'] - $context_lines );
		$old_end   = min( count( $old_lines ), (int) $range['oldEnd'] + $context_lines );
		$new_end   = min( count( $new_lines ), (int) $range['newEnd'] + $context_lines );
		$before    = implode( '', array_slice( $old_lines, $old_start, $old_end - $old_start ) );
		$after     = implode( '', array_slice( $new_lines, $new_start, $new_end - $new_start ) );
		if ( '' === $before && '' === $after ) {
			return null;
		}
		$old_changed = (int) $range['oldEnd'] - (int) $range['oldStart'];
		$new_changed = (int) $range['newEnd'] - (int) $range['newStart'];
		return self::footprint_change( $target, 0 === $old_changed ? 'insert' : ( 0 === $new_changed ? 'delete' : 'replace' ), $before, $after, $old_start + 1, $new_start + 1 );
	}

	/** Build a bounded exact hunk for minified single-line sources. */
	private static function inline_footprint_change( string $target, string $before, string $after ) {
		$old_bytes    = strlen( $before );
		$new_bytes    = strlen( $after );
		$prefix_bytes = 0;
		$byte_limit   = min( $old_bytes, $new_bytes );
		while ( $prefix_bytes < $byte_limit && $before[ $prefix_bytes ] === $after[ $prefix_bytes ] ) {
			++$prefix_bytes;
		}
		while ( $prefix_bytes > 0 && $prefix_bytes < $old_bytes && 0x80 === ( ord( $before[ $prefix_bytes ] ) & 0xC0 ) ) {
			--$prefix_bytes;
		}
		$suffix_bytes = 0;
		while ( $suffix_bytes < $old_bytes - $prefix_bytes && $suffix_bytes < $new_bytes - $prefix_bytes && $before[ $old_bytes - $suffix_bytes - 1 ] === $after[ $new_bytes - $suffix_bytes - 1 ] ) {
			++$suffix_bytes;
		}
		while ( $suffix_bytes > 0 && ( 0x80 === ( ord( $before[ $old_bytes - $suffix_bytes ] ) & 0xC0 ) || 0x80 === ( ord( $after[ $new_bytes - $suffix_bytes ] ) & 0xC0 ) ) ) {
			--$suffix_bytes;
		}
		$prefix      = mb_strlen( substr( $before, 0, $prefix_bytes ) );
		$old_length  = mb_strlen( $before );
		$new_length  = mb_strlen( $after );
		$old_suffix  = mb_strlen( substr( $before, $old_bytes - $suffix_bytes ) );
		$new_suffix  = mb_strlen( substr( $after, $new_bytes - $suffix_bytes ) );
		$old_changed = mb_substr( $before, $prefix, $old_length - $prefix - $old_suffix );
		$new_changed = mb_substr( $after, $prefix, $new_length - $prefix - $new_suffix );
		$changed_len = mb_strlen( $old_changed ) + mb_strlen( $new_changed );
		if ( $changed_len > self::FOOTPRINT_MAX_CONTENT_CHARS ) {
			return null;
		}
		$context_each = min( self::FOOTPRINT_INLINE_CONTEXT, (int) floor( ( self::FOOTPRINT_MAX_CONTENT_CHARS - $changed_len ) / 4 ) );
		$leading      = mb_substr( $before, max( 0, $prefix - $context_each ), min( $context_each, $prefix ) );
		$trailing     = $old_suffix > 0 ? mb_substr( $before, $old_length - $old_suffix, min( $context_each, $old_suffix ) ) : '';
		$old_snippet  = $leading . $old_changed . $trailing;
		$new_snippet  = $leading . $new_changed . $trailing;
		$kind         = '' === $old_changed ? 'insert' : ( '' === $new_changed ? 'delete' : 'replace' );
		return self::footprint_change( $target, $kind, $old_snippet, $new_snippet, 1, 1 );
	}

	/** Normalize one footprint change and attach integrity hashes. */
	private static function footprint_change( string $target, string $kind, string $before, string $after, int $old_line, int $new_line ): array {
		return array(
			'target'          => $target,
			'kind'            => $kind,
			'before'          => $before,
			'after'           => $after,
			'beforeHash'      => hash( 'sha256', $before ),
			'afterHash'       => hash( 'sha256', $after ),
			'startLineBefore' => $old_line,
			'startLineAfter'  => $new_line,
		);
	}

	/** Count changed lines with a Myers shortest-edit-script diff. */
	private static function line_change_stats( string $before, string $after ): array {
		$added   = 0;
		$removed = 0;
		$old     = self::split_lines( $before );
		$new     = self::split_lines( $after );
		foreach ( self::line_diff_operations( $old, $new ) as $operation ) {
			if ( 'insert' === $operation['type'] ) {
				++$added;
			} elseif ( 'delete' === $operation['type'] ) {
				++$removed;
			}
		}
		return array(
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/** Split text into display lines without treating a trailing newline as an extra line. */
	private static function split_lines( string $value ): array {
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		if ( '' === $value ) {
			return array();
		}
		return explode( "\n", rtrim( $value, "\n" ) );
	}

	/** Normalize the editable snapshot fields from an agent payload. */
	private static function snapshot_from_payload( array $payload ): array {
		$snapshot             = array(
			'html'       => isset( $payload['html'] ) ? (string) $payload['html'] : '',
			'customHead' => isset( $payload['customHead'] ) ? (string) $payload['customHead'] : '',
			'css'        => isset( $payload['css'] ) ? (string) $payload['css'] : '',
			'js'         => isset( $payload['js'] ) ? (string) $payload['js'] : '',
			'jsMode'     => isset( $payload['jsMode'] ) && 'module' === $payload['jsMode'] ? 'module' : 'classic',
		);
		$snapshot['baseHash'] = self::editor_hash( $snapshot );
		return $snapshot;
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

	/** Build a compact history-tool record from a timeline query row. */
	private static function history_tool_metadata( array $row, int $prompt_bytes = 1024, int $summary_bytes = 512 ): array {
		return array(
			'versionId'         => (string) $row['activity_uuid'],
			'prompt'            => self::truncate( (string) $row['prompt'], $prompt_bytes ),
			'summary'           => self::truncate( (string) $row['summary'], $summary_bytes ),
			'changedTargets'    => self::decode_array( $row['changed_targets'] ),
			'applicationStatus' => (string) $row['application_status'],
			'createdAt'         => mysql_to_rfc3339( (string) $row['created_at'] ),
			'detailsAvailable'  => ! empty( $row['retained_job_uuid'] ),
		);
	}

	/** Encode a history source cursor without exposing database identifiers. */
	private static function encode_history_cursor( array $cursor ): string {
		$json = wp_json_encode( $cursor );
		return rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination cursor, not executable code.
	}

	/** Decode an opaque history source cursor. */
	private static function decode_history_cursor( string $cursor ) {
		$padding = strlen( $cursor ) % 4;
		if ( $padding > 0 ) {
			$cursor .= str_repeat( '=', 4 - $padding );
		}
		$json = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the opaque pagination cursor above.
		if ( false === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
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

	/** Convert a stored UTC MySQL timestamp to ISO 8601. */
	private static function iso_time( $value ): string {
		return gmdate( 'c', strtotime( (string) $value . ' UTC' ) );
	}

	/** Current UTC SQL datetime. */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
