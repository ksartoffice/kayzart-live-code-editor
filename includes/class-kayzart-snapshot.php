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

	public const MINIMUM_WP_VERSION     = '6.4';
	public const SCHEMA_VERSION         = '2';
	private const LEGACY_SCHEMA_VERSION = '1';
	public const SCHEMA_META_KEY        = '_kayzart_snapshot_schema';
	public const HASH_META_KEY          = '_kayzart_snapshot_hash';

	/**
	 * Metadata included in a complete snapshot.
	 *
	 * @var array<int,string>
	 */
	private const REVISIONED_META_KEYS = array(
		Html_Document::BODY_ATTRS_META_KEY,
		Custom_Head::META_KEY,
		'_kayzart_css',
		'_kayzart_normal_css',
		'_kayzart_tailwind_css',
		'_kayzart_tailwind',
		Tailwind_Compiler::CANDIDATES_META_KEY,
		'_kayzart_js',
		'_kayzart_js_mode',
		self::SCHEMA_META_KEY,
		self::HASH_META_KEY,
	);

	/**
	 * Whether a schema-1 revision is currently being restored.
	 *
	 * @var bool
	 */
	private static $preserving_legacy_mode_meta = false;

	/**
	 * Register WordPress hooks.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_revision_support' ), 100 );
		add_action( 'wp_restore_post_revision', array( __CLASS__, 'begin_legacy_revision_restore' ), 9, 2 );
		add_filter( 'wp_post_revision_meta_keys', array( __CLASS__, 'filter_revision_meta_keys_for_legacy_restore' ), 10, 2 );
		add_action( 'wp_restore_post_revision', array( __CLASS__, 'finish_legacy_revision_restore' ), 11, 2 );
		add_action( 'wp_restore_post_revision', array( __CLASS__, 'sync_generated_css_after_restore' ), 20, 2 );
	}

	/**
	 * Keep mode metadata when restoring a revision created before schema 2.
	 *
	 * WordPress clears every registered revisioned key before copying the
	 * revision values. Schema 1 has no mode-specific metadata to copy.
	 *
	 * @param int $post_id     Restored post ID.
	 * @param int $revision_id Revision ID.
	 */
	public static function begin_legacy_revision_restore( int $post_id, int $revision_id ): void {
		unset( $post_id );
		self::$preserving_legacy_mode_meta = self::LEGACY_SCHEMA_VERSION === (string) get_metadata( 'post', $revision_id, self::SCHEMA_META_KEY, true );
	}

	/**
	 * Exclude schema-2-only mode metadata from a legacy revision restore.
	 *
	 * @param array<int,string> $meta_keys Registered revisioned metadata keys.
	 * @param string            $post_type Post type being restored.
	 * @return array<int,string>
	 */
	public static function filter_revision_meta_keys_for_legacy_restore( array $meta_keys, string $post_type ): array {
		unset( $post_type );
		if ( ! self::$preserving_legacy_mode_meta ) {
			return $meta_keys;
		}

		return array_values(
			array_diff(
				$meta_keys,
				array(
					'_kayzart_normal_css',
					'_kayzart_tailwind_css',
					'_kayzart_tailwind',
				)
			)
		);
	}

	/**
	 * Reconcile the active CSS buffer after core restores legacy metadata.
	 *
	 * @param int $post_id     Restored post ID.
	 * @param int $revision_id Revision ID.
	 */
	public static function finish_legacy_revision_restore( int $post_id, int $revision_id ): void {
		unset( $revision_id );
		if ( self::$preserving_legacy_mode_meta ) {
			$restored_css = (string) get_post_meta( $post_id, '_kayzart_css', true );
			$active_key   = '1' === get_post_meta( $post_id, '_kayzart_tailwind', true )
				? '_kayzart_tailwind_css'
				: '_kayzart_normal_css';
			update_post_meta( $post_id, $active_key, wp_slash( $restored_css ) );
		}
		self::$preserving_legacy_mode_meta = false;
	}

	/**
	 * Rebuild derived Tailwind output after WordPress restores revisioned meta.
	 *
	 * @param int $post_id     Restored post ID.
	 * @param int $revision_id Revision ID.
	 */
	public static function sync_generated_css_after_restore( int $post_id, int $revision_id ): void {
		unset( $revision_id );
		if ( '1' !== get_post_meta( $post_id, '_kayzart_tailwind', true ) ) {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
			delete_post_meta( $post_id, Tailwind_Compiler::CANDIDATES_META_KEY );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		$candidates = Tailwind_Compiler::decode_candidates(
			get_post_meta( $post_id, Tailwind_Compiler::CANDIDATES_META_KEY, true )
		);
		if ( is_wp_error( $candidates ) ) {
			$candidates = Tailwind_Compiler::extract_candidates( (string) $post->post_content );
			if ( ! is_wp_error( $candidates ) ) {
				update_post_meta(
					$post_id,
					Tailwind_Compiler::CANDIDATES_META_KEY,
					wp_slash( Tailwind_Compiler::encode_candidates( $candidates ) )
				);
			}
		}

		if ( is_wp_error( $candidates ) ) {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
			return;
		}

		$generated_css = Tailwind_Compiler::generate(
			$candidates,
			(string) get_post_meta( $post_id, '_kayzart_css', true )
		);
		if ( is_wp_error( $generated_css ) ) {
			delete_post_meta( $post_id, '_kayzart_generated_css' );
			return;
		}
		update_post_meta( $post_id, '_kayzart_generated_css', wp_slash( $generated_css ) );
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
	 * @return array<string,mixed>
	 */
	public static function for_post( int $post_id ): array {
		$post       = get_post( $post_id );
		$content    = $post instanceof \WP_Post ? (string) $post->post_content : '';
		$body_attrs = (string) get_post_meta( $post_id, Html_Document::BODY_ATTRS_META_KEY, true );

		$css          = (string) get_post_meta( $post_id, '_kayzart_css', true );
		$editor_mode  = '1' === get_post_meta( $post_id, '_kayzart_tailwind', true ) ? 'tailwind' : 'normal';
		$normal_css   = get_post_meta( $post_id, '_kayzart_normal_css', true );
		$tailwind_css = get_post_meta( $post_id, '_kayzart_tailwind_css', true );
		$has_normal   = metadata_exists( 'post', $post_id, '_kayzart_normal_css' );
		$has_tailwind = metadata_exists( 'post', $post_id, '_kayzart_tailwind_css' );

		if ( ! $has_normal && 'normal' === $editor_mode ) {
			$normal_css = $css;
		}
		if ( ! $has_tailwind && 'tailwind' === $editor_mode ) {
			$tailwind_css = $css;
		}

		return self::normalize(
			array(
				'html'       => Html_Document::build_editor_html( $content, $body_attrs ),
				'customHead' => Custom_Head::get_for_post( $post_id ),
				'css'        => $css,
				'js'         => (string) get_post_meta( $post_id, '_kayzart_js', true ),
				'jsMode'     => (string) get_post_meta( $post_id, '_kayzart_js_mode', true ),
				'editorMode' => $editor_mode,
				'cssByMode'  => array(
					'normal'   => $has_normal || 'normal' === $editor_mode ? (string) $normal_css : null,
					'tailwind' => $has_tailwind || 'tailwind' === $editor_mode ? (string) $tailwind_css : null,
				),
			)
		);
	}

	/**
	 * Read a snapshot stored on a WordPress revision.
	 *
	 * @param int $revision_id Revision ID.
	 * @return array<string,mixed>|null
	 */
	public static function for_revision( int $revision_id ): ?array {
		if ( ! self::is_supported() ) {
			return null;
		}
		$schema_version = (string) get_metadata( 'post', $revision_id, self::SCHEMA_META_KEY, true );
		if ( self::SCHEMA_VERSION !== $schema_version && self::LEGACY_SCHEMA_VERSION !== $schema_version ) {
			return null;
		}

		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision instanceof \WP_Post || wp_is_post_autosave( $revision ) ) {
			return null;
		}

		$body_attrs  = (string) get_metadata( 'post', $revision_id, Html_Document::BODY_ATTRS_META_KEY, true );
		$raw         = array(
			'html'       => Html_Document::build_editor_html( (string) $revision->post_content, $body_attrs ),
			'customHead' => (string) get_metadata( 'post', $revision_id, Custom_Head::META_KEY, true ),
			'css'        => (string) get_metadata( 'post', $revision_id, '_kayzart_css', true ),
			'js'         => (string) get_metadata( 'post', $revision_id, '_kayzart_js', true ),
			'jsMode'     => (string) get_metadata( 'post', $revision_id, '_kayzart_js_mode', true ),
		);
		$stored_hash = (string) get_metadata( 'post', $revision_id, self::HASH_META_KEY, true );

		if ( self::LEGACY_SCHEMA_VERSION === $schema_version ) {
			if ( '' === $stored_hash || ! hash_equals( $stored_hash, self::legacy_hash( $raw ) ) ) {
				return null;
			}
			// Version 1 did not record a mode. Omitting it tells the client to
			// retain the current mode when this historical revision is loaded.
			return self::normalize_legacy( $raw );
		}

		$editor_mode       = '1' === (string) get_metadata( 'post', $revision_id, '_kayzart_tailwind', true ) ? 'tailwind' : 'normal';
		$raw['editorMode'] = $editor_mode;
		$raw['cssByMode']  = array(
			'normal'   => metadata_exists( 'post', $revision_id, '_kayzart_normal_css' )
				? (string) get_metadata( 'post', $revision_id, '_kayzart_normal_css', true )
				: ( 'normal' === $editor_mode ? $raw['css'] : null ),
			'tailwind' => metadata_exists( 'post', $revision_id, '_kayzart_tailwind_css' )
				? (string) get_metadata( 'post', $revision_id, '_kayzart_tailwind_css', true )
				: ( 'tailwind' === $editor_mode ? $raw['css'] : null ),
		);
		$snapshot          = self::normalize( $raw );
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, self::hash( $snapshot ) ) ) {
			return null;
		}
		return $snapshot;
	}

	/**
	 * Normalize a snapshot to its canonical public shape.
	 *
	 * @param array<string,mixed> $snapshot Raw snapshot.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $snapshot ): array {
		$editor_mode  = isset( $snapshot['editorMode'] ) && 'tailwind' === $snapshot['editorMode'] ? 'tailwind' : 'normal';
		$css          = isset( $snapshot['css'] ) ? (string) $snapshot['css'] : '';
		$css_by_mode  = isset( $snapshot['cssByMode'] ) && is_array( $snapshot['cssByMode'] )
			? $snapshot['cssByMode']
			: array();
		$normal_css   = array_key_exists( 'normal', $css_by_mode ) && null !== $css_by_mode['normal']
			? (string) $css_by_mode['normal']
			: ( 'normal' === $editor_mode ? $css : null );
		$tailwind_css = array_key_exists( 'tailwind', $css_by_mode ) && null !== $css_by_mode['tailwind']
			? (string) $css_by_mode['tailwind']
			: ( 'tailwind' === $editor_mode ? $css : null );

		return array(
			'html'       => isset( $snapshot['html'] ) ? (string) $snapshot['html'] : '',
			'customHead' => isset( $snapshot['customHead'] ) ? (string) $snapshot['customHead'] : '',
			'css'        => $css,
			'js'         => isset( $snapshot['js'] ) ? (string) $snapshot['js'] : '',
			'jsMode'     => Rest_Save::normalize_js_mode( isset( $snapshot['jsMode'] ) ? $snapshot['jsMode'] : '' ),
			'editorMode' => $editor_mode,
			'cssByMode'  => array(
				'normal'   => $normal_css,
				'tailwind' => $tailwind_css,
			),
		);
	}

	/**
	 * Normalize the version 1 snapshot shape without adding mode fields.
	 *
	 * @param array<string,mixed> $snapshot Raw snapshot.
	 * @return array<string,string>
	 */
	private static function normalize_legacy( array $snapshot ): array {
		return array(
			'html'       => isset( $snapshot['html'] ) ? (string) $snapshot['html'] : '',
			'customHead' => isset( $snapshot['customHead'] ) ? (string) $snapshot['customHead'] : '',
			'css'        => isset( $snapshot['css'] ) ? (string) $snapshot['css'] : '',
			'js'         => isset( $snapshot['js'] ) ? (string) $snapshot['js'] : '',
			'jsMode'     => Rest_Save::normalize_js_mode( isset( $snapshot['jsMode'] ) ? $snapshot['jsMode'] : '' ),
		);
	}

	/**
	 * Compute a version 1 snapshot hash.
	 *
	 * @param array<string,mixed> $snapshot Raw snapshot.
	 */
	private static function legacy_hash( array $snapshot ): string {
		$encoded = wp_json_encode( self::normalize_legacy( $snapshot ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return hash( 'sha256', false === $encoded ? '' : $encoded );
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
