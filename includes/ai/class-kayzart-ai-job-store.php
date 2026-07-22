<?php
/**
 * Persistent storage for asynchronous AI edit jobs.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns job creation, state transitions, events, and retention.
 */
class Ai_Job_Store {
	const MAX_EVENTS       = 300;
	const TIMEOUT_SECONDS  = 600;
	const POLL_INTERVAL_MS = 1000;
	const RETENTION_DAYS   = 7;

	const ACTIVE_STATUSES   = array( 'pending', 'running' );
	const TERMINAL_STATUSES = array( 'completed', 'error', 'canceled', 'timed_out', 'enqueue_failed' );

	/**
	 * Create a job or return its idempotent predecessor.
	 *
	 * @param int    $user_id    Owner user ID.
	 * @param int    $post_id    Target post ID.
	 * @param string $request_id Client request ID.
	 * @param array  $payload    Normalized agent payload.
	 * @return array|\WP_Error Array with job and is_new.
	 */
	public function create( int $user_id, int $post_id, string $request_id, array $payload ) {
		global $wpdb;

		$payload_json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$existing     = $this->get_by_request( $user_id, $request_id );
		if ( $existing ) {
			return $this->resolve_existing( $existing, $post_id, $payload_json );
		}
		if ( $this->get_active_for_post( $post_id ) ) {
			return new \WP_Error( 'kayzart_ai_post_locked', __( 'An AI edit is already active for this post.', 'kayzart-live-code-editor' ), array( 'status' => 409 ) );
		}

		$now      = self::now();
		$job_uuid = wp_generate_uuid4();
		$inserted = $wpdb->insert(
			Ai_Setup::get_jobs_table_name(),
			array(
				'job_uuid'         => $job_uuid,
				'post_id'          => $post_id,
				'user_id'          => $user_id,
				'request_id'       => $request_id,
				'status'           => 'pending',
				'cancel_requested' => 0,
				'payload_json'     => $payload_json,
				'events_json'      => '[]',
				'created_at'       => $now,
				'updated_at'       => $now,
				'deadline_at'      => gmdate( 'Y-m-d H:i:s', time() + self::TIMEOUT_SECONDS ),
				'lock_key'         => 'post:' . $post_id,
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			$existing = $this->get_by_request( $user_id, $request_id );
			if ( $existing ) {
				return $this->resolve_existing( $existing, $post_id, $payload_json );
			}
			$active = $this->get_active_for_post( $post_id );
			if ( $active ) {
				return new \WP_Error( 'kayzart_ai_post_locked', __( 'An AI edit is already active for this post.', 'kayzart-live-code-editor' ), array( 'status' => 409 ) );
			}
			return new \WP_Error( 'kayzart_ai_job_create_failed', __( 'The AI edit job could not be created.', 'kayzart-live-code-editor' ), array( 'status' => 503 ) );
		}

		return array(
			'job'    => $this->get( $job_uuid ),
			'is_new' => true,
		);
	}

	/**
	 * Get a job by UUID.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function get( string $job_uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_jobs_table_name() . ' WHERE job_uuid = %s', $job_uuid ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get a job by its user-scoped idempotency key.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $request_id Request ID.
	 */
	public function get_by_request( int $user_id, string $request_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_jobs_table_name() . ' WHERE user_id = %d AND request_id = %s', $user_id, $request_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get the active job holding a post lock.
	 *
	 * @param int $post_id Post ID.
	 */
	public function get_active_for_post( int $post_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Ai_Setup::get_jobs_table_name() . ' WHERE lock_key = %s', 'post:' . $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Whether any AI job is currently executing on this site. */
	public function has_running_job(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . Ai_Setup::get_jobs_table_name() . " WHERE status = 'running' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Atomically claim a pending job.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function claim( string $job_uuid ): bool {
		global $wpdb;
		$now     = self::now();
		$claimed = 1 === $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = 'running', started_at = %s, updated_at = %s WHERE job_uuid = %s AND status = 'pending' AND cancel_requested = 0 AND deadline_at > %s", $now, $now, $job_uuid, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $claimed ) {
			( new Ai_Timeline_Store() )->update_execution( $job_uuid, 'running' );
		}
		return $claimed;
	}

	/**
	 * Whether a running agent should stop. Also expires overdue jobs.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function is_cancel_requested( string $job_uuid ): bool {
		$this->expire_overdue( $job_uuid );
		$job = $this->get( $job_uuid );
		return ! $job || 'running' !== $job['status'] || ! empty( $job['cancel_requested'] );
	}

	/**
	 * Append one request-correlated event using optimistic locking.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param array  $event    Event data.
	 */
	public function append_event( string $job_uuid, array $event ): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$job = $this->get( $job_uuid );
			if ( ! $job ) {
				return false;
			}
			$events             = self::decode_array( $job['events_json'] );
			$event['requestId'] = $job['request_id'];
			$events[]           = $event;
			if ( count( $events ) > self::MAX_EVENTS ) {
				$events = array_slice( $events, -self::MAX_EVENTS );
			}
			$new     = wp_json_encode( $events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . ' SET events_json = %s, updated_at = %s WHERE job_uuid = %s AND events_json = %s', $new, self::now(), $job_uuid, $job['events_json'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 === $changed ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Complete a running job, unless a competing terminal transition won.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param array  $snapshot Final snapshot.
	 * @param string $summary  Final summary.
	 * @param array  $usage    Token usage.
	 */
	public function complete( string $job_uuid, array $snapshot, string $summary, array $usage ): bool {
		global $wpdb;
		$now     = self::now();
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = 'completed', snapshot_json = %s, usage_json = %s, error = NULL, finished_at = %s, updated_at = %s, lock_key = NULL WHERE job_uuid = %s AND status = 'running' AND cancel_requested = 0 AND deadline_at > %s", wp_json_encode( $snapshot ), wp_json_encode( $usage ), $now, $now, $job_uuid, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 === $changed ) {
			$job     = $this->get( $job_uuid );
			$payload = $job ? json_decode( (string) $job['payload_json'], true ) : null;
			if ( is_array( $payload ) ) {
				$before = array(
					'html'       => isset( $payload['html'] ) ? (string) $payload['html'] : '',
					'customHead' => isset( $payload['customHead'] ) ? (string) $payload['customHead'] : '',
					'css'        => isset( $payload['css'] ) ? (string) $payload['css'] : '',
					'js'         => isset( $payload['js'] ) ? (string) $payload['js'] : '',
					'jsMode'     => isset( $payload['jsMode'] ) && 'module' === $payload['jsMode'] ? 'module' : 'classic',
				);
				( new Ai_Timeline_Store() )->complete( $job_uuid, $before, $snapshot, $summary, $usage );
			}
			$this->append_event(
				$job_uuid,
				array(
					'event'    => 'final',
					'snapshot' => $snapshot,
					'summary'  => $summary,
				)
			);
			return true;
		}
		$this->expire_overdue( $job_uuid );
		return false;
	}

	/**
	 * Request cancellation, immediately terminating pending jobs.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function request_cancel( string $job_uuid ) {
		global $wpdb;
		$job = $this->get( $job_uuid );
		if ( ! $job || in_array( $job['status'], self::TERMINAL_STATUSES, true ) ) {
			return $job;
		}
		$now = self::now();
		if ( 'pending' === $job['status'] ) {
			$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = 'canceled', cancel_requested = 1, finished_at = %s, updated_at = %s, lock_key = NULL WHERE job_uuid = %s AND status = 'pending'", $now, $now, $job_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( 1 === $changed ) {
				( new Ai_Timeline_Store() )->update_execution( $job_uuid, 'canceled' );
				$this->append_event( $job_uuid, array( 'event' => 'canceled' ) );
			}
		} else {
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . ' SET cancel_requested = 1, updated_at = %s WHERE job_uuid = %s AND status = %s', $now, $job_uuid, 'running' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $this->get( $job_uuid );
	}

	/** Mark a running job canceled at an agent boundary.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function mark_canceled( string $job_uuid ): bool {
		return $this->transition_error( $job_uuid, 'canceled', __( 'The AI edit job was canceled.', 'kayzart-live-code-editor' ), false, true );
	}

	/** Mark an active job timed out.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function mark_timed_out( string $job_uuid ): bool {
		return $this->transition_error( $job_uuid, 'timed_out', __( 'The AI edit job timed out.', 'kayzart-live-code-editor' ), true, true );
	}

	/** Mark an active job as failed to enqueue.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function mark_enqueue_failed( string $job_uuid ): bool {
		return $this->transition_error( $job_uuid, 'enqueue_failed', __( 'The AI edit job could not be scheduled.', 'kayzart-live-code-editor' ), true, false );
	}

	/** Mark an active job as errored.
	 *
	 * @param string $job_uuid Job UUID.
	 * @param string $message  Safe error message.
	 * @param bool   $retryable Whether the job may be retried.
	 */
	public function mark_error( string $job_uuid, string $message, bool $retryable ): bool {
		return $this->transition_error( $job_uuid, 'error', $message, $retryable, false );
	}

	/** Correct an active job whose persisted deadline has passed.
	 *
	 * @param string $job_uuid Job UUID.
	 */
	public function expire_overdue( string $job_uuid ): bool {
		global $wpdb;
		$now     = self::now();
		$error   = wp_json_encode(
			array(
				'message'   => __( 'The AI edit job timed out.', 'kayzart-live-code-editor' ),
				'retryable' => true,
			)
		);
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = 'timed_out', cancel_requested = 1, error = %s, finished_at = %s, updated_at = %s, lock_key = NULL WHERE job_uuid = %s AND status IN ('pending','running') AND deadline_at <= %s", $error, $now, $now, $job_uuid, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 === $changed ) {
			( new Ai_Timeline_Store() )->update_execution( $job_uuid, 'timed_out' );
			$this->append_event( $job_uuid, array( 'event' => 'timed_out' ) );
			return true;
		}
		return false;
	}

	/** Delete terminal jobs after the retention period.
	 *
	 * @param int $days Retention days.
	 */
	public function cleanup_terminal( int $days = self::RETENTION_DAYS ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$result = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Ai_Setup::get_jobs_table_name() . " WHERE status IN ('completed','error','canceled','timed_out','enqueue_failed') AND finished_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return false === $result ? 0 : (int) $result;
	}

	/** Cancel every unfinished job during plugin deactivation. */
	public function cancel_all_active(): int {
		global $wpdb;
		$active = $wpdb->get_col( 'SELECT job_uuid FROM ' . Ai_Setup::get_jobs_table_name() . " WHERE status IN ('pending','running')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$now    = self::now();
		$error  = wp_json_encode(
			array(
				'message'   => __( 'The plugin was deactivated.', 'kayzart-live-code-editor' ),
				'retryable' => true,
			)
		);
		$result = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = 'canceled', cancel_requested = 1, error = %s, finished_at = %s, updated_at = %s, lock_key = NULL WHERE status IN ('pending','running')", $error, $now, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $active as $job_uuid ) {
			( new Ai_Timeline_Store() )->update_execution( (string) $job_uuid, 'canceled' );
		}
		return false === $result ? 0 : (int) $result;
	}

	/** Convert a database row to the public REST shape.
	 *
	 * @param array $job Database row.
	 */
	public function to_response( array $job ): array {
		return array(
			'ok'              => true,
			'jobId'           => $job['job_uuid'],
			'requestId'       => $job['request_id'],
			'status'          => $job['status'],
			'events'          => self::decode_array( $job['events_json'] ),
			'snapshot'        => self::decode_nullable( $job['snapshot_json'] ),
			'error'           => self::decode_nullable( $job['error'] ),
			'usage'           => self::decode_nullable( $job['usage_json'] ),
			'cancelRequested' => (bool) $job['cancel_requested'],
			'createdAt'       => self::iso_time( $job['created_at'] ),
			'updatedAt'       => self::iso_time( $job['updated_at'] ),
			'startedAt'       => self::iso_time( $job['started_at'] ),
			'finishedAt'      => self::iso_time( $job['finished_at'] ),
			'pollIntervalMs'  => self::POLL_INTERVAL_MS,
			'timeoutMs'       => self::TIMEOUT_SECONDS * 1000,
		);
	}

	/** Resolve an idempotent create attempt.
	 *
	 * @param array  $job          Existing row.
	 * @param int    $post_id      Requested post ID.
	 * @param string $payload_json Canonical payload JSON.
	 */
	private function resolve_existing( array $job, int $post_id, string $payload_json ) {
		$stored_payload = json_decode( (string) $job['payload_json'], true );
		$next_payload   = json_decode( $payload_json, true );
		if ( is_array( $stored_payload ) ) {
			unset( $stored_payload['recentEditContext'] );
		}
		if ( is_array( $next_payload ) ) {
			unset( $next_payload['recentEditContext'] );
		}
		if ( (int) $job['post_id'] !== $post_id || $stored_payload !== $next_payload ) {
			return new \WP_Error( 'kayzart_ai_request_conflict', __( 'This request ID was already used for different input.', 'kayzart-live-code-editor' ), array( 'status' => 409 ) );
		}
		return array(
			'job'    => $job,
			'is_new' => false,
		);
	}

	/** Perform an active-to-terminal error transition.
	 *
	 * @param string $job_uuid      Job UUID.
	 * @param string $status        Terminal status.
	 * @param string $message       Safe error message.
	 * @param bool   $retryable     Whether retrying may succeed.
	 * @param bool   $request_cancel Whether cancellation was requested.
	 */
	private function transition_error( string $job_uuid, string $status, string $message, bool $retryable, bool $request_cancel ): bool {
		global $wpdb;
		$now     = self::now();
		$error   = wp_json_encode(
			array(
				'message'   => $message,
				'retryable' => $retryable,
			)
		);
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Ai_Setup::get_jobs_table_name() . " SET status = %s, cancel_requested = %d, error = %s, finished_at = %s, updated_at = %s, lock_key = NULL WHERE job_uuid = %s AND status IN ('pending','running')", $status, $request_cancel ? 1 : 0, $error, $now, $now, $job_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 === $changed ) {
			( new Ai_Timeline_Store() )->update_execution( $job_uuid, $status );
			$this->append_event(
				$job_uuid,
				array(
					'event'     => $status,
					'message'   => $message,
					'retryable' => $retryable,
				)
			);
			return true;
		}
		return false;
	}

	/** Decode a JSON array with an empty fallback.
	 *
	 * @param mixed $json JSON value.
	 */
	private static function decode_array( $json ): array {
		$value = json_decode( (string) $json, true );
		return is_array( $value ) ? $value : array();
	}

	/** Decode nullable JSON.
	 *
	 * @param mixed $json JSON value.
	 */
	private static function decode_nullable( $json ) {
		if ( null === $json || '' === $json ) {
			return null;
		}
		$value = json_decode( (string) $json, true );
		return JSON_ERROR_NONE === json_last_error() ? $value : null;
	}

	/** Return the current UTC MySQL timestamp. */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/** Convert a MySQL UTC timestamp to ISO 8601.
	 *
	 * @param mixed $value Timestamp.
	 */
	private static function iso_time( $value ) {
		return empty( $value ) ? null : gmdate( 'c', strtotime( $value . ' UTC' ) );
	}
}
