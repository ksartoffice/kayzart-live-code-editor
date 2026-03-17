<?php
/**
 * REST handlers for saving KayzArt content.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for save and compile.
 */
class Rest_Save {

	private const JS_MODE_VALUES = array( 'auto', 'classic', 'module' );
	/**
	 * Shadow DOM Tailwind custom property fallbacks.
	 *
	 * @var string
	 */
	private const TAILWIND_SHADOW_FALLBACK_CSS = '@layer base {
  :host,
  :host *,
  :host *::before,
  :host *::after,
  :host ::backdrop{
    --tw-border-style: solid;
    --tw-gradient-position: initial;
    --tw-gradient-from: #0000;
    --tw-gradient-via: #0000;
    --tw-gradient-to: #0000;
    --tw-gradient-stops: initial;
    --tw-gradient-via-stops: initial;
    --tw-gradient-from-position: 0%;
    --tw-gradient-via-position: 50%;
    --tw-gradient-to-position: 100%;
    --tw-font-weight: initial;
    --tw-shadow: 0 0 #0000;
    --tw-shadow-color: initial;
    --tw-shadow-alpha: 100%;
    --tw-inset-shadow: 0 0 #0000;
    --tw-inset-shadow-color: initial;
    --tw-inset-shadow-alpha: 100%;
    --tw-ring-color: initial;
    --tw-ring-shadow: 0 0 #0000;
    --tw-inset-ring-color: initial;
    --tw-inset-ring-shadow: 0 0 #0000;
    --tw-ring-inset: initial;
    --tw-ring-offset-width: 0px;
    --tw-ring-offset-color: #fff;
    --tw-ring-offset-shadow: 0 0 #0000;
    --radius: 0.25rem;
  }
}';

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
		$tailwind_enabled = rest_sanitize_boolean( $request->get_param( 'tailwindEnabled' ) );
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

		$tailwind_meta   = get_post_meta( $post_id, '_kayzart_tailwind', true );
		$tailwind_locked = '1' === get_post_meta( $post_id, '_kayzart_tailwind_locked', true );
		$has_tailwind    = '' !== $tailwind_meta;
		if ( $tailwind_locked || $has_tailwind ) {
			$tailwind_enabled = '1' === $tailwind_meta;
		}

		if ( $tailwind_enabled ) {
			$size_validation = self::validate_tailwind_input_size( $html, $css_input );
			if ( is_wp_error( $size_validation ) ) {
				return new \WP_REST_Response(
					array(
						'ok'    => false,
						'error' => $size_validation->get_error_message(),
					),
					400
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

		$compiled_css = '';
		if ( $tailwind_enabled ) {
			try {
				$compiled_css = tw::generate(
					array(
						'content' => $html,
						'css'     => $css_input,
					)
				);
				$compiled_css = self::append_tailwind_shadow_fallbacks( $compiled_css );
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

		update_post_meta( $post_id, '_kayzart_css', wp_slash( $css_input ) );
		if ( $has_js ) {
			update_post_meta( $post_id, '_kayzart_js', wp_slash( $js_input ) );
		}
		if ( $has_js || $has_js_mode ) {
			update_post_meta( $post_id, '_kayzart_js_mode', $js_mode );
		}
		delete_post_meta( $post_id, '_kayzart_js_enabled' );
		if ( $tailwind_enabled ) {
			update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $compiled_css ) );
		} else {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
		}
		update_post_meta( $post_id, '_kayzart_tailwind', $tailwind_enabled ? '1' : '0' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );

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
		return in_array( $mode, self::JS_MODE_VALUES, true ) ? $mode : 'auto';
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
		return in_array( $mode, self::JS_MODE_VALUES, true ) ? $mode : null;
	}

	/**
	 * Append Shadow DOM Tailwind fallback custom properties once.
	 *
	 * @param string $css Tailwind generated CSS.
	 * @return string
	 */
	public static function append_tailwind_shadow_fallbacks( string $css ): string {
		if ( '' === $css ) {
			return '';
		}

		$already_injected = false !== strpos( $css, ':host,' )
			&& false !== strpos( $css, ':host ::backdrop{' )
			&& false !== strpos( $css, '--tw-border-style: solid;' )
			&& false !== strpos( $css, '--tw-gradient-position: initial;' )
			&& false !== strpos( $css, '--tw-gradient-from: #0000;' )
			&& false !== strpos( $css, '--tw-gradient-via: #0000;' )
			&& false !== strpos( $css, '--tw-gradient-to: #0000;' )
			&& false !== strpos( $css, '--tw-gradient-stops: initial;' )
			&& false !== strpos( $css, '--tw-gradient-via-stops: initial;' )
			&& false !== strpos( $css, '--tw-gradient-from-position: 0%;' )
			&& false !== strpos( $css, '--tw-gradient-via-position: 50%;' )
			&& false !== strpos( $css, '--tw-gradient-to-position: 100%;' )
			&& false !== strpos( $css, '--tw-font-weight: initial;' )
			&& false !== strpos( $css, '--tw-shadow: 0 0 #0000;' )
			&& false !== strpos( $css, '--tw-shadow-color: initial;' )
			&& false !== strpos( $css, '--tw-shadow-alpha: 100%;' )
			&& false !== strpos( $css, '--tw-inset-shadow: 0 0 #0000;' )
			&& false !== strpos( $css, '--tw-inset-shadow-color: initial;' )
			&& false !== strpos( $css, '--tw-inset-shadow-alpha: 100%;' )
			&& false !== strpos( $css, '--tw-ring-color: initial;' )
			&& false !== strpos( $css, '--tw-ring-shadow: 0 0 #0000;' )
			&& false !== strpos( $css, '--tw-inset-ring-color: initial;' )
			&& false !== strpos( $css, '--tw-inset-ring-shadow: 0 0 #0000;' )
			&& false !== strpos( $css, '--tw-ring-inset: initial;' )
			&& false !== strpos( $css, '--tw-ring-offset-width: 0px;' )
			&& false !== strpos( $css, '--tw-ring-offset-color: #fff;' )
			&& false !== strpos( $css, '--tw-ring-offset-shadow: 0 0 #0000;' )
			&& false !== strpos( $css, '--radius: 0.25rem;' );

		if ( $already_injected ) {
			return $css;
		}

		return rtrim( $css ) . "\n\n" . self::TAILWIND_SHADOW_FALLBACK_CSS . "\n";
	}

	/**
	 * Validate Tailwind compile input size.
	 *
	 * @param string $html HTML input.
	 * @param string $css  CSS input.
	 * @return true|\WP_Error
	 */
	private static function validate_tailwind_input_size( string $html, string $css ) {
		if ( strlen( $html ) > Limits::MAX_TAILWIND_HTML_BYTES ) {
			return new \WP_Error(
				'kayzart_tailwind_html_too_large',
				__( 'Tailwind HTML input exceeds the maximum size.', 'kayzart-live-code-editor' ),
				array( 'status' => 400 )
			);
		}

		if ( strlen( $css ) > Limits::MAX_TAILWIND_CSS_BYTES ) {
			return new \WP_Error(
				'kayzart_tailwind_css_too_large',
				__( 'Tailwind CSS input exceeds the maximum size.', 'kayzart-live-code-editor' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Compile Tailwind CSS for preview.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function compile_tailwind( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id   = absint( $request->get_param( 'post_id' ) );
		$html      = (string) $request->get_param( 'html' );
		$css_input = (string) $request->get_param( 'css' );

		if ( ! Post_Type::is_kayzart_post( $post_id ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => __( 'Invalid post type.', 'kayzart-live-code-editor' ),
				),
				400
			);
		}

		$size_validation = self::validate_tailwind_input_size( $html, $css_input );
		if ( is_wp_error( $size_validation ) ) {
			return new \WP_REST_Response(
				array(
					'ok'    => false,
					'error' => $size_validation->get_error_message(),
				),
				400
			);
		}

		try {
			$css = tw::generate(
				array(
					'content' => $html,
					'css'     => $css_input,
				)
			);
			$css = self::append_tailwind_shadow_fallbacks( $css );
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

		return new \WP_REST_Response(
			array(
				'ok'  => true,
				'css' => $css,
			),
			200
		);
	}
}
