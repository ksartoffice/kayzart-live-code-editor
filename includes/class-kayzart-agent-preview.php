<?php
/**
 * Short-lived preview links for external AI agents.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and serves signed, expiring links that show one page without login.
 *
 * An agent connected over MCP authenticates with an application password,
 * but its browser (Playwright, Chrome DevTools and the like) has no WordPress
 * session, so the regular draft preview is closed to it. Kayzart cannot take
 * screenshots itself -- most hosts have no headless browser -- so it hands the
 * agent a link its own browser can open instead.
 *
 * A link shows the saved state of one page, read-only, and stops working when
 * it expires or a new link is issued for that page. Only a hash of the token
 * is stored.
 */
class Agent_Preview {

	const QUERY_VAR   = 'kayzart_share';
	const META_KEY    = '_kayzart_agent_preview';
	const DEFAULT_TTL = 1800;

	/** Statuses a link can show, including agents' working copies. Trash and auto-drafts stay hidden. */
	const STATUSES = array( 'draft', 'pending', 'private', 'future', 'publish', Agent_Working_Copy::STATUS );

	/**
	 * Post ID served by the current request, or 0.
	 *
	 * @var int
	 */
	private static $post_id = 0;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'maybe_force_query' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_request' ), 0 );
	}

	/**
	 * Register the token query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function register_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Link lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl(): int {
		/**
		 * Filters how long an agent preview link stays valid, in seconds.
		 *
		 * @param int $ttl Lifetime in seconds. Default 1800 (30 minutes).
		 */
		$ttl = (int) apply_filters( 'kayzart_agent_preview_ttl', self::DEFAULT_TTL );
		return max( 60, min( DAY_IN_SECONDS, $ttl ) );
	}

	/**
	 * Issue a new link for a page, replacing any earlier one.
	 *
	 * @param int $post_id Post ID.
	 * @return array{url:string,expiresAt:string,expiresIn:int}
	 */
	public static function issue( int $post_id ): array {
		$token   = wp_generate_password( 40, false, false );
		$ttl     = self::ttl();
		$expires = time() + $ttl;
		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'    => self::hash( $token ),
				'expires' => $expires,
			)
		);

		return array(
			'url'       => self::build_url( $post_id, $token ),
			'expiresAt' => gmdate( 'c', $expires ),
			'expiresIn' => $ttl,
		);
	}

	/**
	 * Whether a token is the current, unexpired token for a page.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token from the URL.
	 * @return bool
	 */
	public static function is_valid( int $post_id, string $token ): bool {
		if ( $post_id <= 0 || '' === $token ) {
			return false;
		}
		$stored = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || empty( $stored['expires'] ) ) {
			return false;
		}
		if ( (int) $stored['expires'] < time() ) {
			return false;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_status, self::STATUSES, true ) || ! Post_Type::is_kayzart_post( $post ) ) {
			return false;
		}
		return hash_equals( (string) $stored['hash'], self::hash( $token ) );
	}

	/**
	 * Point the main query at the linked page when the token is valid.
	 *
	 * Requesting the statuses explicitly is what lets WP_Query return a draft
	 * to a visitor without a session.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function maybe_force_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}
		self::$post_id = 0;
		$token         = (string) $query->get( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}
		$post_id = absint( $query->get( 'page_id' ) );
		if ( ! $post_id ) {
			$post_id = absint( $query->get( 'p' ) );
		}
		if ( ! self::is_valid( $post_id, $token ) ) {
			return;
		}
		$post = get_post( $post_id );

		$query->set( 'post_type', $post->post_type );
		$query->set( 'post_status', self::STATUSES );
		$query->set( 'name', '' );
		$query->set( 'pagename', '' );
		$query->set( 'error', '' );
		if ( Post_Type::PAGE_TYPE === $post->post_type ) {
			$query->set( 'page_id', $post_id );
			$query->set( 'p', '' );
		} else {
			$query->set( 'p', $post_id );
			$query->set( 'page_id', '' );
		}
		Preview::mark_query_as_singular_preview( $query, $post );
		self::$post_id = $post_id;
	}

	/**
	 * Refuse stale links and send no-index, no-cache headers for valid ones.
	 */
	public static function maybe_handle_request(): void {
		$token = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $token ) {
			return;
		}
		if ( ! self::$post_id ) {
			wp_die(
				esc_html__( 'This preview link has expired or was replaced. Ask for a new link.', 'kayzart-live-code-editor' ),
				esc_html__( 'Preview link expired', 'kayzart-live-code-editor' ),
				array( 'response' => 410 )
			);
		}

		add_filter( 'redirect_canonical', '__return_false' );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		add_filter( 'show_admin_bar', '__return_false' );
		nocache_headers();
		// wp_headers has already been sent by template_redirect.
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/**
	 * Build the public URL carrying a token.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $token   Token.
	 * @return string
	 */
	private static function build_url( int $post_id, string $token ): string {
		$post                    = get_post( $post_id );
		$args                    = Post_Type::PAGE_TYPE === $post->post_type
			? array( 'page_id' => $post_id )
			: array(
				'p'         => $post_id,
				'post_type' => $post->post_type,
			);
		$args[ self::QUERY_VAR ] = $token;
		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Hash a token for storage.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
