<?php
/**
 * REST handler for setup wizard.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for KayzArt setup.
 */
class Rest_Setup {
	/**
	 * Complete KayzArt setup.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function setup_mode( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid post type.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		delete_post_meta( $post_id, '_kayzart_setup_required' );
		delete_post_meta( $post_id, '_kayzart_tailwind' );
		delete_post_meta( $post_id, '_kayzart_tailwind_locked' );
		delete_post_meta( $post_id, '_kayzart_generated_css' );

		return new \WP_REST_Response(
			array(
				'ok' => true,
			),
			200
		);
	}
}
