<?php
/**
 * Optional feedback survey tests for Kayzart.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Feedback;
use KayzArt\Post_Type;

/** Verify survey visibility, validation, and explicit external delivery. */
class Test_Feedback extends WP_UnitTestCase {
	/**
	 * Administrator user ID used by the current test.
	 *
	 * @var int
	 */
	private $admin_id;

	/** Create an administrator for every survey test. */
	protected function setUp(): void {
		parent::setUp();
		if ( ! post_type_exists( Post_Type::POST_TYPE ) ) {
			Post_Type::register();
		}
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/** Remove user state and HTTP interception after each test. */
	protected function tearDown(): void {
		delete_user_meta( $this->admin_id, Feedback::STATE_META_KEY );
		delete_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY );
		remove_all_filters( 'pre_http_request' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** The core feedback tab stays directly after Basic and ahead of add-on tabs. */
	public function test_add_settings_tab_preserves_extension_order(): void {
		$tabs = Feedback::add_settings_tab(
			array(
				'basic'   => 'Basic',
				'license' => 'License',
			)
		);

		$this->assertSame( array( 'basic', 'feedback', 'license' ), array_keys( $tabs ) );
	}

	/** A managed page makes an unanswered administrator eligible for the invite. */
	public function test_invite_requires_kayzart_content_and_respects_state(): void {
		$this->assertFalse( Feedback::should_show_invite() );

		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		Post_Type::enable_for_post( $post_id );
		$this->assertTrue( Feedback::should_show_invite() );

		update_user_meta(
			$this->admin_id,
			Feedback::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => time() + DAY_IN_SECONDS,
			)
		);
		$this->assertFalse( Feedback::should_show_invite() );

		update_user_meta(
			$this->admin_id,
			Feedback::STATE_META_KEY,
			array(
				'status'       => 'postponed',
				'remind_after' => time() - 1,
			)
		);
		$this->assertTrue( Feedback::should_show_invite() );

		update_user_meta( $this->admin_id, Feedback::STATE_META_KEY, array( 'status' => 'dismissed' ) );
		$this->assertFalse( Feedback::should_show_invite() );
		update_user_meta( $this->admin_id, Feedback::STATE_META_KEY, array( 'status' => 'submitted' ) );
		$this->assertFalse( Feedback::should_show_invite() );
	}

	/** Editors cannot see the invitation even when a managed page exists. */
	public function test_invite_is_limited_to_administrators(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Post_Type::enable_for_post( $post_id );
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$this->assertFalse( Feedback::should_show_invite() );
	}

	/** Rendering either survey entry point never contacts the external service. */
	public function test_rendering_does_not_contact_external_service(): void {
		$calls = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$calls ) {
				++$calls;
				return new WP_Error( 'unexpected_request' );
			}
		);

		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertSame( 0, $calls );
		$this->assertStringContainsString( 'Send feedback to Kayzart', $output );
		$this->assertStringContainsString( 'privacy-policy', $output );
	}

	/** A complete form maps to the documented payload without site identifiers. */
	public function test_prepare_submission_builds_documented_payload(): void {
		$input   = $this->valid_input();
		$payload = Feedback::prepare_submission( $input );

		$this->assertIsArray( $payload );
		$this->assertSame( Feedback::SURVEY_VERSION, $payload['survey_version'] );
		$this->assertSame( 'freelancer', $payload['role'] );
		$this->assertSame( array( 'client' ), $payload['usage_targets'] );
		$this->assertSame( array( 'landing_page', 'ai_html_wordpress' ), $payload['use_cases'] );
		$this->assertSame( 'responsive', $payload['primary_problem'] );
		$this->assertSame( array( 'ai_chat_editing', 'reusable_components' ), $payload['pro_priorities'] );
		$this->assertSame( 'multi_site_annual', $payload['pricing_preference'] );
		$this->assertArrayNotHasKey( 'site_url', $payload );
		$this->assertArrayNotHasKey( 'email', $payload );
		$this->assertArrayNotHasKey( 'user_id', $payload );
	}

	/** Invalid, excessive, and contradictory choices are rejected. */
	public function test_prepare_submission_rejects_invalid_choices(): void {
		$input         = $this->valid_input();
		$input['role'] = 'intruder';
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input                   = $this->valid_input();
		$input['pro_priorities'] = array( 'ai_chat_editing', 'forms', 'multi_site', 'version_history' );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input                  = $this->valid_input();
		$input['usage_targets'] = array( 'not_used_yet', 'client' );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input            = $this->valid_input();
		$input['comment'] = str_repeat( 'a', 1001 );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input         = $this->valid_input();
		$input['role'] = array( 'freelancer' );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input            = $this->valid_input();
		$input['comment'] = array( 'not a scalar comment' );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input                    = $this->valid_input();
		$input['usage_targets'][] = array( 'client' );
		$this->assertWPError( Feedback::prepare_submission( $input ) );
	}

	/** Pricing may be omitted while required questions remain enforced. */
	public function test_prepare_submission_allows_optional_fields_only(): void {
		$input = $this->valid_input();
		unset( $input['pricing_preference'], $input['comment'] );
		$payload = Feedback::prepare_submission( $input );

		$this->assertIsArray( $payload );
		$this->assertNull( $payload['pricing_preference'] );
		$this->assertSame( '', $payload['comment'] );

		unset( $input['role'] );
		$this->assertWPError( Feedback::prepare_submission( $input ) );
	}

	/** Explicit delivery uses the fixed endpoint and hardened HTTP arguments. */
	public function test_send_submission_maps_request_and_accepts_matching_response(): void {
		$payload  = Feedback::prepare_submission( $this->valid_input() );
		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured, $payload ) {
				unset( $preempt );
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'ok'           => true,
							'submissionId' => $payload['submission_id'],
						)
					),
					'response' => array(
						'code'    => 201,
						'message' => 'Created',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$this->assertTrue( Feedback::send_submission( $payload ) );
		$this->assertSame( Feedback::ENDPOINT, $captured['url'] );
		$this->assertSame( 8, $captured['args']['timeout'] );
		$this->assertSame( 0, $captured['args']['redirection'] );
		$this->assertSame( 4096, $captured['args']['limit_response_size'] );
		$this->assertSame( $payload, json_decode( $captured['args']['body'], true ) );
	}

	/** Delivery fails closed for errors, invalid JSON, and mismatched receipts. */
	public function test_send_submission_rejects_invalid_responses(): void {
		$payload = Feedback::prepare_submission( $this->valid_input() );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'offline' );
			}
		);
		$this->assertWPError( Feedback::send_submission( $payload ) );
		remove_all_filters( 'pre_http_request' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '{"ok":true,"submissionId":"wrong"}',
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
		$this->assertWPError( Feedback::send_submission( $payload ) );
	}

	/** Survey invitation state and failed drafts are removed on uninstall. */
	public function test_uninstall_removes_feedback_user_meta(): void {
		update_user_meta( $this->admin_id, Feedback::STATE_META_KEY, array( 'status' => 'dismissed' ) );
		update_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, array( 'comment' => 'draft' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require KAYZART_PATH . 'uninstall.php';

		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::STATE_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, true ) );
	}

	/**
	 * Build one valid raw survey submission.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_input(): array {
		return array(
			'submission_id'      => wp_generate_uuid4(),
			'role'               => 'freelancer',
			'usage_targets'      => array( 'client' ),
			'use_cases'          => array( 'landing_page', 'ai_html_wordpress' ),
			'primary_problem'    => 'responsive',
			'pro_priorities'     => array( 'ai_chat_editing', 'reusable_components' ),
			'pricing_preference' => 'multi_site_annual',
			'comment'            => 'Please add reusable sections.',
		);
	}
}
