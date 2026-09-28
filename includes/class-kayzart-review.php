<?php
/**
 * WordPress.org review request for Kayzart.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks administrators for a WordPress.org review once Kayzart has proven useful.
 *
 * The notice appears after the site has saved Kayzart pages a few times and its
 * first Kayzart page is at least a week old. Every choice in it is final except
 * one postponement, and nothing leaves the site: the review itself is written
 * on WordPress.org.
 */
class Review {
	public const REVIEW_URL         = 'https://wordpress.org/support/plugin/kayzart-live-code-editor/reviews/#new-post';
	public const SUPPORT_URL        = 'https://wordpress.org/support/plugin/kayzart-live-code-editor/';
	public const SAVE_COUNT_OPTION  = 'kayzart_review_save_count';
	public const SINCE_OPTION       = 'kayzart_review_since';
	public const STATE_META_KEY     = 'kayzart_review_state';
	public const SAVE_THRESHOLD     = 3;
	public const MIN_AGE            = 7 * DAY_IN_SECONDS;
	public const REMIND_AFTER       = 14 * DAY_IN_SECONDS;
	private const ACTION            = 'kayzart_review';
	private const NONCE             = 'kayzart_review_v1';
	private const SETTLED_STATUSES  = array( 'reviewed', 'dismissed' );
	private const PLUGIN_MAIN_FILE  = 'kayzart-live-code-editor.php';
	private const FOOTER_SCREEN_IDS = array( Admin::NEW_SLUG, Admin::SETTINGS_SLUG, Admin::CONVERT_SLUG );
	private const LINK_TAGS         = array(
		'a' => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
		),
	);

	/** Register review hooks. */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'add_plugin_row_meta' ), 10, 2 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'filter_admin_footer_text' ) );
	}

	/**
	 * Count a successful save toward the review threshold.
	 *
	 * The first counted save also fixes the start of the waiting period at the
	 * oldest Kayzart page, so sites that used Kayzart before this counter existed
	 * are not made to wait another week. Writes stop once the threshold is met.
	 */
	public static function record_save(): void {
		$count = (int) get_option( self::SAVE_COUNT_OPTION, 0 );
		if ( $count >= self::SAVE_THRESHOLD ) {
			return;
		}

		if ( 0 === (int) get_option( self::SINCE_OPTION, 0 ) ) {
			update_option( self::SINCE_OPTION, self::oldest_kayzart_content_time(), false );
		}
		update_option( self::SAVE_COUNT_OPTION, $count + 1, false );
	}

	/**
	 * Determine whether the current administrator should see the review request.
	 *
	 * Only options and user meta are read here: the one content query happens
	 * when the first save is counted, never on an admin page load.
	 */
	public static function should_show_notice(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$state  = self::get_state();
		$status = isset( $state['status'] ) ? (string) $state['status'] : '';
		if ( in_array( $status, self::SETTLED_STATUSES, true ) ) {
			return false;
		}
		if ( 'postponed' === $status ) {
			$remind_after = isset( $state['remind_after'] ) ? absint( $state['remind_after'] ) : 0;
			if ( 0 === $remind_after || time() < $remind_after ) {
				return false;
			}
		}

		if ( (int) get_option( self::SAVE_COUNT_OPTION, 0 ) < self::SAVE_THRESHOLD ) {
			return false;
		}
		$since = (int) get_option( self::SINCE_OPTION, 0 );
		return 0 < $since && time() - $since >= self::MIN_AGE;
	}

	/** Render the review request on the screens where Kayzart pages are managed. */
	public static function render_notice(): void {
		if ( ! self::is_notice_screen() || ! self::should_show_notice() ) {
			return;
		}

		$state       = self::get_state();
		$is_reminder = 'postponed' === ( $state['status'] ?? '' );

		echo '<div class="notice notice-info kayzart-reviewNotice">';
		echo '<p><strong>' . esc_html__( 'Is Kayzart helping you build pages?', 'kayzart-live-code-editor' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'If it has been useful, a short review on WordPress.org helps other site owners find Kayzart and tells us what to keep improving.', 'kayzart-live-code-editor' ) . '</p>';
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: %s: WordPress.org support forum URL. */
				__( 'Something not working? Let us know in the <a href="%s" target="_blank" rel="noopener noreferrer">support forum</a>.', 'kayzart-live-code-editor' ),
				esc_url( self::SUPPORT_URL )
			),
			self::LINK_TAGS
		) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><p>';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		wp_nonce_field( self::NONCE );
		echo '<button class="button button-primary" type="submit" name="decision" value="review" formtarget="_blank">' . esc_html__( 'Leave a review', 'kayzart-live-code-editor' ) . '</button> ';
		if ( ! $is_reminder ) {
			echo '<button class="button" type="submit" name="decision" value="postpone">' . esc_html__( 'Maybe later', 'kayzart-live-code-editor' ) . '</button> ';
		}
		echo '<button class="button-link" type="submit" name="decision" value="dismiss">' . esc_html__( 'Do not show again', 'kayzart-live-code-editor' ) . '</button>';
		echo '</p></form></div>';
	}

	/**
	 * Record the administrator's choice, then send them on.
	 *
	 * A settled choice never changes: a stale notice left open in another tab
	 * must not bring back a request that was already answered.
	 */
	public static function handle_action(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kayzart-live-code-editor' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) : '';
		$state    = self::get_state();
		$status   = isset( $state['status'] ) ? (string) $state['status'] : '';

		if ( ! in_array( $status, self::SETTLED_STATUSES, true ) ) {
			if ( 'review' === $decision ) {
				self::set_state( array( 'status' => 'reviewed' ) );
			} elseif ( 'postpone' === $decision ) {
				if ( 'postponed' !== $status ) {
					self::set_state(
						array(
							'status'       => 'postponed',
							'remind_after' => time() + self::REMIND_AFTER,
						)
					);
				}
			} else {
				self::set_state( array( 'status' => 'dismissed' ) );
			}
		}

		if ( 'review' === $decision ) {
			add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_wordpress_org_redirect' ) );
			wp_safe_redirect( self::REVIEW_URL );
			exit;
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}

	/**
	 * Let the review button leave the site for WordPress.org.
	 *
	 * @param string[] $hosts Allowed redirect hosts.
	 * @return string[]
	 */
	public static function allow_wordpress_org_redirect( array $hosts ): array {
		$hosts[] = 'wordpress.org';
		return $hosts;
	}

	/**
	 * Add support and review links under Kayzart on the Plugins screen.
	 *
	 * @param string[] $links Plugin row meta links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public static function add_plugin_row_meta( array $links, string $file ): array {
		if ( plugin_basename( KAYZART_PATH . self::PLUGIN_MAIN_FILE ) !== $file ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::SUPPORT_URL ),
			esc_html__( 'Support', 'kayzart-live-code-editor' )
		);
		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::REVIEW_URL ),
			esc_html__( 'Leave a review', 'kayzart-live-code-editor' )
		);
		return $links;
	}

	/**
	 * Replace the admin footer credit on Kayzart's own screens with a review link.
	 *
	 * @param string|mixed $text Footer text.
	 * @return string|mixed
	 */
	public static function filter_admin_footer_text( $text ) {
		if ( ! self::is_kayzart_screen() ) {
			return $text;
		}

		return wp_kses(
			sprintf(
				/* translators: %s: WordPress.org review form URL. */
				__( 'If Kayzart is useful to you, please consider <a href="%s" target="_blank" rel="noopener noreferrer">leaving a review on WordPress.org</a>.', 'kayzart-live-code-editor' ),
				esc_url( self::REVIEW_URL )
			),
			self::LINK_TAGS
		);
	}

	/**
	 * Whether the current screen is one where Kayzart pages are listed or configured.
	 *
	 * The notice stays off every other screen, including the full-screen editor
	 * and the Add new screen that already carries the feedback survey invite.
	 */
	private static function is_notice_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		if ( 'dashboard' === $screen->id ) {
			return true;
		}
		if ( 'edit' === $screen->base ) {
			return Post_Type::POST_TYPE === $screen->post_type || Post_Type::is_post_type_enabled( (string) $screen->post_type );
		}
		return self::screen_is_page( $screen, Admin::SETTINGS_SLUG );
	}

	/** Whether the current screen is one of Kayzart's own admin pages. */
	private static function is_kayzart_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}
		foreach ( self::FOOTER_SCREEN_IDS as $slug ) {
			if ( self::screen_is_page( $screen, $slug ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match an admin page by slug regardless of its parent menu.
	 *
	 * Screen IDs embed the translated parent menu title, so only the slug suffix
	 * is stable across locales.
	 *
	 * @param \WP_Screen $screen Current screen.
	 * @param string     $slug   Admin page slug.
	 */
	private static function screen_is_page( \WP_Screen $screen, string $slug ): bool {
		$suffix = '_page_' . $slug;
		return substr( $screen->id, -strlen( $suffix ) ) === $suffix;
	}

	/** Find when the oldest current or legacy Kayzart page was created. */
	private static function oldest_kayzart_content_time(): int {
		$queries = array(
			array( 'post_type' => Post_Type::POST_TYPE ),
			array(
				'post_type'  => array_keys( Post_Type::get_selectable_post_types() ),
				// Runs once per site, on the first counted save.
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'   => Post_Type::ENABLED_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value' => '1',
			),
		);

		$oldest = time();
		foreach ( $queries as $query ) {
			$post_ids = get_posts(
				array_merge(
					array(
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'orderby'        => 'date',
						'order'          => 'ASC',
						'fields'         => 'ids',
						'no_found_rows'  => true,
					),
					$query
				)
			);
			if ( empty( $post_ids ) ) {
				continue;
			}
			$created = get_post_timestamp( (int) $post_ids[0] );
			if ( false !== $created && $created < $oldest ) {
				$oldest = $created;
			}
		}
		return $oldest;
	}

	/**
	 * Read the current user's review state.
	 *
	 * @return array<string,mixed>
	 */
	private static function get_state(): array {
		$state = get_user_meta( get_current_user_id(), self::STATE_META_KEY, true );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Store the current user's review state.
	 *
	 * @param array<string,mixed> $state Review state.
	 */
	private static function set_state( array $state ): void {
		$state['updated_at'] = time();
		update_user_meta( get_current_user_id(), self::STATE_META_KEY, $state );
	}
}
