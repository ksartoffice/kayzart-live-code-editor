<?php
/**
 * Creates independent drafts from bundled page templates.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persists a complete template page and owns cleanup of its new files. */
class Template_Page_Creator {

	const REQUEST_META  = '_kayzart_template_request_id';
	const COMPLETE_META = '_kayzart_template_creation_complete';
	const ID_META       = '_kayzart_template_id';
	const VERSION_META  = '_kayzart_template_version';

	/**
	 * Find a previous attempt belonging to this user.
	 *
	 * @param string $request_id Creation request UUID.
	 * @return int
	 */
	private static function find_request( string $request_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'draft', 'pending', 'private', 'publish', 'future' ),
				'author'         => get_current_user_id(),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A scoped lookup only on explicit creation/retry, not a page-view query.
				'meta_key'       => self::REQUEST_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Creation request UUID is scoped to the current author.
				'meta_value'     => $request_id,
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Return a safe retry target, or report an incomplete earlier attempt.
	 *
	 * @param int    $post_id Existing page ID.
	 * @param string $id      Template ID.
	 * @return int|\WP_Error
	 */
	private static function resume( int $post_id, string $id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) || get_post_meta( $post_id, self::ID_META, true ) !== $id || '1' !== get_post_meta( $post_id, self::COMPLETE_META, true ) ) {
			return new \WP_Error( 'template_incomplete', __( 'An earlier attempt did not finish. Check your Pages before starting again.', 'kayzart-live-code-editor' ) );
		}
		return $post_id;
	}

	/**
	 * Create a draft. The admin boundary must also verify its form nonce.
	 *
	 * @param string $id         Allowlisted template ID.
	 * @param string $request_id UUID retained across retries.
	 * @param string $title      Requested page title.
	 * @return int|\WP_Error
	 */
	public static function create( string $id, string $request_id, string $title ) {
		$reason = Template_Library::unavailable_reason();
		if ( '' !== $reason ) {
			return new \WP_Error( 'template_forbidden', $reason );
		}
		if ( ! wp_is_uuid( $request_id, 4 ) ) {
			return new \WP_Error( 'template_request_invalid', __( 'The creation request is invalid. Reload the page and try again.', 'kayzart-live-code-editor' ) );
		}
		$existing = self::find_request( $request_id );
		if ( $existing ) {
			return self::resume( $existing, $id );
		}
		$template = Template_Library::get( $id );
		if ( is_wp_error( $template ) ) {
			return $template;
		}
		$prepared = Template_Library::prepare( $template );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		foreach ( $template['assets'] as $asset ) {
			if ( ! in_array( $asset['mimeType'], get_allowed_mime_types(), true ) || filesize( $asset['file'] ) > wp_max_upload_size() ) {
				return new \WP_Error( 'template_media_unavailable', __( 'This site cannot import the template image. Check the allowed image types and upload limit.', 'kayzart-live-code-editor' ) );
			}
		}
		$lock = 'kayzart_template_lock_' . md5( get_current_user_id() . ':' . $request_id );
		if ( ! add_option( $lock, time(), '', false ) ) {
			$existing = self::find_request( $request_id );
			if ( $existing && '1' === get_post_meta( $existing, self::COMPLETE_META, true ) ) {
				return self::resume( $existing, $id );
			}
			return new \WP_Error( 'template_busy', __( 'This request is already being processed. Wait a moment and try again. If it was interrupted, check your Pages before reloading.', 'kayzart-live-code-editor' ) );
		}
		$post_id     = 0;
		$attachments = array();
		$complete    = false;
		$hooks       = array(
			array( 'wp_after_insert_post', 'wp_save_post_revision_on_insert', 3 ),
			array( 'post_updated', 'wp_save_post_revision', 1 ),
		);
		$removed     = array();
		try {
			$existing = self::find_request( $request_id );
			if ( $existing ) {
				return self::resume( $existing, $id );
			}
			foreach ( $hooks as $hook ) {
				$priority = has_action( $hook[0], $hook[1] );
				if ( false !== $priority ) {
					remove_action( $hook[0], $hook[1], $priority );
					$removed[] = array( $hook[0], $hook[1], $priority, $hook[2] );
				}
			}
			$title   = sanitize_text_field( $title );
			$created = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'draft',
					'post_author' => get_current_user_id(),
					'post_title'  => wp_slash( '' !== $title ? $title : __( 'New service page', 'kayzart-live-code-editor' ) ),
					'meta_input'  => array(
						self::REQUEST_META => $request_id,
						self::ID_META      => $id,
						self::VERSION_META => $template['version'],
					),
				),
				true
			);
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$post_id = (int) $created;
			$images  = array();
			foreach ( $template['assets'] as $asset ) {
				$attachment = self::import_image( $asset, $post_id );
				if ( is_wp_error( $attachment ) ) {
					return $attachment;
				}
				$attachments[]          = $attachment;
				$images[ $asset['id'] ] = wp_get_attachment_url( $attachment );
			}
			$prepared = Template_Library::prepare( $template, $images );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}
			$result = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $prepared['html'] ),
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			Post_Type::enable_for_post( $post_id );
			update_post_meta( $post_id, '_kayzart_css', wp_slash( $prepared['css'] ) );
			update_post_meta( $post_id, '_kayzart_tailwind_css', wp_slash( $prepared['css'] ) );
			delete_post_meta( $post_id, '_kayzart_normal_css' );
			update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $prepared['compiled'] ) );
			update_post_meta( $post_id, Tailwind_Compiler::CANDIDATES_META_KEY, wp_slash( Tailwind_Compiler::encode_candidates( $prepared['candidates'] ) ) );
			update_post_meta( $post_id, '_kayzart_tailwind', '1' );
			update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
			update_post_meta( $post_id, '_kayzart_template_mode', 'standalone' );
			delete_post_meta( $post_id, '_kayzart_setup_required' );
			$expected = Snapshot::normalize(
				array(
					'html'       => $prepared['html'],
					'css'        => $prepared['css'],
					'customHead' => '',
					'js'         => '',
					'jsMode'     => 'classic',
					'editorMode' => 'tailwind',
					'cssByMode'  => array(
						'normal'   => null,
						'tailwind' => $prepared['css'],
					),
				)
			);
			if ( Snapshot::hash( $expected ) !== Snapshot::hash( Snapshot::for_post( $post_id ) ) || get_post_meta( $post_id, '_kayzart_generated_css', true ) !== $prepared['compiled'] || 'standalone' !== get_post_meta( $post_id, '_kayzart_template_mode', true ) ) {
				return new \WP_Error( 'template_save_failed', __( 'The template page could not be saved completely. Please try again.', 'kayzart-live-code-editor' ) );
			}
			if ( Snapshot::revisions_enabled( $post_id ) ) {
				$revision = Snapshot::create_revision( $post_id );
				if ( ! $revision || Snapshot::hash( $expected ) !== Snapshot::hash( Snapshot::for_revision( $revision ) ?? array() ) ) {
					return new \WP_Error( 'template_revision_failed', __( 'The initial page revision could not be saved. Please try again.', 'kayzart-live-code-editor' ) );
				}
			}
			update_post_meta( $post_id, self::COMPLETE_META, '1' );
			$complete = '1' === get_post_meta( $post_id, self::COMPLETE_META, true );
			return $complete ? $post_id : new \WP_Error( 'template_save_failed', __( 'The template page could not be saved completely. Please try again.', 'kayzart-live-code-editor' ) );
		} catch ( \Throwable $error ) {
			// An insert hook can throw after the draft and its request meta exist.
			if ( ! $post_id ) {
				$post_id = self::find_request( $request_id );
			}
			return new \WP_Error( 'template_create_failed', __( 'The template could not be imported. Check your media upload settings and try again.', 'kayzart-live-code-editor' ) );
		} finally {
			foreach ( $removed as $hook ) {
				add_action( $hook[0], $hook[1], $hook[2], $hook[3] );
			}
			if ( ! $complete && $post_id ) {
				// Collect a sideload that inserted its attachment before a later hook failed.
				$owned       = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => 'inherit',
						'post_parent'    => $post_id,
						'posts_per_page' => -1,
						'fields'         => 'ids',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Failure cleanup is restricted to this newly created page.
						'meta_key'       => '_kayzart_template_import_owner',
						// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only collect this request's attachments.
						'meta_value'     => (string) $post_id,
					)
				);
				$attachments = array_unique( array_merge( $attachments, $owned ) );
				$cleanup_ok  = true;
				foreach ( $attachments as $attachment ) {
					if ( ! wp_delete_attachment( $attachment, true ) ) {
						$cleanup_ok = false;
					}
				}
				if ( ! wp_delete_post( $post_id, true ) ) {
					$cleanup_ok = false;
				}
				if ( ! $cleanup_ok ) {
					delete_option( $lock );
					return new \WP_Error( 'template_cleanup_failed', __( 'The import did not finish and some draft files could not be removed. Check Pages and Media before trying again.', 'kayzart-live-code-editor' ) );
				}
			}
			delete_option( $lock );
		}
	}

	/**
	 * Copy a bundled PNG into this page's media without an HTTP request.
	 *
	 * @param array $asset   Validated bundled image.
	 * @param int   $post_id New page ID.
	 * @return int|\WP_Error
	 */
	private static function import_image( array $asset, int $post_id ) {
		$temp = wp_tempnam( basename( $asset['file'] ) );
		if ( ! $temp ) {
			return new \WP_Error( 'template_image_failed', __( 'A temporary image file could not be created.', 'kayzart-live-code-editor' ) );
		}
		try {
			if ( ! copy( $asset['file'], $temp ) ) {
				return new \WP_Error( 'template_image_failed', __( 'The template image could not be copied.', 'kayzart-live-code-editor' ) );
			}
			$result = media_handle_sideload(
				array(
					'name'     => basename( $asset['file'] ),
					'tmp_name' => $temp,
				),
				$post_id,
				__( 'Template image placeholder', 'kayzart-live-code-editor' ),
				array( 'meta_input' => array( '_kayzart_template_import_owner' => (string) $post_id ) )
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$file = get_attached_file( $result );
			if ( ! $file || ! is_file( $file ) || ! wp_get_attachment_url( $result ) || ! wp_getimagesize( $file ) ) {
				wp_delete_attachment( $result, true );
				return new \WP_Error( 'template_image_failed', __( 'The imported template image could not be verified.', 'kayzart-live-code-editor' ) );
			}
			update_post_meta( $result, '_kayzart_template_asset', $asset['id'] . '@' . hash_file( 'sha256', $asset['file'] ) );
			return (int) $result;
		} finally {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}
}
