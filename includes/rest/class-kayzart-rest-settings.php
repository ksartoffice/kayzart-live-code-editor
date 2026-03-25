<?php
/**
 * REST settings handlers for KayzArt.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for updating KayzArt settings.
 */
class Rest_Settings {

	private const TEMPLATE_MODE_VALUES         = array( 'default', 'standalone', 'theme' );
	private const DEFAULT_TEMPLATE_MODE_VALUES = array( 'standalone', 'theme' );
	/**
	 * Normalize template mode value stored in post meta.
	 *
	 * @param mixed $value Raw template mode.
	 * @return string
	 */
	private static function normalize_template_mode( $value ): string {
		$template_mode = is_string( $value ) ? $value : '';
		return in_array( $template_mode, self::TEMPLATE_MODE_VALUES, true ) ? $template_mode : 'default';
	}

	/**
	 * Normalize default template mode value stored in options.
	 *
	 * @param mixed $value Raw template mode.
	 * @return string
	 */
	private static function normalize_default_template_mode( $value ): string {
		$template_mode = is_string( $value ) ? $value : '';
		return in_array( $template_mode, self::DEFAULT_TEMPLATE_MODE_VALUES, true ) ? $template_mode : 'theme';
	}

	/**
	 * Build settings payload for the admin UI.
	 *
	 * @param int $post_id KayzArt post ID.
	 * @return array
	 */
	public static function build_settings_payload( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$highlight_meta         = get_post_meta( $post_id, '_kayzart_live_highlight', true );
		$live_highlight_enabled = '' === $highlight_meta ? true : rest_sanitize_boolean( $highlight_meta );
		$single_page_enabled    = Post_Type::is_single_page_enabled( $post_id );
		$template_mode_meta     = get_post_meta( $post_id, '_kayzart_template_mode', true );
		$template_mode          = self::normalize_template_mode( $template_mode_meta );
		$default_template_mode  = self::normalize_default_template_mode(
			get_option( Admin::OPTION_DEFAULT_TEMPLATE_MODE, 'theme' )
		);

		return array(
			'title'                => (string) $post->post_title,
			'slug'                 => (string) $post->post_name,
			'status'               => (string) $post->post_status,
			'viewUrl'              => $single_page_enabled ? (string) get_permalink( $post_id ) : '',
			'templateMode'         => $template_mode,
			'defaultTemplateMode'  => $default_template_mode,
			'shadowDomEnabled'     => '1' === get_post_meta( $post_id, '_kayzart_shadow_dom', true ),
			'shortcodeEnabled'     => '1' === get_post_meta( $post_id, '_kayzart_shortcode_enabled', true ),
			'singlePageEnabled'    => $single_page_enabled,
			'liveHighlightEnabled' => $live_highlight_enabled,
			'canEditJs'            => current_user_can( 'unfiltered_html' ),
			'externalScripts'      => External_Scripts::get_external_scripts( $post_id, Limits::MAX_EXTERNAL_SCRIPTS ),
			'externalStyles'       => External_Styles::get_external_styles( $post_id, Limits::MAX_EXTERNAL_STYLES ),
			'externalScriptsMax'   => Limits::MAX_EXTERNAL_SCRIPTS,
			'externalStylesMax'    => Limits::MAX_EXTERNAL_STYLES,
		);
	}

