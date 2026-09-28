<?php
/**
 * WordPress.org review request tests for Kayzart.
 *
 * @package KayzArt
 */

use KayzArt\Post_Type;
use KayzArt\Review;

/** Verify when the review request appears and how each choice is kept. */
class Test_Review extends WP_UnitTestCase {
	/**
	 * Administrator user ID used by the current test.
	 *
	 * @var int
	 */
	private $admin_id;

	/** Start every test with an administrator and no review history. */
	protected function setUp(): void {
		parent::setUp();
		rest_get_server();
		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		delete_option( Review::SAVE_COUNT_OPTION );
		delete_option( Review::SINCE_OPTION );
	}

	/** Remove review state and request globals after each test. */
	protected function tearDown(): void {
		delete_user_meta( $this->admin_id, Review::STATE_META_KEY );
		delete_option( Review::SAVE_COUNT_OPTION );
		delete_option( Review::SINCE_OPTION );
		remove_all_filters( 'wp_redirect' );
		remove_filter( 'allowed_redirect_hosts', array( Review::class, 'allow_wordpress_org_redirect' ) );
		unset( $_POST['decision'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** The first counted save starts the wait at the oldest Kayzart page, and counting stops at the threshold. */
	public function test_record_save_dates_from_oldest_page_and_stops_at_threshold(): void {
		$old_id = $this->create_kayzart_page( '2025-01-15 09:00:00' );
		$this->create_kayzart_page( '2026-06-01 09:00:00' );

		Review::record_save();

		$this->assertSame( 1, (int) get_option( Review::SAVE_COUNT_OPTION ) );
		$this->assertSame( get_post_timestamp( $old_id ), (int) get_option( Review::SINCE_OPTION ) );

		for ( $i = 0; $i < Review::SAVE_THRESHOLD + 2; $i++ ) {
			Review::record_save();
		}
		$this->assertSame( Review::SAVE_THRESHOLD, (int) get_option( Review::SAVE_COUNT_OPTION ) );
	}

	/** A legacy Kayzart post counts as the oldest page too. */
	public function test_record_save_includes_legacy_posts(): void {
		$legacy_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_date'   => '2024-03-01 09:00:00',
			)
		);
		$this->create_kayzart_page( '2026-06-01 09:00:00' );

		Review::record_save();

		$this->assertSame( get_post_timestamp( $legacy_id ), (int) get_option( Review::SINCE_OPTION ) );
	}

	/** A successful save through the REST endpoint is what gets counted. */
	public function test_rest_save_counts_toward_threshold(): void {
		$post_id = $this->create_kayzart_page( '2026-06-01 09:00:00' );

		$request = new WP_REST_Request( 'POST', '/kayzart/v1/save' );
		$request->set_body_params(
			array(
				'post_id'         => $post_id,
				'html'            => '<p>Hello</p>',
				'css'             => '',
				'tailwindEnabled' => false,
			)
		);
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, (int) get_option( Review::SAVE_COUNT_OPTION ) );
	}

	/** Both the save count and the week of use are required. */
	public function test_notice_requires_saves_and_one_week(): void {
		$this->assertFalse( Review::should_show_notice() );

		update_option( Review::SAVE_COUNT_OPTION, Review::SAVE_THRESHOLD );
		update_option( Review::SINCE_OPTION, time() - Review::MIN_AGE + HOUR_IN_SECONDS );
		$this->assertFalse( Review::should_show_notice(), 'A page younger than a week must not trigger the request.' );

		update_option( Review::SINCE_OPTION, time() - Review::MIN_AGE );
		$this->assertTrue( Review::should_show_notice() );

		update_option( Review::SAVE_COUNT_OPTION, Review::SAVE_THRESHOLD - 1 );
		$this->assertFalse( Review::should_show_notice(), 'Fewer saves than the threshold must not trigger the request.' );
	}

	/** Only administrators are asked. */
	public function test_notice_is_for_administrators_only(): void {
		$this->make_eligible();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertFalse( Review::should_show_notice() );
	}

	/** A review or a dismissal ends the request; a postponement pauses it. */
	public function test_notice_respects_state(): void {
		$this->make_eligible();

		foreach ( array( 'reviewed', 'dismissed' ) as $status ) {
			update_user_meta( $this->admin_id, Review::STATE_META_KEY, array( 'status' => $status ) );
			$this->assertFalse( Review::should_show_notice(), $status );
		}

		update_user_meta(
			$this->admin_id,
			Review::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => time() + DAY_IN_SECONDS,
			)
		);
		$this->assertFalse( Review::should_show_notice() );

