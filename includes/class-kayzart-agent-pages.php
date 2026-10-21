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
 * A new page is created as a draft directly. An update never touches the
 * page: it is saved to the agent's working copy (Agent_Working_Copy) as a
 * proposal, which a person merges in the editor and saves. The agent reads
 * and previews its working copy while one is current, so it can keep
 * building on its own changes.
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

	/** Option and page meta holding when an agent last called Kayzart. */
	const SEEN_OPTION = 'kayzart_agent_seen';
	const SEEN_META   = '_kayzart_agent_seen';

	/** Seconds within which a repeated call is not recorded again. */
	const SEEN_RESOLUTION = 30;

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
			&& ! in_array( $post->post_status, array( 'trash', Agent_Working_Copy::STATUS ), true )
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
		self::seen();
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
		self::seen();
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
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'update_post_term_cache' => false,
		);
		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = sanitize_text_field( (string) $input['search'] );
		}

		// Return only attachments the caller may read, as /wp/v2/media does: a
		// file attached to someone else's draft or private post stays hidden.
		// Users who can read every post skip the per-attachment check.
		if ( self::can_read_all_attachments() ) {
			$query       = new \WP_Query(
				array_merge(
					$args,
					array(
						'posts_per_page' => $per_page,
						'paged'          => $page,
					)
				)
			);
			$attachments = $query->posts;
			$total       = (int) $query->found_posts;
		} else {
			// Count readable files without keeping the entire library in memory.
			// Exact totals still require a scan, but only one batch and the
			// requested page are retained. Permission checks stay per attachment.
			$total       = 0;
			$attachments = array();
			$offset      = ( $page - 1 ) * $per_page;
			$scan_page   = 1;
			$batch_size  = 100;
			do {
				$query = new \WP_Query(
					array_merge(
						$args,
						array(
							'posts_per_page'         => $batch_size,
							'paged'                  => $scan_page++,
							'fields'                 => 'ids',
							'orderby'                => array(
								'date' => 'DESC',
								'ID'   => 'DESC',
							),
							'no_found_rows'          => true,
							'cache_results'          => false,
							'update_post_meta_cache' => false,
						)
					)
				);
				$ids   = array_map( 'intval', $query->posts );
				$added = self::prime_scan_posts( $ids );
				try {
					$parents = array();
					foreach ( $ids as $id ) {
						$attachment = get_post( $id );
						if ( $attachment instanceof \WP_Post && $attachment->post_parent > 0 ) {
							$parents[] = (int) $attachment->post_parent;
						}
					}
					$added = array_merge( $added, self::prime_scan_posts( array_unique( $parents ) ) );
					foreach ( $ids as $id ) {
						if ( self::can_read_attachment( $id ) ) {
							if ( $total >= $offset && count( $attachments ) < $per_page ) {
								$attachments[] = get_post( $id );
							}
							++$total;
						}
					}
				} finally {
					foreach ( $added as $id ) {
						wp_cache_delete( $id, 'posts' );
					}
				}
				$batch_count = count( $ids );
			} while ( $batch_count === $batch_size );
		}

		$items = array();
		foreach ( $attachments as $attachment ) {
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
			'totalPages' => (int) ceil( $total / $per_page ),
			'total'      => $total,
		);
	}

	/**
	 * Prime one scan batch and return only cache entries added by this scan.
	 *
	 * @param array<int> $ids Post IDs.
	 * @return array<int>
	 */
	private static function prime_scan_posts( array $ids ): array {
		$added = array();
		foreach ( $ids as $id ) {
			if ( false === wp_cache_get( $id, 'posts' ) ) {
				$added[] = $id;
			}
		}
		_prime_post_caches( $ids, false, false );
		return $added;
	}

	/**
	 * Whether the current user may see an attachment, mirroring
	 * WP_REST_Posts_Controller::check_read_permission(): a file attached to a
	 * post is as readable as that post, and an unattached file is treated as
	 * published.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private static function can_read_attachment( int $attachment_id ): bool {
		if ( current_user_can( 'read_post', $attachment_id ) ) {
			return true;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment instanceof \WP_Post || 'inherit' !== $attachment->post_status ) {
			return false;
		}
		$parent = $attachment->post_parent > 0 ? get_post( $attachment->post_parent ) : null;
		if ( ! $parent instanceof \WP_Post ) {
			return true;
		}
		$status = get_post_status_object( $parent->post_status );
		return ( $status && $status->public ) || current_user_can( 'read_post', $parent->ID );
	}

	/**
	 * Whether the current user can read every attachment, including files
	 * attached to other people's drafts and private posts and pages.
	 *
	 * @return bool
	 */
	private static function can_read_all_attachments(): bool {
		foreach ( array( 'edit_others_posts', 'edit_others_pages', 'read_private_posts', 'read_private_pages' ) as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				return false;
			}
		}
		return true;
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
		self::seen( $post_id );
		$copy_id = Agent_Working_Copy::current( $post_id, self::agent_key() );
		return array_merge(
			Agent_Preview::issue( $copy_id ? $copy_id : $post_id ),
			array( 'state' => $copy_id ? 'proposed' : 'saved' )
		);
	}

	/**
	 * Read one page with its sources.
	 *
	 * While the agent has a current working copy, its proposal is returned
	 * (state "proposed"), so the agent keeps building on its own changes;
	 * otherwise the saved page (state "saved").
	 *
	 * @param int $post_id Post ID.
	 * @return array|\WP_Error
	 */
	public static function get_page( int $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! self::is_agent_page( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}
		self::seen( $post_id );
		$copy_id    = Agent_Working_Copy::current( $post_id, self::agent_key() );
		$working_id = $copy_id ? $copy_id : $post_id;
		$snapshot   = Snapshot::for_post( $working_id );

		return array_merge(
			self::summary( $post ),
			array(
				'state'           => $copy_id ? 'proposed' : 'saved',
				'html'            => $snapshot['html'],
				'css'             => $snapshot['css'],
				'head'            => $snapshot['customHead'],
				'js'              => $snapshot['js'],
				'baseHash'        => Agent_Working_Copy::version( $working_id ),
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
		self::record_activity( $post_id, $post_id, $saved, $before, (string) ( $input['summary'] ?? '' ), 'create' );

		return self::write_result( $post_id, $post_id, $saved, $after );
	}

	/**
	 * Propose changes to an existing page with full sources and/or exact
	 * replacements.
	 *
	 * The change is saved to the agent's working copy, never to the page. The
	 * working copy starts from the page on the first update, and again once
	 * the page has moved on without it.
	 *
	 * The title is not proposed: titles are saved the moment they change,
	 * outside the editor's unsaved state, so they cannot wait for a merge.
	 *
	 * @param array $input Ability input (id, baseHash, html, css, head, edits).
	 * @return array|\WP_Error
	 */
	public static function update_page( array $input ) {
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( ! self::can_edit( $post_id ) ) {
			return self::error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), 404 );
		}
		$agent      = self::agent_key();
		$copy_id    = Agent_Working_Copy::current( $post_id, $agent );
		$working_id = $copy_id ? $copy_id : $post_id;
		$version    = Agent_Working_Copy::version( $working_id );
		if ( ! hash_equals( $version, (string) ( $input['baseHash'] ?? '' ) ) ) {
			return self::page_changed_error();
		}
		$before = Snapshot::for_post( $working_id );

		$sources = array();
		foreach ( array( 'html', 'css', 'head' ) as $target ) {
			if ( isset( $input[ $target ] ) ) {
				$sources[ $target ] = (string) $input[ $target ];
			}
		}
		$edits = isset( $input['edits'] ) && is_array( $input['edits'] ) ? $input['edits'] : array();
		if ( empty( $sources ) && empty( $edits ) ) {
			return self::error( 'kayzart_nothing_to_update', __( 'Provide html, css, head or edits to update.', 'kayzart-live-code-editor' ), 400 );
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

		if ( ! $copy_id ) {
			$started = Agent_Working_Copy::start( $post_id, $agent );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			// A person saved the page after the check above: the change was
			// made to the older version, so it must be made again.
			if ( ! hash_equals( $started['base'], $version ) ) {
				return self::page_changed_error();
			}
			$copy_id = $started['id'];
		}

		$head_changed = $before['customHead'] !== $after['customHead'];
		$saved        = self::save( $copy_id, $after, $head_changed );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		self::record_activity( $post_id, $copy_id, $saved, $before, (string) ( $input['summary'] ?? '' ), 'update' );

		return self::write_result( $post_id, $copy_id, $saved, $after );
	}

	/**
	 * Error for an update made to sources that are no longer current.
	 *
	 * @return \WP_Error
	 */
	private static function page_changed_error(): \WP_Error {
		return self::error(
			'kayzart_page_changed',
			__( 'The page changed after you read it, for example because a person saved it. Call kayzart/get-page again and reapply your change to the current sources.', 'kayzart-live-code-editor' ),
			409
		);
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
	 * @param int   $post_id      Post ID.
	 * @param array $snapshot     Snapshot to persist.
	 * @param bool  $include_head Whether to write the custom head.
	 * @return array|\WP_Error Save response data.
	 */
	private static function save( int $post_id, array $snapshot, bool $include_head ) {
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

		$response = Rest_Save::save( $request );
		$data     = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $data ) || empty( $data['ok'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : __( 'Save failed.', 'kayzart-live-code-editor' );
			return self::error( 'kayzart_save_failed', $message, $response->get_status() >= 400 ? $response->get_status() : 500 );
		}
		return $data;
	}

	/**
	 * Show the write in the page's AI history as an agent edit.
	 *
	 * A write to a working copy is recorded on its page as a proposal.
	 *
	 * @param int         $page_id      Page the write belongs to.
	 * @param int         $written_id   Post written: the page, or the agent's working copy.
	 * @param array       $saved        Save response data.
	 * @param array       $before       Snapshot before the write.
	 * @param string      $summary      Agent-written description of the change.
	 * @param string      $action       create or update.
	 * @param string|null $title_before Title before an update; null for a new page.
	 */
	private static function record_activity( int $page_id, int $written_id, array $saved, array $before, string $summary, string $action, ?string $title_before = null ): void {
		$after        = Snapshot::for_post( $written_id );
		$title_after  = (string) get_post_field( 'post_title', $written_id, 'raw' );
		$title_change = null !== $title_before && $title_before !== $title_after
			? array(
				'before' => $title_before,
				'after'  => $title_after,
			)
			: null;
		if ( null === $title_change && Snapshot::hash( Snapshot::normalize( $before ) ) === Snapshot::hash( $after ) ) {
			return;
		}
		$proposal = $written_id !== $page_id;
		Agent_Working_Copy::mark_in_use();
		( new Ai_Timeline_Store() )->record_agent_edit(
			$page_id,
			get_current_user_id(),
			isset( $saved['revision']['id'] ) ? (int) $saved['revision']['id'] : null,
			$before,
			$after,
			array(
				'summary'     => sanitize_textarea_field( $summary ),
				'client'      => self::client_label(),
				'action'      => $action,
				'titleChange' => $title_change,
				'proposal'    => $proposal,
				'agentKey'    => $proposal ? self::agent_key() : '',
				'chain'       => $proposal ? Agent_Working_Copy::chain( $written_id ) : '',
			)
		);
	}

	/**
	 * Record that an agent is at work, on the site and on a page.
	 *
	 * An agent reads before it writes, so an open editor can start checking
	 * for proposals often before the first one arrives. Calls within
	 * SEEN_RESOLUTION seconds of the last record write nothing.
	 *
	 * @param int $post_id Page the agent is working on, or 0.
	 */
	private static function seen( int $post_id = 0 ): void {
		$now = time();
		if ( $now - (int) get_option( self::SEEN_OPTION, 0 ) >= self::SEEN_RESOLUTION ) {
			update_option( self::SEEN_OPTION, $now, false );
		}
		if ( $post_id > 0 && $now - (int) get_post_meta( $post_id, self::SEEN_META, true ) >= self::SEEN_RESOLUTION ) {
			update_post_meta( $post_id, self::SEEN_META, $now );
		}
	}

	/**
	 * Seconds since an agent last called Kayzart for a page or for the site.
	 *
	 * @param int $post_id Page ID.
	 * @return int|null Null when no agent has.
	 */
	public static function seen_seconds_ago( int $post_id ): ?int {
		$last = max( (int) get_option( self::SEEN_OPTION, 0 ), (int) get_post_meta( $post_id, self::SEEN_META, true ) );
		return $last > 0 ? max( 0, time() - $last ) : null;
	}

	/**
	 * Identify the agent a working copy belongs to.
	 *
	 * Scoped to the WordPress user. Over HTTP the application password is
	 * identified by its UUID rather than its name, which can be edited. Over
	 * a local STDIO connection it is the name from KAYZART_AGENT_NAME. Two
	 * agents with different keys never share a working copy.
	 *
	 * @return string Opaque key.
	 */
	public static function agent_key(): string {
		$uuid = '';
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && function_exists( 'rest_get_authenticated_app_password' ) ) {
			$uuid = (string) rest_get_authenticated_app_password();
		}
		$agent = '' !== $uuid ? 'app:' . $uuid : 'label:' . self::client_label();
		return hash( 'sha256', get_current_user_id() . '|' . $agent );
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
	 * @param int   $page_id    Page ID.
	 * @param int   $written_id Post written: the page, or the agent's working copy.
	 * @param array $saved      Save response data.
	 * @param array $sent       Snapshot the agent asked to store.
	 * @return array
	 */
	private static function write_result( int $page_id, int $written_id, array $saved, array $sent ): array {
		$page     = self::get_page( $page_id );
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

		$result = array(
			'page'       => is_array( $page ) ? $page : array(),
			'state'      => $written_id === $page_id ? 'saved' : 'proposed',
			'revisionId' => isset( $saved['revision']['id'] ) ? (int) $saved['revision']['id'] : null,
			'warnings'   => array_merge( $warnings, Agent_Checks::inspect( $written_id ) ),
		);
		if ( 'proposed' === $result['state'] ) {
			$result['message'] = __( 'Saved as a proposal. The page itself does not change until a person opens it in the Kayzart editor, merges your changes and saves. Tell the person and give them editUrl. Keep updating with the returned baseHash to refine the proposal.', 'kayzart-live-code-editor' );
		}
		return $result;
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