	/**
	 * Update KayzArt settings from the admin UI.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function update_settings( \WP_REST_Request $request ): \WP_REST_Response {

		$post_id = absint( $request->get_param( 'post_id' ) );
		$updates = $request->get_param( 'updates' );

		if ( ! is_array( $updates ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid payload.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		$prepared = self::prepare_updates( $post_id, $updates );
		if ( is_wp_error( $prepared ) ) {
			return self::build_error_response( $prepared );
		}

		$applied = self::apply_prepared_updates( $post_id, $prepared );
		if ( is_wp_error( $applied ) ) {
			return self::build_error_response( $applied );
		}

		return new \WP_REST_Response(
			array(
				'ok'       => true,
				'settings' => self::build_settings_payload( $post_id ),
			),
			200
		);
	}

	/**
	 * Prepare and validate settings updates without writing to the database.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates Raw updates payload.
	 * @return array|\WP_Error
	 */
	public static function prepare_updates( int $post_id, array $updates ) {

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'kayzart_post_not_found',
				__( 'Post not found.', 'kayzart-live-code-editor' ),
				array( 'status' => 404 )
			);
		}

		$prepared = array(
			'post_update'  => array( 'ID' => $post_id ),
			'meta_updates' => array(),
			'meta_deletes' => array(),
		);

		$status           = isset( $updates['status'] ) ? sanitize_key( (string) $updates['status'] ) : null;
		$visibility       = isset( $updates['visibility'] ) ? sanitize_key( (string) $updates['visibility'] ) : null;
		$valid_statuses   = array( 'draft', 'pending', 'private', 'publish' );
		$valid_visibility = array( 'public', 'private' );

		if ( null !== $status && ! in_array( $status, $valid_statuses, true ) ) {
			$status = null;
		}

		if ( null !== $visibility && ! in_array( $visibility, $valid_visibility, true ) ) {
			$visibility = null;
		}

		if ( isset( $updates['title'] ) ) {
			$prepared['post_update']['post_title'] = sanitize_text_field( (string) $updates['title'] );
		}

		if ( array_key_exists( 'slug', $updates ) ) {
			$prepared['post_update']['post_name'] = sanitize_title( (string) $updates['slug'] );
		}

		if ( 'private' === $visibility ) {
			$prepared['post_update']['post_status']   = 'private';
			$prepared['post_update']['post_password'] = '';
		} elseif ( $status ) {
				$prepared['post_update']['post_status'] = $status;
		}

		if ( isset( $prepared['post_update']['post_status'] ) && 'publish' === $prepared['post_update']['post_status'] ) {
			if ( ! current_user_can( 'publish_post', $post_id ) ) {
				return new \WP_Error(
					'kayzart_permission_denied',
					__( 'Permission denied.', 'kayzart-live-code-editor' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( array_key_exists( 'shadowDomEnabled', $updates ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new \WP_Error(
					'kayzart_permission_denied',
					__( 'Permission denied.', 'kayzart-live-code-editor' ),
					array( 'status' => 403 )
				);
			}
			$shadow_dom_enabled                              = rest_sanitize_boolean( $updates['shadowDomEnabled'] );
			$prepared['meta_updates']['_kayzart_shadow_dom'] = $shadow_dom_enabled ? '1' : '0';
		}

		if ( array_key_exists( 'templateMode', $updates ) ) {
			$prepared['meta_updates']['_kayzart_template_mode'] = self::normalize_template_mode( $updates['templateMode'] );
		}

		if ( array_key_exists( 'shortcodeEnabled', $updates ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new \WP_Error(
					'kayzart_permission_denied',
					__( 'Permission denied.', 'kayzart-live-code-editor' ),
					array( 'status' => 403 )
				);
			}
			$shortcode_enabled                                      = rest_sanitize_boolean( $updates['shortcodeEnabled'] );
			$prepared['meta_updates']['_kayzart_shortcode_enabled'] = $shortcode_enabled ? '1' : '0';
		}

		if ( array_key_exists( 'singlePageEnabled', $updates ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
					return new \WP_Error(
						'kayzart_permission_denied',
						__( 'Permission denied.', 'kayzart-live-code-editor' ),
						array( 'status' => 403 )
					);
			}
			$single_page_enabled                                      = rest_sanitize_boolean( $updates['singlePageEnabled'] );
			$prepared['meta_updates']['_kayzart_single_page_enabled'] = $single_page_enabled ? '1' : '0';
		}

		if ( array_key_exists( 'liveHighlightEnabled', $updates ) ) {
			$live_highlight_enabled                                  = rest_sanitize_boolean( $updates['liveHighlightEnabled'] );
				$prepared['meta_updates']['_kayzart_live_highlight'] = $live_highlight_enabled ? '1' : '0';
		}

		if ( array_key_exists( 'externalScripts', $updates ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new \WP_Error(
					'kayzart_permission_denied',
					__( 'Permission denied.', 'kayzart-live-code-editor' ),
					array( 'status' => 403 )
				);
			}
			if ( ! is_array( $updates['externalScripts'] ) ) {
				return new \WP_Error(
					'kayzart_invalid_external_scripts',
					__( 'Invalid external scripts payload.', 'kayzart-live-code-editor' ),
					array( 'status' => 400 )
				);
			}

			$raw_scripts    = array_values( $updates['externalScripts'] );
			$string_scripts = array_values( array_filter( $raw_scripts, 'is_string' ) );
			$error          = null;
			$sanitized      = External_Scripts::validate_list(
				$string_scripts,
				Limits::MAX_EXTERNAL_SCRIPTS,
				$error
			);
			if ( null === $sanitized ) {
					return new \WP_Error(
						'kayzart_invalid_external_scripts',
						null !== $error ? $error : __( 'External scripts must be valid https:// URLs.', 'kayzart-live-code-editor' ),
						array( 'status' => 400 )
					);
			}

			if ( empty( $sanitized ) ) {
					$prepared['meta_deletes'][] = '_kayzart_external_scripts';
			} else {
				$prepared['meta_updates']['_kayzart_external_scripts'] = wp_json_encode( $sanitized );
			}
		}

		if ( array_key_exists( 'externalStyles', $updates ) ) {
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				return new \WP_Error(
					'kayzart_permission_denied',
					__( 'Permission denied.', 'kayzart-live-code-editor' ),
					array( 'status' => 403 )
				);
			}
			if ( ! is_array( $updates['externalStyles'] ) ) {
				return new \WP_Error(
					'kayzart_invalid_external_styles',
					__( 'Invalid external styles payload.', 'kayzart-live-code-editor' ),
					array( 'status' => 400 )
				);
			}

			$raw_styles    = array_values( $updates['externalStyles'] );
			$string_styles = array_values( array_filter( $raw_styles, 'is_string' ) );
			$error         = null;
			$sanitized     = External_Styles::validate_list(
				$string_styles,
				Limits::MAX_EXTERNAL_STYLES,
				$error
			);
			if ( null === $sanitized ) {
					return new \WP_Error(
						'kayzart_invalid_external_styles',
						null !== $error ? $error : __( 'External styles must be valid https:// URLs.', 'kayzart-live-code-editor' ),
						array( 'status' => 400 )
					);
			}

			if ( empty( $sanitized ) ) {
					$prepared['meta_deletes'][] = '_kayzart_external_styles';
			} else {
					$prepared['meta_updates']['_kayzart_external_styles'] = wp_json_encode( $sanitized );
			}
		}

		return $prepared;
	}

	/**
	 * Apply prepared updates generated by prepare_updates().
	 *
	 * @param int   $post_id Post ID.
	 * @param array $prepared Prepared payload.
	 * @return true|\WP_Error
	 */
	public static function apply_prepared_updates( int $post_id, array $prepared ) {

		$post_update = $prepared['post_update'] ?? array( 'ID' => $post_id );
		if ( ! is_array( $post_update ) ) {
			$post_update = array( 'ID' => $post_id );
		}
		if ( 1 < count( $post_update ) ) {
			$result = wp_update_post( $post_update, true );
			if ( is_wp_error( $result ) ) {
				return new \WP_Error(
					'kayzart_update_failed',
					$result->get_error_message(),
					array( 'status' => 400 )
				);
			}
		}

		$meta_updates = $prepared['meta_updates'] ?? array();
		if ( is_array( $meta_updates ) ) {
			foreach ( $meta_updates as $meta_key => $meta_value ) {
				update_post_meta( $post_id, (string) $meta_key, $meta_value );
			}
		}

		$meta_deletes = $prepared['meta_deletes'] ?? array();
		if ( is_array( $meta_deletes ) ) {
			foreach ( $meta_deletes as $meta_key ) {
				delete_post_meta( $post_id, (string) $meta_key );
			}
		}

		return true;
	}

	/**
	 * Convert a WP_Error into a REST response.
	 *
	 * @param \WP_Error $error Error object.
	 * @return \WP_REST_Response
	 */
	private static function build_error_response( \WP_Error $error ): \WP_REST_Response {

		$status = self::resolve_error_status( $error );
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $error->get_error_message(),
			),
			$status
		);
	}

	/**
	 * Resolve HTTP status from WP_Error.
	 *
	 * @param \WP_Error $error Error object.
	 * @return int
	 */
	private static function resolve_error_status( \WP_Error $error ): int {

		$data = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return (int) $data['status'];
		}
		return 400;
	}
}
