<?php
/**
 * Full-page revision snapshots for Kayzart-managed posts.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers revisioned metadata and reads/writes complete editor snapshots.
 */
class Snapshot {

	public const MINIMUM_WP_VERSION = '6.4';
	public const SCHEMA_VERSION     = '1';
	public const SCHEMA_META_KEY    = '_kayzart_snapshot_schema';
	public const HASH_META_KEY      = '_kayzart_snapshot_hash';

	/**
	 * Metadata included in a complete snapshot.
	 *
	 * @var array<int,string>
	 */
	private const REVISIONED_META_KEYS = array(
		Html_Document::BODY_ATTRS_META_KEY,
		Custom_Head::META_KEY,
		'_kayzart_css',
		'_kayzart_js',
		'_kayzart_js_mode',
		self::SCHEMA_META_KEY,
		self::HASH_META_KEY,
	);

	/**
	 * Register WordPress hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_revision_support' ), 100 );
	}

	/**
	 * Whether the running WordPress version supports post-meta revisions.
	 */
	public static function is_supported(): bool {
		global $wp_version;

		return version_compare( (string) $wp_version, self::MINIMUM_WP_VERSION, '>=' )
			&& function_exists( 'wp_post_revision_meta_keys' )
			&& function_exists( 'wp_restore_post_revision_meta' );
	}

	/**
	 * Add revision support and register all snapshot metadata.
	 */
	public static function register_revision_support(): void {
		if ( ! self::is_supported() ) {
			return;
		}

		$post_types = Post_Type::get_enabled_post_types();
		if ( Post_Type::has_legacy_posts() && ! in_array( Post_Type::POST_TYPE, $post_types, true ) ) {
			$post_types[] = Post_Type::POST_TYPE;
		}

		foreach ( array_unique( $post_types ) as $post_type ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
				continue;
			}

			add_post_type_support( $post_type, 'revisions' );
			foreach ( self::REVISIONED_META_KEYS as $meta_key ) {
				register_post_meta(
					$post_type,
					$meta_key,
					array(
						'type'              => 'string',
						'single'            => true,
						'show_in_rest'      => false,
						'revisions_enabled' => true,
					)
				);
			}
		}
	}

	/**
	 * Read the current saved snapshot for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{html:string,customHead:string,css:string,js:string,jsMode:string}
	 */
	public static function for_post( int $post_id ): array {
		$post       = get_post( $post_id );
		$content    = $post instanceof \WP_Post ? (string) $post->post_content : '';
		$body_attrs = (string) get_post_meta( $post_id, Html_Document::BODY_ATTRS_META_KEY, true );

		return self::normalize(
			array(
				'html'       => Html_Document::build_editor_html( $content, $body_attrs ),
				'customHead' => Custom_Head::get_for_post( $post_id ),
				'css'        => (string) get_post_meta( $post_id, '_kayzart_css', true ),
				'js'         => (string) get_post_meta( $post_id, '_kayzart_js', true ),
				'jsMode'     => (string) get_post_meta( $post_id, '_kayzart_js_mode', true ),
			)
		);
	}

	/**
	 * Read a snapshot stored on a WordPress revision.
	 *
	 * @param int $revision_id Revision ID.
	 * @return array{html:string,customHead:string,css:string,js:string,jsMode:string}|null
	 */
	public static function for_revision( int $revision_id ): ?array {
		if ( ! self::is_supported() || self::SCHEMA_VERSION !== (string) get_metadata( 'post', $revision_id, self::SCHEMA_META_KEY, true ) ) {
			return null;
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision instanceof \WP_Post || wp_is_post_autosave( $revision ) ) {
			return null;
		}

		$body_attrs  = (string) get_metadata( 'post', $revision_id, Html_Document::BODY_ATTRS_META_KEY, true );
		$snapshot    = self::normalize(
			array(
				'html'       => Html_Document::build_editor_html( (string) $revision->post_content, $body_attrs ),
				'customHead' => (string) get_metadata( 'post', $revision_id, Custom_Head::META_KEY, true ),
				'css'        => (string) get_metadata( 'post', $revision_id, '_kayzart_css', true ),
				'js'         => (string) get_metadata( 'post', $revision_id, '_kayzart_js', true ),
				'jsMode'     => (string) get_metadata( 'post', $revision_id, '_kayzart_js_mode', true ),
			)
		);
		$stored_hash = (string) get_metadata( 'post', $revision_id, self::HASH_META_KEY, true );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, self::hash( $snapshot ) ) ) {
			return null;
		}
		return $snapshot;
	}

	/**
	 * Normalize a snapshot to its canonical public shape.
	 *
	 * @param array<string,mixed> $snapshot Raw snapshot.
	 * @return array{html:string,customHead:string,css:string,js:string,jsMode:string}
	 */
	public static function normalize( array $snapshot ): array {
		return array(
			'html'       => isset( $snapshot['html'] ) ? (string) $snapshot['html'] : '',
			'customHead' => isset( $snapshot['customHead'] ) ? (string) $snapshot['customHead'] : '',
			'css'        => isset( $snapshot['css'] ) ? (string) $snapshot['css'] : '',
			'js'         => isset( $snapshot['js'] ) ? (string) $snapshot['js'] : '',
			'jsMode'     => Rest_Save::normalize_js_mode( isset( $snapshot['jsMode'] ) ? $snapshot['jsMode'] : '' ),
		);
	}

	/**
	 * Compute a stable hash for a snapshot.
	 *
	 * @param array<string,mixed> $snapshot Snapshot data.
	 */
	public static function hash( array $snapshot ): string {
		$encoded = wp_json_encode( self::normalize( $snapshot ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	/**
	 * Whether WordPress revisions are enabled for the post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function revisions_enabled( int $post_id ): bool {
		$post = get_post( $post_id );
		return self::is_supported() && $post instanceof \WP_Post && wp_revisions_enabled( $post );
	}

	/**
	 * Create one complete revision for the post's current saved state.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null Revision ID, or null when revisions are disabled/failed.
	 */
	public static function create_revision( int $post_id ): ?int {
		if ( ! self::revisions_enabled( $post_id ) ) {
			return null;
		}

		$snapshot = self::for_post( $post_id );
		update_post_meta( $post_id, self::SCHEMA_META_KEY, self::SCHEMA_VERSION );
		update_post_meta( $post_id, self::HASH_META_KEY, self::hash( $snapshot ) );

		$skip_change_check = static function () {
			return false;
		};
		add_filter( 'wp_save_post_revision_check_for_changes', $skip_change_check, 10, 3 );
		try {
			$revision_id = wp_save_post_revision( $post_id );
		} finally {
			remove_filter( 'wp_save_post_revision_check_for_changes', $skip_change_check, 10 );
		}

		return is_int( $revision_id ) && $revision_id > 0 ? $revision_id : null;
	}
}
