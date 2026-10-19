<?php
/**
 * Page operations for external AI agents (MCP via the Abilities API).
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, creates and updates Kayzart pages on behalf of an external agent.
 *
 * Every write goes through Rest_Save::save(), the same code path as the
 * editor, so Tailwind compilation, custom head sanitizing and snapshot
 * revisions behave identically. The REST route itself cannot be used: its
 * permission check requires a cookie nonce, which an agent authenticating
 * with an application password does not have.
 *
 * Writes are held to the built-in AI editor's policy: JavaScript stays
 * read-only, and Ai_Tools::validate_full_write() rejects scripts, embeds,
 * remote resources and broken CSS before anything is stored.
 */
class Agent_Pages {

	/** Maximum bytes accepted for one html, css or head source. */
	const MAX_SOURCE_BYTES = 1048576;

	/** Default and maximum page size for list_pages(). */
	const DEFAULT_PER_PAGE = 20;
	const MAX_PER_PAGE     = 100;

	/** Maximum exact replacements in one update. */
	const MAX_EDITS = 50;

	/** Default and maximum page size for list_media(). */
	const DEFAULT_MEDIA_PER_PAGE = 20;
	const MAX_MEDIA_PER_PAGE     = 50;

	/** Media kinds list_media() can filter by. */
	const MEDIA_TYPES = array( 'image', 'video', 'audio' );

	/** CSS modes a page can be created in. */
	const CSS_MODES = array( 'normal', 'tailwind' );

	/**
	 * Whether the current user may use agent page operations at all.
	 *
	 * AI editing is opt-in per role and per user through Ai_Setup::CAPABILITY,
	 * and an agent writing pages is AI editing, so the same switch governs it,
	 * as does the filter that turns AI editing off site-wide. The hashing and
	 * edit tools need mbstring and the safety policy and page checks need DOM,
	 * so without them nothing is offered rather than failing halfway through
	 * a write. Agents need no AI provider or job scheduler.
	 *
	 * @return bool
	 */
	public static function can_use(): bool {
		return is_user_logged_in()
			&& current_user_can( Ai_Setup::CAPABILITY )
			&& Ai_Availability::is_feature_enabled()
			&& Ai_Availability::is_mbstring_present()
			&& Ai_Availability::is_dom_present();
	}

	/**
	 * Whether the current user may create a page of the given post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function can_create( string $post_type ): bool {
		if ( ! self::can_use() || ! Post_Type::is_post_type_enabled( $post_type ) ) {
			return false;
		}
		$post_type_object = get_post_type_object( $post_type );
		return $post_type_object instanceof \WP_Post_Type && current_user_can( $post_type_object->cap->create_posts );
	}

	/**
	 * Whether the current user may read and edit a Kayzart page.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function can_edit( int $post_id ): bool {
		return self::can_use()
			&& self::is_agent_page( $post_id )
			&& current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether the current user may browse the media library.
	 *
	 * @return bool
	 */
	public static function can_list_media(): bool {
		return self::can_use() && current_user_can( 'upload_files' );
	}

	/**
	 * Whether a post is a Kayzart-managed page agents may work on.
	 *
	 * Ordinary posts of an enabled type are excluded until a person converts
	 * them in the editor, so an agent cannot take over regular content.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_agent_page( int $post_id ): bool {
		$post = get_post( $post_id );
		return $post instanceof \WP_Post
			&& 'trash' !== $post->post_status
			&& Post_Type::is_editor_enabled_post( $post )
			&& Post_Type::is_kayzart_post( $post );
	}

	/**
	 * Resolve the post type a new page is created as.
	 *
	 * @param string $requested Requested post type, or an empty string.
	 * @return string Empty string when no enabled type is available.
	 */
	public static function resolve_post_type( string $requested ): string {
		$enabled = Post_Type::get_enabled_post_types();
		if ( '' !== $requested ) {
			return in_array( $requested, $enabled, true ) ? $requested : '';
		}
		if ( in_array( Post_Type::PAGE_TYPE, $enabled, true ) ) {
			return Post_Type::PAGE_TYPE;
		}
		return isset( $enabled[0] ) ? (string) $enabled[0] : '';
	}

