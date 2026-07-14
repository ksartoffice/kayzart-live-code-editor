<?php
/**
 * REST handlers for Kayzart full-page revision history.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists and reads complete Kayzart snapshots.
 */
class Rest_Revisions {

	/**
	 * List complete snapshots for a post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function index( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id            = absint( $request->get_param( 'post_id' ) );
		$page               = max( 1, absint( $request->get_param( 'page' ) ) );
		$requested_per_page = absint( $request->get_param( 'per_page' ) );
		$per_page           = min( 100, max( 1, $requested_per_page > 0 ? $requested_per_page : 20 ) );
		$supported          = Snapshot::is_supported();

		if ( ! $supported ) {
			return new \WP_REST_Response( self::empty_payload( $page, $per_page ), 200 );
		}

		$all         = self::get_complete_revisions( $post_id );
		$total       = count( $all );
		$total_pages = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
		$offset      = ( $page - 1 ) * $per_page;
		$selected    = array_slice( array_reverse( $all ), $offset, $per_page );
		$summaries   = array();

		foreach ( $selected as $item ) {
			$summaries[] = $item['summary'];
		}

		return new \WP_REST_Response(
			array(
				'ok'               => true,
				'supported'        => true,
				'minVersion'       => Snapshot::MINIMUM_WP_VERSION,
				'currentVersion'   => self::current_wp_version(),
				'revisionsEnabled' => Snapshot::revisions_enabled( $post_id ),
				'canLoad'          => current_user_can( 'unfiltered_html' ),
				'revisions'        => $summaries,
				'pagination'       => array(
					'page'       => $page,
					'perPage'    => $per_page,
					'total'      => $total,
					'totalPages' => $total_pages,
				),
			),
			200
		);
	}

	/**
	 * Read one complete snapshot.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public static function show( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Snapshot::is_supported() ) {
			return new \WP_REST_Response(
				array(
					'ok'         => false,
					'code'       => 'kayzart_revisions_unsupported',
					'error'      => __( 'Full-page revisions require WordPress 6.4 or later.', 'kayzart-live-code-editor' ),
					'minVersion' => Snapshot::MINIMUM_WP_VERSION,
				),
				409
			);
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Permission denied.', 'kayzart-live-code-editor' ),
				),
				403
			);
		}

		$post_id     = absint( $request->get_param( 'post_id' ) );
		$revision_id = absint( $request->get_param( 'revision_id' ) );
		$revision    = wp_get_post_revision( $revision_id );
		$snapshot    = Snapshot::for_revision( $revision_id );

		if ( ! $revision instanceof \WP_Post || (int) $revision->post_parent !== $post_id || null === $snapshot ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Revision not found.', 'kayzart-live-code-editor' ),
				),
				404
			);
		}

		$items   = self::get_complete_revisions( $post_id );
		$summary = null;
		foreach ( $items as $item ) {
			if ( (int) $item['revision']->ID === $revision_id ) {
				$summary = $item['summary'];
				break;
			}
		}
		if ( null === $summary ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Revision not found.', 'kayzart-live-code-editor' ),
				),
				404
			);
		}

		$snapshot['baseHash'] = Snapshot::hash( $snapshot );
		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'revision' => array_merge( $summary, array( 'snapshot' => $snapshot ) ),
			),
			200
		);
	}

	/**
	 * Format a newly-created revision for the save response.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id Revision ID.
	 * @return array<string,mixed>|null
	 */
	public static function summary_for_revision( int $post_id, int $revision_id ): ?array {
		foreach ( self::get_complete_revisions( $post_id ) as $item ) {
			if ( (int) $item['revision']->ID === $revision_id ) {
				return $item['summary'];
			}
		}
		return null;
	}

	/**
	 * Return complete revisions in chronological order, with duplicate hashes removed.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{revision:\WP_Post,snapshot:array<string,string>,summary:array<string,mixed>}>
	 */
	private static function get_complete_revisions( int $post_id ): array {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'orderby' => array(
					'date' => 'ASC',
					'ID'   => 'ASC',
				),
			)
		);
		$items     = array();
		$last_hash = '';
		$previous  = null;

		foreach ( $revisions as $revision ) {
			$snapshot = Snapshot::for_revision( (int) $revision->ID );
			if ( null === $snapshot ) {
				continue;
			}
			$hash = Snapshot::hash( $snapshot );
			if ( $hash === $last_hash ) {
				continue;
			}

			$changed   = null === $previous ? array() : self::changed_sections( $previous, $snapshot );
			$author    = get_userdata( (int) $revision->post_author );
			$summary   = array(
				'id'              => (int) $revision->ID,
				'date'            => mysql_to_rfc3339( (string) $revision->post_date ),
				'dateGmt'         => mysql_to_rfc3339( (string) $revision->post_date_gmt ),
				'author'          => array(
					'id'   => (int) $revision->post_author,
					'name' => $author instanceof \WP_User ? (string) $author->display_name : __( 'Unknown user', 'kayzart-live-code-editor' ),
				),
				'changedSections' => $changed,
				'isFirst'         => null === $previous,
			);
			$items[]   = array(
				'revision' => $revision,
				'snapshot' => $snapshot,
				'summary'  => $summary,
			);
			$previous  = $snapshot;
			$last_hash = $hash;
		}

		return $items;
	}

	/**
	 * Determine which editor sections changed.
	 *
	 * @param array<string,string> $before Previous snapshot.
	 * @param array<string,string> $after  Current snapshot.
	 * @return array<int,string>
	 */
	private static function changed_sections( array $before, array $after ): array {
		$changed = array();
		if ( $before['html'] !== $after['html'] ) {
			$changed[] = 'html';
		}
		if ( $before['css'] !== $after['css'] ) {
			$changed[] = 'css';
		}
		if ( $before['js'] !== $after['js'] || $before['jsMode'] !== $after['jsMode'] ) {
			$changed[] = 'javascript';
		}
		if ( $before['customHead'] !== $after['customHead'] ) {
			$changed[] = 'customHead';
		}
		return $changed;
	}

	/**
	 * Empty list payload for unsupported WordPress versions.
	 *
	 * @param int $page     Current page.
	 * @param int $per_page Items per page.
	 * @return array<string,mixed>
	 */
	private static function empty_payload( int $page, int $per_page ): array {
		return array(
			'ok'               => true,
			'supported'        => false,
			'minVersion'       => Snapshot::MINIMUM_WP_VERSION,
			'currentVersion'   => self::current_wp_version(),
			'revisionsEnabled' => false,
			'canLoad'          => false,
			'revisions'        => array(),
			'pagination'       => array(
				'page'       => $page,
				'perPage'    => $per_page,
				'total'      => 0,
				'totalPages' => 0,
			),
		);
	}

	/**
	 * Return the running WordPress version.
	 */
	private static function current_wp_version(): string {
		global $wp_version;
		return (string) $wp_version;
	}
}
