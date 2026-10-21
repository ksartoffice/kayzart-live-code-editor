<?php
/**
 * Working copies that hold an external agent's proposed page changes.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One hidden post per page and agent, where the agent's updates are saved.
 *
 * An agent never writes the page itself. Its updates go to its working copy,
 * and a person merges them into the page in the editor and saves. The page
 * therefore keeps a single writer, the editor's save, and needs no lock.
 * The working copy has a single writer too, the agent it belongs to, and is
 * a real post of the page's type, so saving, Tailwind compilation, page
 * checks and preview links work on it unchanged.
 *
 * A working copy remembers the page version it started from. While the page
 * still has that version, the agent keeps building on the copy. Once the
 * page changed in a way that does not include the copy (a person saved
 * something else), the copy is out of date and its next update starts over
 * from the page. Nothing is lost that way: every update is kept with its
 * snapshots in the AI Edit history.
 *
 * Each start begins a new chain. The proposals recorded on one chain build
 * on each other, so merging the newest one merges them all.
 */
final class Agent_Working_Copy {

	/** Internal post status of working copies. At most 20 characters. */
	const STATUS = 'kayzart_agent_copy';

	/** Meta key holding the page a working copy belongs to. */
	const SOURCE_META = '_kayzart_agent_copy_of';

	/** Meta key holding the agent a working copy belongs to. */
	const AGENT_META = '_kayzart_agent_copy_agent';

	/** Meta key holding the page version a working copy started from. */
	const BASE_META = '_kayzart_agent_copy_base';

	/** Meta key holding the chain the working copy's proposals belong to. */
	const CHAIN_META = '_kayzart_agent_copy_chain';

	/** Option set once an agent has written to this site. */
	const IN_USE_OPTION = 'kayzart_agent_in_use';