	/**
	 * Authoring guidelines, fonts and limits for the agent.
	 *
	 * @param string $css_mode normal or tailwind.
	 * @return array
	 */
	public static function guidelines( string $css_mode ): array {
		$css_mode = 'tailwind' === $css_mode ? 'tailwind' : 'normal';
		$fonts    = Ai_Fonts::catalog_for_tool( Ai_Fonts::resolve_for_payload() );
		unset( $fonts['ok'] );

		return array(
			'cssMode'         => $css_mode,
			'rules'           => Ai_Prompt::agent_guidelines( $css_mode ),
			'fonts'           => $fonts,
			'editableTargets' => self::editable_targets(),
			'postTypes'       => Post_Type::get_enabled_post_types(),
			'limits'          => array(
				'maxSourceBytes' => self::MAX_SOURCE_BYTES,
				'maxEdits'       => self::MAX_EDITS,
			),
		);
	}

	/**
	 * List Kayzart pages the current user can edit.
	 *
	 * @param array $input Ability input (search, status, page, perPage).
	 * @return array
	 */
	public static function list_pages( array $input ): array {
		$per_page = isset( $input['perPage'] ) ? (int) $input['perPage'] : self::DEFAULT_PER_PAGE;
		$per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$statuses = array( 'draft', 'pending', 'private', 'publish', 'future' );
		if ( isset( $input['status'] ) && in_array( $input['status'], $statuses, true ) ) {
			$statuses = array( (string) $input['status'] );
		}

		// Permissions are per post (own drafts, others' pages, private pages),
		// so they are applied to every match before paginating. Kayzart pages
		// are few enough that the ID list stays small.
		$args = array(
			'post_type'              => Post_Type::get_enabled_post_types(),
			'post_status'            => $statuses,
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Kayzart pages are identified by this flag.
			'meta_query'             => array(
				array(
					'key'   => Post_Type::ENABLED_META,
					'value' => '1',
				),
			),
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$ids = array_map( 'intval', ( new \WP_Query( $args ) )->posts );
		_prime_post_caches( $ids, false, true );
		$editable = array_values(
			array_filter(
				$ids,
				static function ( int $id ): bool {
					return current_user_can( 'edit_post', $id );
				}
			)
		);
		$pages    = array();
		foreach ( array_slice( $editable, ( $page - 1 ) * $per_page, $per_page ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$pages[] = self::summary( $post );
			}
		}

		return array(
			'pages'      => $pages,
			'page'       => $page,
			'totalPages' => (int) ceil( count( $editable ) / $per_page ),
			'total'      => count( $editable ),
		);
	}

	/**
	 * Search the media library so the agent can use real file URLs.
	 *
	 * @param array $input Ability input (search, type, page, perPage).
	 * @return array
	 */
	public static function list_media( array $input ): array {
		$per_page = isset( $input['perPage'] ) ? (int) $input['perPage'] : self::DEFAULT_MEDIA_PER_PAGE;
		$per_page = max( 1, min( self::MAX_MEDIA_PER_PAGE, $per_page ) );
		$page     = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
		$type     = isset( $input['type'] ) && in_array( $input['type'], self::MEDIA_TYPES, true ) ? (string) $input['type'] : 'image';
		$args     = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => $type,
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		$query = new \WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $attachment ) {
			if ( ! $attachment instanceof \WP_Post ) {
				continue;
			}
			$id       = (int) $attachment->ID;
			$metadata = wp_get_attachment_metadata( $id );
			$item     = array(
				'id'       => $id,
				'url'      => (string) wp_get_attachment_url( $id ),
				'title'    => html_entity_decode( get_the_title( $attachment ), ENT_QUOTES, 'UTF-8' ),
				'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'mimeType' => (string) $attachment->post_mime_type,
				'width'    => is_array( $metadata ) && isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
				'height'   => is_array( $metadata ) && isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
				'sizes'    => array(),
			);
			if ( 'image' === $type && is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( array_keys( $metadata['sizes'] ) as $size ) {
					$source = wp_get_attachment_image_src( $id, $size );
					if ( is_array( $source ) ) {
						$item['sizes'][] = array(
							'name'   => (string) $size,
							'url'    => (string) $source[0],
							'width'  => (int) $source[1],
							'height' => (int) $source[2],
						);
					}
				}
			}
			$items[] = $item;
		}

		return array(
			'media'      => $items,
			'page'       => $page,
			'totalPages' => (int) $query->max_num_pages,
			'total'      => (int) $query->found_posts,
		);
	}

