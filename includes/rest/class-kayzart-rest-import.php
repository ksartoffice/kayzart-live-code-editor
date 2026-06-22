<?php
/**
 * REST handler for creating KayzArt posts from full HTML imports.
 *
 * @package KayzArt
 */

namespace KayzArt;

use TailwindPHP\tw;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST callbacks for import workflows.
 */
class Rest_Import {
	/**
	 * Create a new KayzArt-managed draft from imported editor parts.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public static function create_from_import( \WP_REST_Request $request ): \WP_REST_Response {
		$source_post_id = absint( $request->get_param( 'post_id' ) );
		$mode           = sanitize_key( (string) $request->get_param( 'mode' ) );
		$html           = (string) $request->get_param( 'html' );
		$html_parts     = Html_Document::split_editor_html( $html );
		$content_html   = (string) $html_parts['content'];
		$body_attrs     = (string) $html_parts['body_attrs'];
		$custom_head    = (string) $request->get_param( 'customHead' );
		$css_input      = self::sanitize_css_input( (string) $request->get_param( 'css' ) );
		$js_input       = (string) $request->get_param( 'js' );
		$js_mode        = self::sanitize_js_mode( $request->get_param( 'jsMode' ) );

		if ( 'tailwind' !== $mode && 'normal' !== $mode ) {
			return self::error_response( __( 'Invalid mode.', 'kayzart-live-code-editor' ), 400 );
		}

		if ( null === $js_mode ) {
			return self::error_response( __( 'Invalid jsMode value.', 'kayzart-live-code-editor' ), 400 );
		}

		$source_post = get_post( $source_post_id );
		if ( ! $source_post || ! Post_Type::is_editor_enabled_post( $source_post_id ) ) {
			return self::error_response( __( 'Invalid post type.', 'kayzart-live-code-editor' ), 400 );
		}

		$post_type = $source_post->post_type;
		if ( ! Post_Type::is_post_type_enabled( $post_type ) ) {
			return self::error_response( __( 'This post type is not enabled for Kayzart.', 'kayzart-live-code-editor' ), 400 );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->create_posts ) ) {
			return self::error_response( __( 'Permission denied.', 'kayzart-live-code-editor' ), 403 );
		}

		if ( ( '' !== trim( $custom_head ) || '' !== trim( $js_input ) || 'classic' !== $js_mode ) && ! current_user_can( 'unfiltered_html' ) ) {
			return self::error_response( __( 'Permission denied.', 'kayzart-live-code-editor' ), 403 );
		}

		$tailwind_enabled = 'tailwind' === $mode;
		if ( $tailwind_enabled ) {
			$size_validation = self::validate_tailwind_input_size( $content_html, $css_input );
			if ( is_wp_error( $size_validation ) ) {
				return self::error_response( $size_validation->get_error_message(), 400 );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => __( 'Untitled landing page', 'kayzart-live-code-editor' ),
				'post_content' => wp_slash( $content_html ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return self::error_response( $post_id->get_error_message(), 400 );
		}

		$post_id = (int) $post_id;
		Post_Type::enable_for_post( $post_id );

		if ( '' !== $body_attrs ) {
			update_post_meta( $post_id, Html_Document::BODY_ATTRS_META_KEY, wp_slash( $body_attrs ) );
		}

		Custom_Head::save( $post_id, $custom_head );
		update_post_meta( $post_id, '_kayzart_css', wp_slash( $css_input ) );
		update_post_meta( $post_id, '_kayzart_js', wp_slash( $js_input ) );
		update_post_meta( $post_id, '_kayzart_js_mode', $js_mode );
		update_post_meta( $post_id, '_kayzart_tailwind', $tailwind_enabled ? '1' : '0' );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		delete_post_meta( $post_id, '_kayzart_setup_required' );
		delete_post_meta( $post_id, '_kayzart_js_enabled' );

		if ( $tailwind_enabled ) {
			try {
				$compiled_css = tw::generate(
					array(
						'content' => $content_html,
						'css'     => $css_input,
					)
				);
			} catch ( \Throwable $e ) {
				wp_delete_post( $post_id, true );
				return self::error_response(
					sprintf(
						/* translators: %s: error message. */
						__( 'Tailwind compile failed: %s', 'kayzart-live-code-editor' ),
						$e->getMessage()
					),
					500
				);
			}
			update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $compiled_css ) );
		} else {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
		}

		return new \WP_REST_Response(
			array(
				'ok'      => true,
				'postId'  => $post_id,
				'editUrl' => Post_Type::get_editor_url( $post_id ),
			),
			200
		);
	}

	/**
	 * Build an error response.
	 *
	 * @param string $message Error message.
	 * @param int    $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => $message,
			),
			$status
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
	 * Sanitize JavaScript execution mode.
	 *
	 * @param mixed $value Raw mode.
	 * @return string|null
	 */
	private static function sanitize_js_mode( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return 'classic';
		}
		$mode = strtolower( trim( $value ) );
		if ( 'module' === $mode || 'classic' === $mode ) {
			return $mode;
		}
		return null;
	}

	/**
	 * Validate Tailwind compile input sizes.
	 *
	 * @param string $html HTML input.
	 * @param string $css CSS input.
	 * @return true|\WP_Error
	 */
	private static function validate_tailwind_input_size( string $html, string $css ) {
		if ( strlen( $html ) > Limits::MAX_TAILWIND_HTML_BYTES ) {
			return new \WP_Error(
				'kayzart_tailwind_html_too_large',
				__( 'Tailwind HTML input exceeds the maximum size.', 'kayzart-live-code-editor' )
			);
		}
		if ( strlen( $css ) > Limits::MAX_TAILWIND_CSS_BYTES ) {
			return new \WP_Error(
				'kayzart_tailwind_css_too_large',
				__( 'Tailwind CSS input exceeds the maximum size.', 'kayzart-live-code-editor' )
			);
		}
		return true;
	}
}
