<?php
/**
 * REST handler for importing KayzArt data.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for KayzArt import.
 */
class Rest_Import {

	/**
	 * Import a KayzArt JSON payload into a post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function import_payload( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$payload = $request->get_param( 'payload' );

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid post type.', 'kayzart-live-code-editor' ),
				),
				400
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

		if ( ! is_array( $payload ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid import payload.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		$version = isset( $payload['version'] ) ? (int) $payload['version'] : 0;
		if ( 1 !== $version ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Unsupported import version.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		if ( ! array_key_exists( 'html', $payload ) || ! is_string( $payload['html'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid HTML value.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		if ( ! array_key_exists( 'css', $payload ) || ! is_string( $payload['css'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid CSS value.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		if ( ! array_key_exists( 'tailwindEnabled', $payload ) || ! is_bool( $payload['tailwindEnabled'] ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid tailwindEnabled value.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}
		$js_input = '';
		if ( array_key_exists( 'js', $payload ) ) {
			if ( ! is_string( $payload['js'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid JavaScript value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$js_input = $payload['js'];
		}

		$js_mode = 'classic';
		if ( array_key_exists( 'jsMode', $payload ) ) {
			if ( ! is_string( $payload['jsMode'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid jsMode value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$raw_js_mode = strtolower( trim( $payload['jsMode'] ) );
			if ( ! in_array( $raw_js_mode, array( 'classic', 'module', 'auto' ), true ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid jsMode value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$js_mode = Rest_Save::normalize_js_mode( $raw_js_mode );
		}

		$shadow_dom_enabled = false;
		if ( array_key_exists( 'shadowDomEnabled', $payload ) ) {
			if ( ! is_bool( $payload['shadowDomEnabled'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid shadowDomEnabled value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$shadow_dom_enabled = $payload['shadowDomEnabled'];
		}

		$shortcode_enabled = false;
		if ( array_key_exists( 'shortcodeEnabled', $payload ) ) {
			if ( ! is_bool( $payload['shortcodeEnabled'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid shortcodeEnabled value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$shortcode_enabled = $payload['shortcodeEnabled'];
		}

		$single_page_enabled = null;
		if ( array_key_exists( 'singlePageEnabled', $payload ) ) {
			if ( ! is_bool( $payload['singlePageEnabled'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid singlePageEnabled value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$single_page_enabled = $payload['singlePageEnabled'];
		}

		$live_highlight_enabled = null;
		if ( array_key_exists( 'liveHighlightEnabled', $payload ) ) {
			if ( ! is_bool( $payload['liveHighlightEnabled'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid liveHighlightEnabled value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$live_highlight_enabled = $payload['liveHighlightEnabled'];
		}

		$generated_css_input = '';
		if ( array_key_exists( 'generatedCss', $payload ) ) {
			if ( ! is_string( $payload['generatedCss'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid generatedCss value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
			$generated_css_input = self::sanitize_css_input( $payload['generatedCss'] );
		}

		$external_scripts = array();
		if ( array_key_exists( 'externalScripts', $payload ) ) {
			if ( ! is_array( $payload['externalScripts'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid externalScripts value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}

			$error            = null;
			$external_scripts = External_Scripts::validate_list(
				array_values( $payload['externalScripts'] ),
				Limits::MAX_EXTERNAL_SCRIPTS,
				$error
			);
			if ( null === $external_scripts ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => null !== $error ? $error : __( 'Invalid externalScripts value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
		}

		$external_styles = array();
		if ( array_key_exists( 'externalStyles', $payload ) ) {
			if ( ! is_array( $payload['externalStyles'] ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => __( 'Invalid externalStyles value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}

			$error           = null;
			$external_styles = External_Styles::validate_list(
				array_values( $payload['externalStyles'] ),
				Limits::MAX_EXTERNAL_STYLES,
				$error
			);
			if ( null === $external_styles ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => null !== $error ? $error : __( 'Invalid externalStyles value.', 'kayzart-live-code-editor' ),
					),
					400
				);
			}
		}

		$html             = $payload['html'];
		$css_input        = self::sanitize_css_input( $payload['css'] );
		$tailwind_enabled = $payload['tailwindEnabled'];
		$result           = wp_update_post(
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

		$compiled_css = '';
		if ( $tailwind_enabled ) {
			if ( '' !== $generated_css_input ) {
				$compiled_css = Rest_Save::append_tailwind_shadow_fallbacks( $generated_css_input );
			} else {
				try {
					$compiled_css = tw::generate(
						array(
							'content' => $html,
							'css'     => $css_input,
						)
					);
					$compiled_css = Rest_Save::append_tailwind_shadow_fallbacks( $compiled_css );
				} catch ( \Throwable $e ) {
					return new \WP_REST_Response(
						array(
							'ok'    => false,
							'error' => sprintf(
								/* translators: %s: error message. */
								__( 'Tailwind compile failed: %s', 'kayzart-live-code-editor' ),
								$e->getMessage()
							),
						),
						500
					);
				}
			}
		}

		update_post_meta( $post_id, '_kayzart_css', wp_slash( $css_input ) );
		update_post_meta( $post_id, '_kayzart_js', wp_slash( $js_input ) );
		update_post_meta( $post_id, '_kayzart_js_mode', $js_mode );
		delete_post_meta( $post_id, '_kayzart_js_enabled' );
		update_post_meta( $post_id, '_kayzart_shadow_dom', $shadow_dom_enabled ? '1' : '0' );
		update_post_meta( $post_id, '_kayzart_shortcode_enabled', $shortcode_enabled ? '1' : '0' );
		if ( null !== $single_page_enabled ) {
			update_post_meta( $post_id, '_kayzart_single_page_enabled', $single_page_enabled ? '1' : '0' );
		}
		if ( null !== $live_highlight_enabled ) {
			update_post_meta( $post_id, '_kayzart_live_highlight', $live_highlight_enabled ? '1' : '0' );
		}
		update_post_meta( $post_id, '_kayzart_tailwind', $tailwind_enabled ? '1' : '0' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		delete_post_meta( $post_id, '_kayzart_setup_required' );

		if ( $tailwind_enabled ) {
			update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $compiled_css ) );
		} else {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
		}

		if ( empty( $external_scripts ) ) {
			delete_post_meta( $post_id, '_kayzart_external_scripts' );
		} else {
			update_post_meta(
				$post_id,
				'_kayzart_external_scripts',
				wp_json_encode( $external_scripts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}

		if ( empty( $external_styles ) ) {
			delete_post_meta( $post_id, '_kayzart_external_styles' );
		} else {
			update_post_meta(
				$post_id,
				'_kayzart_external_styles',
				wp_json_encode( $external_styles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			);
		}

		$response = array(
			'ok'              => true,
			'html'            => $html,
			'tailwindEnabled' => $tailwind_enabled,
			'settingsData'    => Rest_Settings::build_settings_payload( $post_id ),
		);

		return new \WP_REST_Response( $response, 200 );
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
}
