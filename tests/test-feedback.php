<?php
/**
 * Optional feedback survey tests for Kayzart.
 *
 * @package KayzArt
 */

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
		delete_option( Feedback::CLOSED_OPTION );
		delete_transient( Feedback::CONTENT_TRANSIENT );
	}

	/** Remove user state and HTTP interception after each test. */
	protected function tearDown(): void {
		delete_user_meta( $this->admin_id, Feedback::STATE_META_KEY );
		delete_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY );
		delete_user_meta( $this->admin_id, Feedback::RESPONSE_META_KEY );
		delete_user_meta( $this->admin_id, Feedback::PENDING_META_KEY );
		delete_option( Feedback::CLOSED_OPTION );
		delete_transient( Feedback::CONTENT_TRANSIENT );
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

	/** The campaign closes on its own, and a closed survey exposes no UI at all. */
	public function test_closed_survey_hides_tab_and_invite(): void {
		$this->assertTrue( Feedback::is_open() );
		$this->assertGreaterThan( time(), strtotime( Feedback::SURVEY_END ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Post_Type::enable_for_post( $post_id );
		$this->assertTrue( Feedback::should_show_invite() );

		update_option( Feedback::CLOSED_OPTION, '1' );

		$this->assertFalse( Feedback::is_open() );
		$this->assertFalse( Feedback::should_show_invite() );
		$this->assertSame( array( 'basic' ), array_keys( Feedback::add_settings_tab( array( 'basic' => 'Basic' ) ) ) );

		ob_start();
		Feedback::render_settings_tab();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/** A 410 response marks the survey closed instead of looking like an outage. */
	public function test_gone_response_reports_a_closed_survey(): void {
		$payload = Feedback::prepare_submission( $this->valid_input() );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array( 'code' => 410 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$result = Feedback::send_submission( $payload );
		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_feedback_closed', $result->get_error_code() );
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

	/** Enabling Kayzart on a post invalidates the cached managed-content answer. */
	public function test_managed_content_cache_is_invalidated_when_a_page_is_enabled(): void {
		$this->assertFalse( Feedback::has_kayzart_content() );
		$this->assertSame( '0', get_transient( Feedback::CONTENT_TRANSIENT ) );

		$post_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Post_Type::enable_for_post( $post_id );

		$this->assertFalse( get_transient( Feedback::CONTENT_TRANSIENT ) );
		$this->assertTrue( Feedback::has_kayzart_content() );
		$this->assertSame( '1', get_transient( Feedback::CONTENT_TRANSIENT ) );
	}

	/** A stale invite left open in another tab cannot revive a settled survey. */
	public function test_invite_action_preserves_terminal_states(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'kayzart_feedback_invite_v1' );
		$_POST                = array(
			'decision'  => 'postpone',
			'post_type' => 'page',
			'_wpnonce'  => $_REQUEST['_wpnonce'],
		);
		$redirect             = static function () {
			throw new Exception( 'redirected' );
		};
		add_filter( 'wp_redirect', $redirect );

		foreach ( array( 'submitted', 'dismissed' ) as $status ) {
			$state = array(
				'status'     => $status,
				'updated_at' => 12345,
			);
			update_user_meta( $this->admin_id, Feedback::STATE_META_KEY, $state );

			try {
				Feedback::handle_invite_action();
				$this->fail( 'The invite action should always redirect.' );
			} catch ( Exception $e ) {
				$this->assertSame( 'redirected', $e->getMessage() );
			}

			$this->assertSame( $state, get_user_meta( $this->admin_id, Feedback::STATE_META_KEY, true ) );
			$this->assertFalse( Feedback::should_show_invite() );
		}

		remove_filter( 'wp_redirect', $redirect );
		$_POST    = array();
		$_REQUEST = array();
	}

	/** Repeating a postpone request keeps the reminder instead of dismissing. */
	public function test_repeated_postpone_does_not_dismiss_the_invite(): void {
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'kayzart_feedback_invite_v1' );
		$_POST                = array(
			'decision'  => 'postpone',
			'post_type' => 'page',
			'_wpnonce'  => $_REQUEST['_wpnonce'],
		);
		$redirect             = static function () {
			throw new Exception( 'redirected' );
		};
		add_filter( 'wp_redirect', $redirect );

		foreach ( array( 1, 2, 3 ) as $unused ) {
			try {
				Feedback::handle_invite_action();
			} catch ( Exception $e ) {
				$this->assertSame( 'redirected', $e->getMessage() );
			}
		}

		$state = get_user_meta( $this->admin_id, Feedback::STATE_META_KEY, true );
		$this->assertSame( 'postponed', $state['status'] );
		$this->assertGreaterThan( time(), $state['remind_after'] );

		remove_filter( 'wp_redirect', $redirect );
		$_POST    = array();
		$_REQUEST = array();
	}

	/** Opening the survey twice before sending reuses one submission ID. */
	public function test_first_submission_id_is_reused_across_renders(): void {
		$ids = array();
		foreach ( array( 1, 2 ) as $unused ) {
			ob_start();
			Feedback::render_settings_tab();
			$output = (string) ob_get_clean();
			$this->assertSame( 1, preg_match( '/name="submission_id" value="([^"]+)"/', $output, $matches ) );
			$ids[] = $matches[1];
		}

		$this->assertSame( $ids[0], $ids[1] );
		$this->assertTrue( wp_is_uuid( $ids[0], 4 ) );
		$this->assertSame( $ids[0], get_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, true ) );
	}

	/** A render that loses the claim adopts the identifier already stored. */
	public function test_pending_submission_id_adopts_an_existing_claim(): void {
		$claimed = wp_generate_uuid4();
		add_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, $claimed, true );

		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="submission_id" value="' . $claimed . '"', $output );
		$this->assertCount( 1, get_user_meta( $this->admin_id, Feedback::PENDING_META_KEY ) );
	}

	/** An unusable stored identifier is replaced instead of being reused. */
	public function test_pending_submission_id_replaces_an_unusable_value(): void {
		add_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, 'not-a-uuid', true );

		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/name="submission_id" value="([^"]+)"/', $output, $matches ) );
		$this->assertTrue( wp_is_uuid( $matches[1], 4 ) );
		$this->assertSame( $matches[1], get_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, true ) );
	}

	/** The browser limit never stops input the counter still shows as valid. */
	public function test_native_maxlength_leaves_room_for_astral_characters(): void {
		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'maxlength="2000" data-kayzart-character-limit="1000"', $output );
		$this->assertStringContainsString( 'maxlength="200" data-kayzart-character-limit="100"', $output );

		$input            = $this->valid_input();
		$input['comment'] = str_repeat( '🎉', 1000 );
		$this->assertIsArray( Feedback::prepare_submission( $input ) );

		$input['comment'] = str_repeat( '🎉', 1001 );
		$this->assertWPError( Feedback::prepare_submission( $input ) );
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
		$this->assertStringNotContainsString( 'required', $output );
	}

	/** The unanswered reminder ships hidden and never marks a question mandatory. */
	public function test_unanswered_reminder_is_rendered_hidden(): void {
		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'kayzart-feedbackUnanswered', $output );
		$this->assertStringContainsString( 'hidden', $output );
		$this->assertStringContainsString( 'data-kayzart-send-anyway', $output );
		$this->assertStringNotContainsString( 'optional', strtolower( $output ) );
	}

	/** The shared "Other" description follows both Pro questions. */
	public function test_pro_other_description_belongs_to_both_pro_questions(): void {
		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'data-kayzart-other-for="pro_priorities,pro_decisive"', $output );
	}

	/** Character limits count characters, not bytes, for multi-byte answers. */
	public function test_text_limits_count_characters_not_bytes(): void {
		$input               = $this->valid_input();
		$input['role']       = 'other';
		$input['role_other'] = str_repeat( 'あ', 100 );
		$payload             = Feedback::prepare_submission( $input );

		$this->assertIsArray( $payload );
		$this->assertSame( $input['role_other'], $payload['role_other'] );

		$input['role_other'] = str_repeat( 'あ', 101 );
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input            = $this->valid_input();
		$input['comment'] = str_repeat( 'あ', 1000 );
		$this->assertIsArray( Feedback::prepare_submission( $input ) );
	}

	/** A sent answer is shown again so it can be corrected and resent. */
	public function test_sent_answers_are_restored_for_editing(): void {
		$payload = Feedback::prepare_submission( $this->valid_input() );
		update_user_meta( $this->admin_id, Feedback::RESPONSE_META_KEY, $payload );
		update_user_meta(
			$this->admin_id,
			Feedback::STATE_META_KEY,
			array(
				'status'        => 'submitted',
				'submission_id' => $payload['submission_id'],
				'updated_at'    => time(),
			)
		);

		ob_start();
		Feedback::render_settings_tab();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Update and resend my feedback', $output );
		$this->assertStringContainsString( 'value="' . $payload['submission_id'] . '"', $output );
		$this->assertStringContainsString( 'kayzart-feedback-role-freelancer" type="radio" name="role" value="freelancer" checked', $output );
	}

	/** A kept draft survives repeated renders so a reload never loses answers. */
	public function test_draft_survives_repeated_renders(): void {
		$draft = array(
			'submission_id' => wp_generate_uuid4(),
			'comment'       => 'Restored comment',
		);
		update_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, $draft );

		foreach ( array( 1, 2 ) as $unused ) {
			ob_start();
			Feedback::render_settings_tab();
			$output = (string) ob_get_clean();
			$this->assertStringContainsString( 'Restored comment', $output );
			$this->assertStringContainsString( 'value="' . $draft['submission_id'] . '"', $output );
		}

		$this->assertSame( $draft, get_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, true ) );
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
		$this->assertSame( 'three_five', $payload['monthly_volume'] );
		$this->assertSame( 'responsive', $payload['primary_problem'] );
		$this->assertSame( 'workable', $payload['api_key_attitude'] );
		$this->assertSame( array( 'included_ai', 'client_lock' ), $payload['pro_priorities'] );
		$this->assertSame( 'included_ai', $payload['pro_decisive'] );
		$this->assertSame( 'multi_site_annual', $payload['pricing_preference'] );
		$this->assertArrayNotHasKey( 'site_url', $payload );
		$this->assertArrayNotHasKey( 'email', $payload );
		$this->assertArrayNotHasKey( 'user_id', $payload );
	}

	/** Answering a single question is enough, and an empty form is refused. */
	public function test_every_question_is_optional_but_something_must_be_answered(): void {
		$payload = Feedback::prepare_submission(
			array(
				'submission_id' => wp_generate_uuid4(),
				'comment'       => 'Just a comment.',
			)
		);

		$this->assertIsArray( $payload );
		$this->assertSame( 'Just a comment.', $payload['comment'] );
		$this->assertNull( $payload['role'] );
		$this->assertSame( array(), $payload['usage_targets'] );

		$empty = Feedback::prepare_submission( array( 'submission_id' => wp_generate_uuid4() ) );
		$this->assertWPError( $empty );
		$this->assertSame( 'kayzart_feedback_empty', $empty->get_error_code() );
	}

	/** Choice counts are not capped and combinations are never contradictory. */
	public function test_prepare_submission_accepts_generous_choices(): void {
		$input                   = $this->valid_input();
		$input['pro_priorities'] = array( 'included_ai', 'client_lock', 'ab_testing', 'form_integration', 'niche_templates' );
		$input['usage_targets']  = array( 'not_used_yet', 'client' );
		$payload                 = Feedback::prepare_submission( $input );

		$this->assertIsArray( $payload );
		$this->assertCount( 5, $payload['pro_priorities'] );
		$this->assertSame( array( 'not_used_yet', 'client' ), $payload['usage_targets'] );
	}

	/** Free text travels only with the "Other" choice it belongs to. */
	public function test_other_text_requires_its_choice_and_respects_the_limit(): void {
		$input               = $this->valid_input();
		$input['role_other'] = 'Product manager';
		$payload             = Feedback::prepare_submission( $input );
		$this->assertNull( $payload['role_other'] );

		$input['role'] = 'other';
		$payload       = Feedback::prepare_submission( $input );
		$this->assertSame( 'Product manager', $payload['role_other'] );

		$input['role_other'] = str_repeat( 'a', 101 );
		$result              = Feedback::prepare_submission( $input );
		$this->assertWPError( $result );
		$this->assertSame( 'kayzart_feedback_invalid_role_other', $result->get_error_code() );
	}

	/** Values outside the allowlist and oversized text are still rejected. */
	public function test_prepare_submission_rejects_invalid_choices(): void {
		$input         = $this->valid_input();
		$input['role'] = 'intruder';
		$this->assertWPError( Feedback::prepare_submission( $input ) );

		$input                   = $this->valid_input();
		$input['pro_priorities'] = array( 'included_ai', 'version_history' );
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

		$input                  = $this->valid_input();
		$input['submission_id'] = 'not-a-uuid';
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

	/** Survey state, drafts, sent answers, and the closed flag go on uninstall. */
	public function test_uninstall_removes_feedback_data(): void {
		update_user_meta( $this->admin_id, Feedback::STATE_META_KEY, array( 'status' => 'dismissed' ) );
		update_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, array( 'comment' => 'draft' ) );
		update_user_meta( $this->admin_id, Feedback::RESPONSE_META_KEY, array( 'comment' => 'sent' ) );
		update_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, wp_generate_uuid4() );
		update_option( Feedback::CLOSED_OPTION, '1' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		require KAYZART_PATH . 'uninstall.php';

		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::STATE_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::DRAFT_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::RESPONSE_META_KEY, true ) );
		$this->assertSame( '', get_user_meta( $this->admin_id, Feedback::PENDING_META_KEY, true ) );
		$this->assertFalse( get_option( Feedback::CLOSED_OPTION ) );
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
			'monthly_volume'     => 'three_five',
			'primary_problem'    => 'responsive',
			'api_key_attitude'   => 'workable',
			'pro_priorities'     => array( 'included_ai', 'client_lock' ),
			'pro_decisive'       => 'included_ai',
			'pricing_preference' => 'multi_site_annual',
			'comment'            => 'Please add reusable sections.',
		);
	}
}