		update_user_meta(
			$this->admin_id,
			Review::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => time() - 1,
			)
		);
		$this->assertTrue( Review::should_show_notice() );
	}

	/** The notice appears on the dashboard and Kayzart list screens, not everywhere. */
	public function test_notice_renders_only_on_allowed_screens(): void {
		$this->make_eligible();

		set_current_screen( 'dashboard' );
		$html = $this->render_notice();
		$this->assertStringContainsString( 'kayzart-reviewNotice', $html );
		$this->assertStringContainsString( 'value="postpone"', $html );
		$this->assertStringContainsString( esc_url( Review::SUPPORT_URL ), $html );

		set_current_screen( 'edit-page' );
		$this->assertStringContainsString( 'kayzart-reviewNotice', $this->render_notice() );

		set_current_screen( 'plugins' );
		$this->assertSame( '', $this->render_notice() );
	}

	/** A returning reminder offers no second postponement. */
	public function test_reminder_hides_postpone_button(): void {
		$this->make_eligible();
		update_user_meta(
			$this->admin_id,
			Review::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => time() - 1,
			)
		);

		set_current_screen( 'dashboard' );
		$html = $this->render_notice();

		$this->assertStringContainsString( 'value="review"', $html );
		$this->assertStringNotContainsString( 'value="postpone"', $html );
		$this->assertStringContainsString( 'value="dismiss"', $html );
	}

	/** Choosing to review records the choice and goes to the WordPress.org form. */
	public function test_review_action_redirects_to_wordpress_org(): void {
		$location = $this->run_action( 'review' );

		$this->assertSame( Review::REVIEW_URL, $location );
		$this->assertSame( 'reviewed', $this->state()['status'] );
	}

	/** Postponing twice keeps the first reminder date. */
	public function test_postpone_is_idempotent(): void {
		$this->run_action( 'postpone' );
		$first = $this->state()['remind_after'];
		$this->assertGreaterThan( time(), $first );

		update_user_meta(
			$this->admin_id,
			Review::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => $first - 10,
			)
		);
		$this->run_action( 'postpone' );

		$this->assertSame( $first - 10, $this->state()['remind_after'] );
	}

	/** A settled choice is never overwritten by a stale notice. */
	public function test_settled_state_is_kept(): void {
		$this->run_action( 'dismiss' );
		$this->assertSame( 'dismissed', $this->state()['status'] );

		$this->run_action( 'postpone' );
		$this->run_action( 'review' );

		$this->assertSame( 'dismissed', $this->state()['status'] );
	}

	/** Support and review links are added to Kayzart's row on the Plugins screen only. */
	public function test_plugin_row_meta_targets_kayzart_only(): void {
		$file  = plugin_basename( KAYZART_PATH . 'kayzart-live-code-editor.php' );
		$links = Review::add_plugin_row_meta( array( 'Version 1' ), $file );

		$this->assertCount( 3, $links );
		$this->assertStringContainsString( esc_url( Review::SUPPORT_URL ), $links[1] );
		$this->assertStringContainsString( esc_url( Review::REVIEW_URL ), $links[2] );

		$this->assertSame( array( 'Version 1' ), Review::add_plugin_row_meta( array( 'Version 1' ), 'other/other.php' ) );
	}

	/** The footer credit changes on Kayzart screens and nowhere else. */
	public function test_footer_text_only_on_kayzart_screens(): void {
		set_current_screen( 'toplevel_page_kayzart-new' );
		$this->assertStringContainsString( esc_url( Review::REVIEW_URL ), Review::filter_admin_footer_text( 'Thanks' ) );

		set_current_screen( 'kayzart_page_kayzart-settings' );
		$this->assertStringContainsString( esc_url( Review::REVIEW_URL ), Review::filter_admin_footer_text( 'Thanks' ) );

		set_current_screen( 'dashboard' );
		$this->assertSame( 'Thanks', Review::filter_admin_footer_text( 'Thanks' ) );
	}

	/**
	 * Create a Kayzart-managed page with a fixed creation date.
	 *
	 * @param string $date Local post date.
	 */
	private function create_kayzart_page( string $date ): int {
		$post_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_date'   => $date,
			)
		);
		Post_Type::enable_for_post( $post_id );
		return $post_id;
	}

	/** Satisfy both site-wide conditions. */
	private function make_eligible(): void {
		update_option( Review::SAVE_COUNT_OPTION, Review::SAVE_THRESHOLD );
		update_option( Review::SINCE_OPTION, time() - Review::MIN_AGE - DAY_IN_SECONDS );
	}

	/** Capture the notice markup. */
	private function render_notice(): string {
		ob_start();
		Review::render_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Submit a notice decision and return where it redirected.
	 *
	 * @param string $decision Decision value.
	 */
	private function run_action( string $decision ): string {
		$_POST['decision']    = $decision;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'kayzart_review_v1' );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location );
			}
		);

		try {
			Review::handle_action();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		} finally {
			remove_all_filters( 'wp_redirect' );
		}
		$this->fail( 'The action must redirect.' );
	}

	/**
	 * Read the administrator's stored review state.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		$state = get_user_meta( $this->admin_id, Review::STATE_META_KEY, true );
		return is_array( $state ) ? $state : array();
	}
}