	/**
	 * Issue a short-lived link that shows the page without login.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error
	 */
	public static function preview_link( int $post_id ) {
		if ( ! self::can_edit( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}
		return Agent_Preview::issue( $post_id );
	}

	/**
	 * Read one page with its sources.
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error
	 */
	public static function get_page( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_agent_page( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}
		$snapshot = Snapshot::for_post( $post_id );

		return array_merge(
			self::summary( $post ),
			array(
				'html'            => $snapshot['html'],
				'css'             => $snapshot['css'],
				'head'            => $snapshot['customHead'],
				'js'              => $snapshot['js'],
				'baseHash'        => self::version_hash( $post, $snapshot ),
				'editableTargets' => self::editable_targets(),
			)
		);
	}

	/**
	 * Create a draft page.
	 *
	 * @param array $input Ability input (title, html, css, cssMode, head, postType).
	 * @return array|\WP_Error
	 */
	public static function create_page( array $input ) {
		$post_type = self::resolve_post_type( isset( $input['postType'] ) ? sanitize_key( (string) $input['postType'] ) : '' );
		if ( '' === $post_type || ! self::can_create( $post_type ) ) {
			return self::error( 'kayzart_permission_denied', __( 'You cannot create Kayzart pages of this type.', 'kayzart-live-code-editor' ), 403 );
		}

		$css_mode = isset( $input['cssMode'] ) && 'tailwind' === $input['cssMode'] ? 'tailwind' : 'normal';
		$before   = array(
			'html'       => '',
			'customHead' => '',
			'css'        => 'tailwind' === $css_mode ? Css_Mode::create_initial_tailwind_source( '' ) : '',
			'js'         => '',
			'jsMode'     => 'classic',
		);
		$sources  = array( 'html' => (string) ( $input['html'] ?? '' ) );
		if ( isset( $input['css'] ) && '' !== (string) $input['css'] ) {
			$sources['css'] = (string) $input['css'];
		}
		if ( isset( $input['head'] ) ) {
			$sources['head'] = (string) $input['head'];
		}

		$after = self::apply_sources( $before, $sources );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$invalid = self::validate_write( $before, $after );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		$title   = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_status' => 'draft',
				'post_title'  => '' !== $title ? $title : __( 'Untitled landing page', 'kayzart-live-code-editor' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return self::error( 'kayzart_create_failed', $post_id->get_error_message(), 500 );
		}
		$post_id = (int) $post_id;

		// Mirrors Admin::create_new_landing_page_post() with a chosen setup mode.
		Post_Type::enable_for_post( $post_id );
		Css_Mode::initialize_post_mode( $post_id, $css_mode );
		update_post_meta( $post_id, '_kayzart_tailwind_locked', '1' );
		delete_post_meta( $post_id, '_kayzart_setup_required' );

		$saved = self::save( $post_id, $after, isset( $sources['head'] ) );
		if ( is_wp_error( $saved ) ) {
			wp_delete_post( $post_id, true );
			return $saved;
		}
		self::record_activity( $post_id, $saved, $before, (string) ( $input['summary'] ?? '' ), 'create' );

		return self::write_result( $post_id, $saved, $after );
	}

	/**
	 * Update an existing page with full sources and/or exact replacements.
	 *
	 * @param array $input Ability input (id, baseHash, title, html, css, head, edits).
	 * @return array|\WP_Error
	 */
	public static function update_page( array $input ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( ! self::can_edit( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}

		// Hold the page's save lock from the baseHash check through the write,
		// so no other save can land in between and be overwritten.
		if ( ! Save_Lock::acquire( $post_id ) ) {
			return self::error( 'kayzart_page_busy', __( 'Another save of this page is in progress. Try again in a moment.', 'kayzart-live-code-editor' ), 409 );
		}
		try {
			return self::update_page_locked( $post_id, $input );
		} finally {
			Save_Lock::release( $post_id );
		}
	}

	/**
	 * Update a page while holding its save lock.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $input   Ability input.
	 * @return array|\WP_Error
	 */
	private static function update_page_locked( int $post_id, array $input ) {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_agent_page( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}
		$before = Snapshot::for_post( $post_id );
		if ( ! hash_equals( self::version_hash( $post, $before ), (string) ( $input['baseHash'] ?? '' ) ) ) {
			return self::error(
				'kayzart_page_changed',
				__( 'The page changed after you read it. Call kayzart/get-page again and reapply your change to the current sources.', 'kayzart-live-code-editor' ),
				409
			);
		}

		$sources = array();
		foreach ( array( 'html', 'css', 'head' ) as $target ) {
			if ( isset( $input[ $target ] ) ) {
				$sources[ $target ] = (string) $input[ $target ];
			}
		}
		$edits = isset( $input['edits'] ) && is_array( $input['edits'] ) ? $input['edits'] : array();
		$title = isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : null;
		if ( empty( $sources ) && empty( $edits ) && null === $title ) {
			return self::error( 'kayzart_nothing_to_update', __( 'Provide html, css, head, edits or title to update.', 'kayzart-live-code-editor' ), 400 );
		}
		if ( count( $edits ) > self::MAX_EDITS ) {
			return self::error( 'kayzart_too_many_edits', __( 'Too many edits in one update.', 'kayzart-live-code-editor' ), 400 );
		}

		$after = self::apply_sources( $before, $sources );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$after = self::apply_edits( $after, $edits );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$invalid = self::validate_write( $before, $after );
		if ( is_wp_error( $invalid ) ) {
			return $invalid;
		}

		$head_changed = $before['customHead'] !== $after['customHead'];
		$saved        = self::save( $post_id, $after, $head_changed, $title );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::record_activity( $post_id, $saved, $before, (string) ( $input['summary'] ?? '' ), 'update', (string) $post->post_title );

		return self::write_result( $post_id, $saved, $after );
	}

	/**
	 * Version token an update must present.
	 *
	 * Covers the editor sources and modes plus the title, the fields an update
	 * can change, so a person's newer title is not silently overwritten either.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $snapshot Snapshot::for_post() of the post.
	 * @return string
	 */
	private static function version_hash( \WP_Post $post, array $snapshot ): string {
		return hash( 'sha256', Snapshot::hash( $snapshot ) . '|' . (string) $post->post_title );
	}

	/**
	 * Targets the current user may write.
	 *
	 * Custom head requires unfiltered_html, exactly as in the editor.
	 *
	 * @return array<int,string>
	 */
	public static function editable_targets(): array {
		$targets = array( 'html', 'css' );
		if ( current_user_can( 'unfiltered_html' ) ) {
			$targets[] = 'head';
		}
		return $targets;
	}

	/**
	 * Replace whole sources in a snapshot.
	 *
	 * @param array                $snapshot Snapshot.
	 * @param array<string,string> $sources  Sources keyed by target (html, css, head).
	 * @return array|\WP_Error
	 */
	private static function apply_sources( array $snapshot, array $sources ) {
		$editable = self::editable_targets();
		foreach ( $sources as $target => $source ) {
			if ( ! in_array( $target, $editable, true ) ) {
				return self::target_error( (string) $target );
			}
			if ( strlen( $source ) > self::MAX_SOURCE_BYTES ) {
				return self::error( 'kayzart_source_too_large', __( 'A source exceeds the maximum size.', 'kayzart-live-code-editor' ), 413 );
			}
		}
		return self::snapshot_fields( Ai_Tools::with_sources( $snapshot, $sources ), $snapshot );
	}

	/**
	 * Apply exact replacements in order, as the built-in AI editor does.
	 *
	 * @param array $snapshot Snapshot.
	 * @param array $edits    List of {target, from, to, replaceAll}.
	 * @return array|\WP_Error
	 */
	private static function apply_edits( array $snapshot, array $edits ) {
		$editable = self::editable_targets();
		foreach ( array_values( $edits ) as $index => $edit ) {
			if ( ! is_array( $edit ) ) {
				return self::error( 'kayzart_invalid_edit', __( 'Each edit must be an object.', 'kayzart-live-code-editor' ), 400 );
			}
			$target = isset( $edit['target'] ) ? (string) $edit['target'] : '';
			if ( ! in_array( $target, $editable, true ) ) {
				return self::target_error( $target );
			}
			try {
				$result = Ai_Tools::run_tool(
					'replace_string',
					array(
						'target'     => $target,
						'from'       => (string) ( $edit['from'] ?? '' ),
						'to'         => (string) ( $edit['to'] ?? '' ),
						'replaceAll' => ! empty( $edit['replaceAll'] ),
					),
					$snapshot,
					array(),
					$editable
				);
			} catch ( Ai_Tool_Error $e ) {
				return self::error(
					'kayzart_edit_failed',
					/* translators: 1: edit index starting at 0, 2: error message. */
					sprintf( __( 'Edit %1$d failed: %2$s', 'kayzart-live-code-editor' ), $index, $e->getMessage() ),
					400,
					array(
						'editIndex' => $index,
						'details'   => $e->get_details(),
					)
				);
			}
			$snapshot = self::snapshot_fields( $result['snapshot'], $snapshot );
			if ( strlen( Ai_Tools::get_snapshot_source( $snapshot, $target ) ) > self::MAX_SOURCE_BYTES ) {
				return self::error( 'kayzart_source_too_large', __( 'A source exceeds the maximum size.', 'kayzart-live-code-editor' ), 413 );
			}
		}
		return $snapshot;
	}

	/**
	 * Keep the snapshot fields Snapshot::normalize() understands.
	 *
	 * Ai_Tools returns its own working shape (with baseHash and without the
	 * mode fields), so the mode fields are carried over from the original.
	 *
	 * @param array $next     Snapshot returned by Ai_Tools.
	 * @param array $original Snapshot before the change.
	 * @return array
	 */
	private static function snapshot_fields( array $next, array $original ): array {
		$merged = $original;
		foreach ( array( 'html', 'customHead', 'css' ) as $key ) {
			$merged[ $key ] = (string) ( $next[ $key ] ?? '' );
		}
		return $merged;
	}

	/**
	 * Run the built-in AI editor's write policy on a transition.
	 *
	 * @param array $before Snapshot before.
	 * @param array $after  Snapshot after.
	 * @return true|\WP_Error
	 */
	private static function validate_write( array $before, array $after ) {
		$violations = Ai_Tools::validate_full_write( $before, $after, self::editable_targets() );
		if ( empty( $violations ) ) {
			return true;
		}
		// The policy fingerprints each finding to compare counts; the hash means nothing to the agent.
		$violations = array_map(
			static function ( $violation ) {
				return trim( (string) preg_replace( '/\s+[0-9a-f]{64}\b/', '', (string) $violation ) );
			},
			$violations
		);
		return self::error(
			'kayzart_unsafe_or_invalid_page',
			/* translators: %s: list of violations. */
			sprintf( __( 'The page was not saved: %s', 'kayzart-live-code-editor' ), implode( ' ', array_slice( $violations, 0, 10 ) ) ),
			400,
			array( 'violations' => array_slice( $violations, 0, 10 ) )
		);
	}

	/**
	 * Persist a snapshot through the editor's save handler.
	 *
	 * @param int         $post_id      Post ID.
	 * @param array       $snapshot     Snapshot to persist.
	 * @param bool        $include_head Whether to write the custom head.
	 * @param string|null $title        New title, or null to keep it.
	 * @return array|\WP_Error Save response data.
	 */
	private static function save( int $post_id, array $snapshot, bool $include_head, ?string $title = null ) {
		$tailwind = '1' === get_post_meta( $post_id, '_kayzart_tailwind', true );
		$request  = new \WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'html', $snapshot['html'] );
		$request->set_param( 'css', $snapshot['css'] );
		$request->set_param( 'tailwindEnabled', $tailwind );
		if ( $include_head ) {
			$request->set_param( 'customHead', $snapshot['customHead'] );
		}
		if ( $tailwind ) {
			// The editor extracts candidates in the browser; an agent has no
			// browser, so the server-side twin of that extractor runs here.
			$candidates = Tailwind_Compiler::extract_candidates( $snapshot['html'] );
			if ( is_wp_error( $candidates ) ) {
				return self::error( $candidates->get_error_code(), $candidates->get_error_message(), 400 );
			}
			$request->set_param( 'tailwindCandidates', $candidates );
		}
		if ( null !== $title ) {
			$request->set_param( 'settingsUpdates', array( 'title' => $title ) );
		}

		$response = Rest_Save::save( $request );
		$data     = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : __( 'Save failed.', 'kayzart-live-code-editor' );
			return self::error( 'kayzart_save_failed', $message, $response->get_status() >= 400 ? $response->get_status() : 500 );
		}
		return $data;
	}

