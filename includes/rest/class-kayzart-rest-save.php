<?php
/**
 * REST handlers for saving KayzArt content.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for save.
 */
class Rest_Save {

	private const JS_MODE_VALUES = array( 'classic', 'module' );

	/**
	 * Save KayzArt post content and metadata.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function save( \WP_REST_Request $request ): \WP_REST_Response {

		$post_id          = absint( $request->get_param( 'post_id' ) );
		$html             = (string) $request->get_param( 'html' );
		$css_input        = self::sanitize_css_input( (string) $request->get_param( 'css' ) );
		$js_input         = (string) $request->get_param( 'js' );
		$has_js           = $request->has_param( 'js' );
		$has_js_mode      = $request->has_param( 'jsMode' );
		$settings_updates = $request->get_param( 'settingsUpdates' );
		$has_settings     = $request->has_param( 'settingsUpdates' );
		$prepared_updates = null;

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid post type.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		if ( ( $has_js || $has_js_mode ) && ! current_user_can( 'unfiltered_html' ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Permission denied.', 'kayzart-live-code-editor' ),
				),
				403
			);
		}

		$js_mode = self::normalize_js_mode( get_post_meta( $post_id, '_kayzart_js_mode', true ) );
		if ( $has_js_mode ) {
			$js_mode = self::sanitize_js_mode( $request->get_param( 'jsMode' ) );
			if ( null === $js_mode ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid jsMode value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
		}

		if ( $has_settings ) {
			if ( ! is_array( $settings_updates ) ) {
					return new \WP_REST_Response(
						array(
							'ok'    => false,
							'error' => __( 'Invalid payload.', 'kayzart-live-code-editor' ),
						),
						400
					);
			}
			$prepared_updates = Rest_Settings::prepare_updates( $post_id, $settings_updates );
			if ( is_wp_error( $prepared_updates ) ) {
				$error_data = $prepared_updates->get_error_data();
				$status     = is_array( $error_data ) && isset( $error_data['status'] )
					? (int) $error_data['status']
					: 400;
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => $prepared_updates->get_error_message(),
					),
					$status
				);
			}
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $html ),
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $result->get_error_message(),
				),
				400
			);
		}

		update_post_meta( $post_id, '_kayzart_css', wp_slash( $css_input ) );
		if ( $has_js ) {
			update_post_meta( $post_id, '_kayzart_js', wp_slash( $js_input ) );
		}
		if ( $has_js || $has_js_mode ) {
			update_post_meta( $post_id, '_kayzart_js_mode', $js_mode );
		}
		delete_post_meta( $post_id, '_kayzart_js_enabled' );
		delete_post_meta( $post_id, '_kayzart_generated_css' );
		delete_post_meta( $post_id, '_kayzart_tailwind' );
		delete_post_meta( $post_id, '_kayzart_tailwind_locked' );

		if ( $has_settings && is_array( $prepared_updates ) ) {
			$applied_updates = Rest_Settings::apply_prepared_updates( $post_id, $prepared_updates );
			if ( is_wp_error( $applied_updates ) ) {
				$error_data = $applied_updates->get_error_data();
				$status     = is_array( $error_data ) && isset( $error_data['status'] )
					? (int) $error_data['status']
					: 400;
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => $applied_updates->get_error_message(),
					),
					$status
				);
			}
		}

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'settings' => Rest_Settings::build_settings_payload( $post_id ),
			),
			200
		);
	}
	/**
	 * Sanitize CSS input to prevent style tag injection.
	 *
	 * @param string $css Raw CSS input.
	 * @return string
	 */
	private static function sanitize_css_input( string $css ): string {
		if ( '' === $css ) {
			return '';
		}
		return str_ireplace( '</style', '&lt;/style', $css );
	}

	/**
	 * Normalize JavaScript execution mode.
	 *
	 * @param mixed $value Raw mode value.
	 * @return string
	 */
	public static function normalize_js_mode( $value ): string {
		$mode = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( 'module' === $mode ) {
			return 'module';
		}
		if ( 'classic' === $mode || 'auto' === $mode ) {
			return 'classic';
		}
		return 'classic';
	}

	/**
	 * Sanitize JavaScript execution mode when provided by clients.
	 *
	 * @param mixed $value Raw mode value.
	 * @return string|null
	 */
	private static function sanitize_js_mode( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$mode = strtolower( trim( $value ) );
		if ( 'module' === $mode ) {
			return 'module';
		}
		if ( 'classic' === $mode || 'auto' === $mode ) {
			return 'classic';
		}
		return null;
	}
}