	/** Page meta that is not carried over to a working copy. */
	const META_DENYLIST = array(
		'_kayzart_setup_required',
		'_kayzart_screen',
		Admin::INITIAL_AI_REQUEST_META_KEY,
		Agent_Preview::META_KEY,
		self::SOURCE_META,
		self::AGENT_META,
		self::BASE_META,
		self::CHAIN_META,
		Agent_Pages::SEEN_META,
	);

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_for_page' ) );
	}

	/**
	 * Register the internal status that keeps working copies out of every list.
	 */
	public static function register_status(): void {
		register_post_status(
			self::STATUS,
			array(
				'label'                     => _x( 'Agent working copy', 'post status', 'kayzart-live-code-editor' ),
				'public'                    => false,
				'internal'                  => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
			)
		);
	}

	/**
	 * How the open editor watches for incoming proposals.
	 *
	 * - none: agents cannot connect (no Abilities API), so it never checks.
	 * - focus: agents could connect but nothing shows one is used. It checks
	 *   once when the person comes back to the editor window or tab, which
	 *   is when a person who asked an agent elsewhere looks for the result.
	 * - poll: an agent has written here, or the MCP Adapter that agents
	 *   connect through is active. It also checks on a timer, so a person
	 *   watching the editor next to the agent sees proposals arrive.
	 *
	 * @return string none, focus or poll.
	 */
	public static function proposal_checks(): string {
		if ( ! Abilities::is_supported() ) {
			return 'none';
		}
		$in_use = '1' === get_option( self::IN_USE_OPTION, '' ) || class_exists( '\\WP\\MCP\\Core\\McpAdapter' );
		$checks = $in_use ? 'poll' : 'focus';
		/**
		 * Filters how the open editor watches for agent proposals.
		 *
		 * Return poll on a site whose agents connect through another MCP
		 * server, or focus to avoid timed checks.
		 *
		 * @param string $checks focus or poll.
		 */
		$checks = (string) apply_filters( 'kayzart_agent_proposal_checks', $checks );
		return in_array( $checks, array( 'none', 'focus', 'poll' ), true ) ? $checks : 'focus';
	}

	/**
	 * Remember that an agent has written to this site.
	 */
	public static function mark_in_use(): void {
		if ( '1' !== get_option( self::IN_USE_OPTION, '' ) ) {
			update_option( self::IN_USE_OPTION, '1', false );
		}
	}

	/**
	 * Whether a post is a working copy.
	 *
	 * @param int|\WP_Post $post Post ID or object.
	 * @return bool
	 */
	public static function is_copy( $post ): bool {
		$post = get_post( $post );
		return $post instanceof \WP_Post && self::STATUS === $post->post_status;
	}

	/**
	 * Page a working copy belongs to.
	 *
	 * @param int $copy_id Working copy ID.
	 * @return int 0 when the post is not a working copy.
	 */
	public static function page_of( int $copy_id ): int {
		return self::is_copy( $copy_id ) ? (int) get_post_meta( $copy_id, self::SOURCE_META, true ) : 0;
	}

	/**
	 * Version of a page or working copy, including read-only context, used
	 * to detect changes since the agent last read it.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function version( int $post_id ): string {
		return Snapshot::hash( Snapshot::for_post( $post_id ) );
	}

	/**
	 * Version of only the proposed sources; JavaScript stays with the page.
	 *
	 * @param array $snapshot Editor snapshot.
	 * @return string
	 */
	public static function proposal_version( array $snapshot ): string {
		return hash(
			'sha256',
			wp_json_encode(
				array(
					'html'       => (string) ( $snapshot['html'] ?? '' ),
					'customHead' => (string) ( $snapshot['customHead'] ?? '' ),
					'css'        => (string) ( $snapshot['css'] ?? '' ),
				)
			)
		);
	}

	/**
	 * The agent's working copy of a page, current or out of date.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $agent   Agent key.
	 * @return int 0 when there is none.
	 */
	public static function find( int $page_id, string $agent ): int {
		$ids = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => self::STATUS,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Working copies are found by their page and agent.
				'meta_query'       => array(
					array(
						'key'   => self::SOURCE_META,
						'value' => (string) $page_id,
					),
					array(
						'key'   => self::AGENT_META,
						'value' => $agent,
					),
				),
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * The agent's working copy of a page while the page has not moved on.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $agent   Agent key.
	 * @return int 0 when there is none or it is out of date.
	 */
	public static function current( int $page_id, string $agent ): int {
		$copy_id = self::find( $page_id, $agent );
		if ( ! $copy_id ) {
			return 0;
		}
		return hash_equals( (string) get_post_meta( $copy_id, self::BASE_META, true ), self::version( $page_id ) ) ? $copy_id : 0;
	}

	/**
	 * Start a working copy from the page as it is now.
	 *
	 * An out-of-date working copy is reset in place, so its ID and any
	 * preview link issued for it stay valid.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $agent   Agent key.
	 * @return array{id:int,base:string}|\WP_Error The copy and the page version it started from.
	 */
	public static function start( int $page_id, string $agent ) {
		$page = get_post( $page_id );
		if ( ! $page instanceof \WP_Post ) {
			return new \WP_Error( 'kayzart_page_not_found', __( 'Kayzart page not found.', 'kayzart-live-code-editor' ), array( 'status' => 404 ) );
		}
		$base    = self::version( $page_id );
		$copy_id = self::find( $page_id, $agent );
		$fields  = array(
			'post_title'   => wp_slash( $page->post_title ),
			'post_content' => wp_slash( $page->post_content ),
			'post_excerpt' => wp_slash( $page->post_excerpt ),
		);

		// The content is the page's, already stored by someone allowed to; it
		// must stay byte-identical so the copy starts at the page's version.
		$kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		try {
			if ( $copy_id ) {
				$result = wp_update_post( array_merge( $fields, array( 'ID' => $copy_id ) ), true );
			} else {
				$result = wp_insert_post(
					array_merge(
						$fields,
						array(
							'post_type'      => $page->post_type,
							'post_status'    => self::STATUS,
							'post_author'    => get_current_user_id(),
							'post_name'      => 'kayzart-agent-copy-' . $page_id . '-' . substr( $agent, 0, 8 ),
							'comment_status' => 'closed',
							'ping_status'    => 'closed',
						)
					),
					true
				);
			}
		} finally {
			if ( $kses ) {
				kses_init_filters();
			}
		}
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( 'kayzart_copy_failed', $result->get_error_message(), array( 'status' => 500 ) );
		}
		$copy_id = (int) $result;

		self::copy_meta( $page_id, $copy_id );
		update_post_meta( $copy_id, self::SOURCE_META, (string) $page_id );
		update_post_meta( $copy_id, self::AGENT_META, $agent );
		update_post_meta( $copy_id, self::BASE_META, $base );
		update_post_meta( $copy_id, self::CHAIN_META, wp_generate_uuid4() );
		clean_post_cache( $copy_id );

		return array(
			'id'   => $copy_id,
			'base' => $base,
		);
	}

	/**
	 * Chain the working copy's proposals belong to.
	 *
	 * @param int $copy_id Working copy ID.
	 * @return string
	 */
	public static function chain( int $copy_id ): string {
		return (string) get_post_meta( $copy_id, self::CHAIN_META, true );
	}

	/**
	 * Settle proposals when the editor saves the page.
	 *
	 * Runs inside the save and looks only at what was saved: a proposal is
	 * taken in when the saved HTML, CSS and head match that proposal. Nothing the
	 * browser remembers about merging is trusted, so that record can never
	 * drift from the content. A proposal merged and then edited before saving
	 * stays pending, and the person discards it. Taken-in proposals, and the
	 * earlier ones on their chains, become applied.
	 *
	 * A working copy that holds nothing beyond the saved page is deleted.
	 * One the agent has added to since continues from the saved page, as
	 * long as the page was saved with the merged proposal unchanged.
	 * Otherwise it is left out of date, and its next update starts over.
	 *
	 * @param int $page_id Page just saved.
	 * @return int Number of proposals marked applied.
	 */
	public static function settle( int $page_id ): int {
		if ( self::is_copy( $page_id ) ) {
			return 0;
		}
		$store   = new Ai_Timeline_Store();
		$version = self::version( $page_id );
		$sources = self::proposal_version( Snapshot::for_post( $page_id ) );
		$result  = $store->mark_proposals_merged( $page_id, $sources );
		foreach ( $result['newest'] as $proposal ) {
			$copy_id = self::find_chain( $page_id, $proposal['chain'] );
			if ( ! $copy_id ) {
				continue;
			}
			if ( hash_equals( self::proposal_version( Snapshot::for_post( $copy_id ) ), $sources ) ) {
				self::delete( $copy_id );
			} elseif ( '' !== $proposal['version'] && hash_equals( $proposal['version'], $sources ) ) {
				update_post_meta( $copy_id, self::BASE_META, $version );
			}
		}
		return $result['count'];
	}

	/**
	 * Discard a proposal's chain and the working copy holding it.
	 *
	 * @param int $page_id     Page ID.
	 * @param int $activity_id Proposal activity.
	 * @return bool False when the activity is not a pending proposal of the page.
	 */
	public static function discard( int $page_id, int $activity_id ): bool {
		$chain = ( new Ai_Timeline_Store() )->discard_proposal_chain( $page_id, $activity_id );
		if ( null === $chain ) {
			return false;
		}
		$copy_id = self::find_chain( $page_id, $chain );
		if ( $copy_id ) {
			self::delete( $copy_id );
		}
		return true;
	}

	/**
	 * The working copy of a page that holds a chain.
	 *
	 * @param int    $page_id Page ID.
	 * @param string $chain   Chain.
	 * @return int 0 when that chain's copy is gone or has started over.
	 */
	private static function find_chain( int $page_id, string $chain ): int {
		if ( '' === $chain ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => self::STATUS,
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Working copies are found by their page and chain.
				'meta_query'       => array(
					array(
						'key'   => self::SOURCE_META,
						'value' => (string) $page_id,
					),
					array(
						'key'   => self::CHAIN_META,
						'value' => $chain,
					),
				),
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Delete a working copy and its revisions.
	 *
	 * @param int $copy_id Working copy ID.
	 */
	public static function delete( int $copy_id ): void {
		if ( self::is_copy( $copy_id ) ) {
			wp_delete_post( $copy_id, true );
		}
	}

	/**
	 * Delete every working copy of a page that is being deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public static function delete_for_page( $post_id ): void {
		$post_id = (int) $post_id;
		if ( self::is_copy( $post_id ) ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'        => array_values( get_post_types() ),
				'post_status'      => self::STATUS,
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'no_found_rows'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Working copies are found by their page.
				'meta_query'       => array(
					array(
						'key'   => self::SOURCE_META,
						'value' => (string) $post_id,
					),
				),
			)
		);
		foreach ( $ids as $copy_id ) {
			self::delete( (int) $copy_id );
		}
	}

	/**
	 * Replace a working copy's Kayzart meta with the page's.
	 *
	 * Everything that affects how the page renders comes along: sources,
	 * modes, generated CSS, template settings and the page template.
	 *
	 * @param int $page_id Page ID.
	 * @param int $copy_id Working copy ID.
	 */
	private static function copy_meta( int $page_id, int $copy_id ): void {
		foreach ( array_keys( (array) get_post_meta( $copy_id ) ) as $key ) {
			if ( self::carries_over( (string) $key ) ) {
				delete_post_meta( $copy_id, $key );
			}
		}
		foreach ( (array) get_post_meta( $page_id ) as $key => $values ) {
			if ( ! self::carries_over( (string) $key ) ) {
				continue;
			}
			foreach ( (array) $values as $value ) {
				add_post_meta( $copy_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	/**
	 * Whether a page meta key is carried over to its working copy.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private static function carries_over( string $key ): bool {
		if ( '_wp_page_template' === $key ) {
			return true;
		}
		return 0 === strpos( $key, '_kayzart_' ) && ! in_array( $key, self::META_DENYLIST, true );
	}
}