	/**
	 * Show the write in the editor's AI history as an agent edit.
	 *
	 * @param int         $post_id Post ID.
	 * @param array       $saved   Save response data.
	 * @param array       $before  Snapshot before the write.
	 * @param string      $summary      Agent-written description of the change.
	 * @param string      $action       create or update.
	 * @param string|null $title_before Title before an update; null for a new page.
	 */
	private static function record_activity( int $post_id, array $saved, array $before, string $summary, string $action, ?string $title_before = null ): void {
		$after        = Snapshot::for_post( $post_id );
		$title_after  = (string) get_post_field( 'post_title', $post_id, 'raw' );
		$title_change = null !== $title_before && $title_before !== $title_after
			? array(
				'before' => $title_before,
				'after'  => $title_after,
			)
			: null;
		if ( null === $title_change && Snapshot::hash( Snapshot::normalize( $before ) ) === Snapshot::hash( $after ) ) {
			return;
		}
		( new Ai_Timeline_Store() )->record_agent_edit(
			$post_id,
			get_current_user_id(),
			isset( $saved['revision']['id'] ) ? (int) $saved['revision']['id'] : null,
			$before,
			$after,
			sanitize_textarea_field( $summary ),
			self::client_label(),
			$action,
			$title_change
		);
	}

	/** Prefix marking a stored agent name as a local STDIO connection. */
	const LOCAL_CLIENT_PREFIX = 'local:';

