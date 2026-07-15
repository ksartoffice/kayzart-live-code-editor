<?php
/**
 * REST API for the durable AI editing timeline.
 *
 * @package KayzArt
 */

namespace KayzArt;

// REST request types are declared in every callback signature.
// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lists timeline activities and resolves retained snapshots. */
class Rest_Ai_Timeline {
	/** Register timeline routes. */
	public static function register_routes(): void {
		register_rest_route(
			'kayzart/v1',
			'/ai/timeline',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'index' ),
				'permission_callback' => array( __CLASS__, 'post_permission' ),
			)
		);
		register_rest_route(
			'kayzart/v1',
			'/ai/timeline/(?P<activity_id>\d+)/snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'snapshot' ),
				'permission_callback' => array( __CLASS__, 'activity_permission' ),
			)
		);
		register_rest_route(
			'kayzart/v1',
			'/ai/timeline/(?P<activity_id>\d+)/application',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'application' ),
				'permission_callback' => array( __CLASS__, 'activity_permission' ),
			)
		);
		register_rest_route(
			'kayzart/v1',
			'/ai/timeline/(?P<activity_id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => array( __CLASS__, 'activity_permission' ),
			)
		);
	}

	/** Return one stable page of a post timeline. */
	public static function index( \WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$before  = absint( $request->get_param( 'before' ) );
		$data    = ( new Ai_Timeline_Store() )->list_for_post( $post_id, $before );
		return rest_ensure_response( array_merge( array( 'ok' => true ), $data ) );
	}

	/** Return a retained before or after snapshot. */
	public static function snapshot( \WP_REST_Request $request ) {
		$target = self::target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$store    = new Ai_Timeline_Store();
		$activity = $store->get( absint( $request['activity_id'] ) );
		$snapshot = $activity ? $store->get_snapshot( $activity, $target ) : null;
		if ( ! $snapshot ) {
			return new \WP_Error( 'kayzart_ai_snapshot_expired', __( 'The AI edit snapshot is no longer available.', 'kayzart-live-code-editor' ), array( 'status' => 410 ) );
		}
		return rest_ensure_response(
			array(
				'ok'       => true,
				'snapshot' => $snapshot,
			)
		);
	}

	/** Persist the browser's successful apply/revert state. */
	public static function application( \WP_REST_Request $request ) {
		$input  = $request->get_json_params();
		$status = is_array( $input ) && isset( $input['status'] ) ? (string) $input['status'] : '';
		if ( ! in_array( $status, array( 'applied', 'reverted' ), true ) ) {
			return new \WP_Error( 'kayzart_ai_application_invalid', __( 'The AI edit application state is invalid.', 'kayzart-live-code-editor' ), array( 'status' => 400 ) );
		}
		$store = new Ai_Timeline_Store();
		$id    = absint( $request['activity_id'] );
		if ( ! $store->update_application( $id, $status ) ) {
			return new \WP_Error( 'kayzart_ai_application_failed', __( 'The AI edit application state could not be saved.', 'kayzart-live-code-editor' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'ok'   => true,
				'item' => $store->to_response( $store->get( $id ) ),
			)
		);
	}

	/** Return a retained snapshot and add a durable restore activity. */
	public static function restore( \WP_REST_Request $request ) {
		$target = self::target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		$store    = new Ai_Timeline_Store();
		$activity = $store->get( absint( $request['activity_id'] ) );
		$snapshot = $activity ? $store->get_snapshot( $activity, $target ) : null;
		if ( ! $snapshot ) {
			return new \WP_Error( 'kayzart_ai_snapshot_expired', __( 'The AI edit snapshot is no longer available.', 'kayzart-live-code-editor' ), array( 'status' => 410 ) );
		}
		$store->update_application( (int) $activity['id'], 'after' === $target ? 'applied' : 'reverted' );
		$record = $store->record_restore( $activity, get_current_user_id(), $target );
		return rest_ensure_response(
			array(
				'ok'       => true,
				'snapshot' => $snapshot,
				'item'     => $record ? $store->to_response( $record ) : null,
			)
		);
	}

	/** Authorize a post timeline without exposing inaccessible posts. */
	public static function post_permission( \WP_REST_Request $request ) {
		$auth = self::authenticate( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		return self::can_access_post( absint( $request->get_param( 'post_id' ) ) );
	}

	/** Authorize an activity through its target post. */
	public static function activity_permission( \WP_REST_Request $request ) {
		$auth = self::authenticate( $request );
		if ( true !== $auth ) {
			return $auth;
		}
		$activity = ( new Ai_Timeline_Store() )->get( absint( $request['activity_id'] ) );
		if ( ! $activity || true !== self::can_access_post( (int) $activity['post_id'] ) ) {
			return new \WP_Error( 'kayzart_ai_timeline_not_found', __( 'AI edit history not found.', 'kayzart-live-code-editor' ), array( 'status' => 404 ) );
		}
		return true;
	}

	/** Require a logged-in REST nonce. */
	private static function authenticate( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$nonce = sanitize_text_field( wp_unslash( (string) $request->get_header( 'X-WP-Nonce' ) ) );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'kayzart_invalid_nonce', __( 'Permission denied.', 'kayzart-live-code-editor' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** Shared post-level capability check. */
	private static function can_access_post( int $post_id ): bool {
		return $post_id > 0 && Post_Type::is_editor_enabled_post( $post_id ) && current_user_can( Ai_Setup::CAPABILITY ) && current_user_can( 'edit_post', $post_id );
	}

	/** Normalize before/after from query or JSON. */
	private static function target( \WP_REST_Request $request ) {
		$input  = $request->get_json_params();
		$target = is_array( $input ) && isset( $input['target'] ) ? (string) $input['target'] : (string) $request->get_param( 'target' );
		if ( ! in_array( $target, array( 'before', 'after' ), true ) ) {
			return new \WP_Error( 'kayzart_ai_restore_target_invalid', __( 'The AI edit restore target is invalid.', 'kayzart-live-code-editor' ), array( 'status' => 400 ) );
		}
		return $target;
	}
}
// phpcs:enable Squiz.Commenting.FunctionComment.MissingParamTag