	/**
	 * Identify the agent for the history record.
	 *
	 * Over HTTP it is the application password's name, which the person chose
	 * when connecting the agent (for example "Claude Code").
	 *
	 * Over a local STDIO connection (WP-CLI) there is no such name, and the MCP
	 * Adapter does not expose the client's self-reported name to abilities. The
	 * person names the agent instead with the KAYZART_AGENT_NAME environment
	 * variable in the MCP server configuration. The name is stored with a
	 * prefix and turned into "Name (local)" by display_label() when shown, so
	 * the wording follows the viewer's language rather than the language the
	 * WP-CLI process ran in.
	 *
	 * @return string Stored identifier; pass it to display_label() to show it.
	 */
	public static function client_label(): string {
		$label = '';
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$label = self::LOCAL_CLIENT_PREFIX . self::sanitize_agent_name( (string) getenv( 'KAYZART_AGENT_NAME' ) );
		} elseif ( function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = rest_get_authenticated_app_password();
			if ( $uuid ) {
				$item  = \WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
				$label = is_array( $item ) && ! empty( $item['name'] ) ? (string) $item['name'] : '';
			}
		}
		/**
		 * Filters how an MCP write's agent is recorded in the editor's AI history.
		 *
		 * A value starting with "local:" is shown as "Name (local)".
		 *
		 * @param string $label Agent name. Empty when it cannot be determined.
		 */
		$label = (string) apply_filters( 'kayzart_agent_client_label', $label );
		return '' !== $label ? $label : 'MCP';
	}

	/**
	 * Turn a stored agent identifier into the label shown to the viewer.
	 *
	 * @param string $stored Value recorded by client_label().
	 * @return string
	 */
	public static function display_label( string $stored ): string {
		if ( 0 !== strpos( $stored, self::LOCAL_CLIENT_PREFIX ) ) {
			return $stored;
		}
		$name = self::sanitize_agent_name( substr( $stored, strlen( self::LOCAL_CLIENT_PREFIX ) ) );
		if ( '' === $name ) {
			return __( 'Local agent', 'kayzart-live-code-editor' );
		}
		/* translators: %s: agent name set with KAYZART_AGENT_NAME, such as Claude Code. */
		return sprintf( __( '%s (local)', 'kayzart-live-code-editor' ), $name );
	}

	/**
	 * Clean an agent name supplied through the environment.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function sanitize_agent_name( string $name ): string {
		return mb_substr( trim( sanitize_text_field( $name ) ), 0, 64 );
	}

	/**
	 * Build the result of a successful write.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $saved   Save response data.
	 * @param array $sent    Snapshot the agent asked to store.
	 * @return array
	 */
	private static function write_result( int $post_id, array $saved, array $sent ): array {
		$page     = self::get_page( $post_id );
		$warnings = array();
		if ( ! empty( $saved['customHeadRemovedTags'] ) && is_array( $saved['customHeadRemovedTags'] ) ) {
			$warnings[] = sprintf(
				/* translators: %s: comma-separated tag names. */
				__( 'These tags were removed from head: %s', 'kayzart-live-code-editor' ),
				implode( ', ', array_map( 'strval', $saved['customHeadRemovedTags'] ) )
			);
		}
		if ( is_array( $page ) && $page['html'] !== $sent['html'] ) {
			$warnings[] = __( 'WordPress changed the html while saving, for example by removing markup your account cannot save. The page field shows what was stored.', 'kayzart-live-code-editor' );
		}

		return array(
			'page'       => is_array( $page ) ? $page : array(),
			'revisionId' => isset( $saved['revision']['id'] ) ? (int) $saved['revision']['id'] : null,
			'warnings'   => array_merge( $warnings, Agent_Checks::inspect( $post_id ) ),
		);
	}

	/**
	 * Summarize a page for listings.
	 *
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	private static function summary( \WP_Post $post ): array {
		$post_id = (int) $post->ID;
		return array(
			'id'       => $post_id,
			'title'    => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'status'   => (string) $post->post_status,
			'postType' => (string) $post->post_type,
			'cssMode'  => '1' === get_post_meta( $post_id, '_kayzart_tailwind', true ) ? 'tailwind' : 'normal',
			'modified' => mysql_to_rfc3339( (string) $post->post_modified_gmt ),
			'editUrl'  => Admin::get_open_editor_url( $post_id ),
			'viewUrl'  => 'publish' === $post->post_status ? (string) get_permalink( $post ) : (string) get_preview_post_link( $post ),
		);
	}

	/**
	 * Build an error for a target the user cannot write.
	 *
	 * @param string $target Target name.
	 * @return \WP_Error
	 */
	private static function target_error( string $target ): \WP_Error {
		if ( 'js' === $target ) {
			return self::error( 'kayzart_target_read_only', __( 'JavaScript is read-only for agents.', 'kayzart-live-code-editor' ), 400 );
		}
		if ( 'head' === $target ) {
			return self::error( 'kayzart_target_read_only', __( 'Your account cannot edit the custom head.', 'kayzart-live-code-editor' ), 403 );
		}
		return self::error( 'kayzart_invalid_target', __( 'Unknown target. Use html, css or head.', 'kayzart-live-code-editor' ), 400 );
	}

	/**
	 * Build a WP_Error with an HTTP status.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $data    Extra error data.
	 * @return \WP_Error
	 */
	private static function error( string $code, string $message, int $status, array $data = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( $data, array( 'status' => $status ) ) );
	}
}
