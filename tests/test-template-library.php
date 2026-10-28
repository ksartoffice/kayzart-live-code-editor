<?php
/**
 * Offline template loading, creation, and failure ownership tests.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Snapshot;
use KayzArt\Template_Library;
use KayzArt\Template_Page_Creator;

class Test_Template_Library extends WP_UnitTestCase {

	private $created = array();

	protected function setUp(): void {
		parent::setUp();
		update_option( Admin::OPTION_ENABLED_POST_TYPES, array( 'page' ) );
		Snapshot::register_revision_support();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	protected function tearDown(): void {
		foreach ( $this->created as $id ) {
			foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) as $attachment ) {
				wp_delete_attachment( $attachment->ID, true );
			}
			wp_delete_post( $id, true );
		}
		remove_all_filters( 'wp_handle_sideload_prefilter' );
		delete_option( Admin::OPTION_ENABLED_POST_TYPES );
		parent::tearDown();
	}

	private function create_page( $request = null, $locale = 'ja' ) {
		$result = Template_Page_Creator::create( 'general-service-lp', $request ?? wp_generate_uuid4(), 'Template test', $locale );
		if ( is_int( $result ) ) {
			$this->created[] = $result;
		}
		return $result;
	}

	public function test_only_allowlisted_ids_can_be_loaded(): void {
		$this->assertWPError( Template_Library::get( '../general-service-lp' ) );
		$this->assertWPError( Template_Library::get( 'https://example.test/template' ) );
		$this->assertWPError( Template_Library::get( 'missing' ) );
		$template = Template_Library::get( 'general-service-lp' );
		$this->assertIsArray( $template );
		$this->assertSame( 'ja', $template['locale'] );
		$this->assertSame( 'tailwind', $template['cssMode'] );
	}

	public function test_default_content_language_uses_site_language_not_user_language(): void {
		$site_locale = 'ja';
		$site_language = static function () use ( &$site_locale ) { return $site_locale; };
		add_filter( 'pre_option_WPLANG', $site_language );
		try {
			update_user_meta( get_current_user_id(), 'locale', 'en_US' );
			$this->assertSame( 'ja', Template_Library::default_locale() );
			update_user_meta( get_current_user_id(), 'locale', 'ja' );
			foreach ( array( '', 'en_US', 'fr_FR' ) as $site_locale ) {
				$this->assertSame( 'en', Template_Library::default_locale() );
			}
		} finally {
			remove_filter( 'pre_option_WPLANG', $site_language );
		}
	}

	public function test_english_variant_shares_the_design_and_has_only_english_copy(): void {
		$ja = Template_Library::get( 'general-service-lp', 'ja' );
		$en = Template_Library::get( 'general-service-lp', 'en' );
		$this->assertIsArray( $en );
		$this->assertSame( 'en', $en['locale'] );
		$this->assertSame( $ja['css'], $en['css'] );
		$this->assertSame( $ja['assets'], $en['assets'] );
		$this->assertStringContainsString( 'lang="en"', $en['html'] );
		$this->assertStringContainsString( 'Good design.', $en['html'] );
		$this->assertSame( 0, preg_match( '/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $en['html'] ) );
		preg_match_all( '/class="([^"]*)"/', $ja['html'], $ja_classes );
		preg_match_all( '/class="([^"]*)"/', $en['html'], $en_classes );
		$this->assertSame( $ja_classes[1], $en_classes[1] );
		$this->assertSame( 'thumbnail.en.webp', $en['files']['thumbnail'] );
	}

	public function test_unsupported_locales_cannot_create_content_or_resolve_files(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		foreach ( array( '', '../en', 'en_US', 'fr', 'https://example.test/en' ) as $locale ) {
			$this->assertWPError( Template_Library::get( 'general-service-lp', $locale ) );
			$this->assertWPError( Template_Library::preview( 'general-service-lp', $locale ) );
			$this->assertWPError( $this->create_page( null, $locale ) );
		}
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
	}

	public function test_localized_preview_caches_do_not_mix_languages(): void {
		$en = Template_Library::preview( 'general-service-lp', 'en' );
		$ja = Template_Library::preview( 'general-service-lp', 'ja' );
		$this->assertIsString( $en );
		$this->assertStringContainsString( '<html lang="en">', $en );
		$this->assertStringContainsString( 'Good design.', $en );
		$this->assertStringContainsString( '<html lang="ja">', $ja );
		$this->assertStringNotContainsString( 'Good design.', $ja );
		$this->assertSame( $en, Template_Library::preview( 'general-service-lp', 'en' ) );
	}

	public function test_english_creation_and_retries_preserve_the_selected_language(): void {
		$request = wp_generate_uuid4();
		$id      = $this->create_page( $request, 'en' );
		$this->assertIsInt( $id );
		$this->assertSame( 'en', get_post_meta( $id, Template_Page_Creator::LOCALE_META, true ) );
		$this->assertStringContainsString( 'lang="en"', get_post( $id )->post_content );
		$this->assertStringContainsString( 'Good design.', get_post( $id )->post_content );
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], get_post( $id )->post_content );
		$this->assertSame( $id, $this->create_page( $request, 'en' ) );
		$conflict = $this->create_page( $request, 'ja' );
		$this->assertWPError( $conflict );
		$this->assertSame( 'template_request_conflict', $conflict->get_error_code() );
		$this->assertSame( 'en', get_post_meta( $id, Template_Page_Creator::LOCALE_META, true ) );
		$this->assertStringContainsString( 'Good design.', get_post( $id )->post_content );
	}

	public function test_language_selector_and_retry_keep_preview_and_form_in_sync(): void {
		$original = get_option( 'WPLANG', '' );
		update_option( 'WPLANG', 'en_US' );
		set_transient( 'kayzart_template_retry_' . get_current_user_id(), array( 'locale' => 'ja', 'title' => 'Retry title' ) );
		ob_start();
		try {
			KayzArt\Template_Admin::render( false );
			$markup = ob_get_contents();
		} finally {
			ob_end_clean();
			update_option( 'WPLANG', $original );
		}
		$this->assertMatchesRegularExpression( '/<option[^>]+value="ja"[^>]+selected/', $markup );
		$this->assertStringContainsString( 'form="kayzart-template-form-general-service-lp"', $markup );
		$this->assertStringContainsString( 'name="template_locale"', $markup );
		$this->assertStringContainsString( 'template_locale=ja', $markup );
		$this->assertStringContainsString( 'value="Retry title"', $markup );
		preg_match_all( '/data-preview="([^"]+)"/', $markup, $urls );
		$this->assertCount( 2, $urls[1] );
		foreach ( $urls[1] as $url ) {
			parse_str( wp_parse_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), PHP_URL_QUERY ), $query );
			$this->assertSame( 'general-service-lp', $query['template_id'] );
			$this->assertContains( $query['template_locale'], array( 'ja', 'en' ) );
			$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], KayzArt\Template_Admin::PREVIEW_ACTION ) );
		}
	}

	public function test_preview_is_isolated_local_and_has_no_database_content_side_effects(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$calls  = array();
		$block  = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $block, 10, 3 );
		try {
			$preview = Template_Library::preview( 'general-service-lp' );
			$this->assertIsString( $preview );
			$this->assertStringContainsString( 'Content-Security-Policy', $preview );
			$this->assertStringContainsString( 'images/landscape.png', $preview );
			$this->assertStringContainsString( '--color-ink', $preview );
			$this->assertStringNotContainsString( '{{asset:', $preview );
			$this->assertStringNotContainsString( 'demo-workspace', $preview );
			$this->assertStringNotContainsString( '<script', $preview );
			$this->assertSame( $preview, Template_Library::preview( 'general-service-lp' ) );
			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
			$this->assertSame( array(), $calls );
		} finally {
			remove_filter( 'pre_http_request', $block );
		}
	}

	public function test_complete_draft_has_independent_media_css_buffers_and_revision(): void {
		$id = $this->create_page();
		$this->assertIsInt( $id );
		$this->assertSame( 'page', get_post_type( $id ) );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( 'standalone', get_post_meta( $id, '_kayzart_template_mode', true ) );
		$this->assertSame( '1', get_post_meta( $id, '_kayzart_tailwind', true ) );
		$this->assertFalse( metadata_exists( 'post', $id, '_kayzart_normal_css' ) );
		$snapshot = Snapshot::for_post( $id );
		$this->assertNull( $snapshot['cssByMode']['normal'] );
		$this->assertSame( $snapshot['css'], $snapshot['cssByMode']['tailwind'] );
		$this->assertStringContainsString( wp_upload_dir()['baseurl'], $snapshot['html'] );
		$this->assertStringNotContainsString( 'assets/templates', $snapshot['html'] );
		$this->assertStringNotContainsString( '{{asset:', $snapshot['html'] );
		$this->assertSame( '', get_post_meta( $id, Admin::INITIAL_AI_REQUEST_META_KEY, true ) );
		$this->assertCount( 1, get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) ) );
		if ( Snapshot::revisions_enabled( $id ) ) {
			$revisions = wp_get_post_revisions( $id );
			$this->assertCount( 1, $revisions );
			$this->assertSame( Snapshot::hash( $snapshot ), Snapshot::hash( Snapshot::for_revision( (int) key( $revisions ) ) ) );
		}
	}

	public function test_repeated_request_returns_same_draft_without_new_media(): void {
		$request = wp_generate_uuid4();
		$first   = $this->create_page( $request );
		$this->assertIsInt( $first );
		$this->assertSame( $first, Template_Page_Creator::create( 'general-service-lp', $request, 'Do not overwrite' ) );
		$this->assertSame( 'Template test', get_the_title( $first ) );
		$this->assertCount( 1, get_children( array( 'post_parent' => $first, 'post_type' => 'attachment' ) ) );
	}

	public function test_new_pages_do_not_share_placeholder_attachments(): void {
		$one = $this->create_page();
		$two = $this->create_page();
		$this->assertIsInt( $one );
		$this->assertIsInt( $two );
		$this->assertNotSame( get_post( $one )->post_content, get_post( $two )->post_content );
	}

	public function test_user_without_media_permission_cannot_create(): void {
		$user = wp_get_current_user();
		$user->add_cap( 'upload_files', false );
		$result = $this->create_page();
		$this->assertWPError( $result );
		$this->assertSame( 'template_forbidden', $result->get_error_code() );
	}

	public function test_disabled_page_and_invalid_request_are_rejected(): void {
		$this->assertWPError( $this->create_page( 'not-a-uuid' ) );
		update_option( Admin::OPTION_ENABLED_POST_TYPES, array( 'post' ) );
		$this->assertNotEmpty( Template_Library::unavailable_reason() );
		$this->assertWPError( $this->create_page() );
	}

	public function test_upload_failure_removes_its_draft_and_keeps_bundled_source(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		add_filter( 'wp_handle_sideload_prefilter', static function ( $file ) {
			$file['error'] = 'Fixture upload failure';
			return $file;
		} );
		$this->assertWPError( $this->create_page() );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		$this->assertFileExists( KAYZART_PATH . 'assets/templates/free/general-service-lp/images/landscape.png' );
	}

	public function test_editor_without_unfiltered_html_keeps_template_layout(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		wp_get_current_user()->add_cap( 'unfiltered_html', false );
		$id = $this->create_page();
		$this->assertIsInt( $id );
		$this->assertStringContainsString( 'lg:grid-cols-2', get_post( $id )->post_content );
		$this->assertStringNotContainsString( '<svg', get_post( $id )->post_content );
	}

	public function test_creation_and_media_registration_do_not_make_http_requests(): void {
		$calls = array();
		$block = static function ( $pre, $args, $url ) use ( &$calls ) {
			$calls[] = $url;
			return new WP_Error( 'unexpected_http' );
		};
		add_filter( 'pre_http_request', $block, 10, 3 );
		try {
			$this->assertIsInt( $this->create_page() );
			$this->assertSame( array(), $calls );
		} finally {
			remove_filter( 'pre_http_request', $block );
		}
	}

	public function test_parallel_request_is_rejected_and_other_requests_still_work(): void {
		$request = wp_generate_uuid4();
		$lock    = 'kayzart_template_lock_' . md5( get_current_user_id() . ':' . $request );
		add_option( $lock, time(), '', false );
		try {
			$result = $this->create_page( $request );
			$this->assertWPError( $result );
			$this->assertSame( 'template_busy', $result->get_error_code() );
			$this->assertIsInt( $this->create_page() );
		} finally {
			delete_option( $lock );
		}
	}

	public function test_failed_save_collects_the_new_page_and_its_media(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$break  = static function ( $data ) {
			if ( 'page' === $data['post_type'] && '' !== $data['post_content'] ) {
				$data['post_content'] = '<p>Fixture rejected save</p>';
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $break );
		try {
			$this->assertWPError( $this->create_page() );
			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		} finally {
			remove_filter( 'wp_insert_post_data', $break );
		}
	}

	public function test_duplication_copies_origin_but_not_request_identity(): void {
		$source = $this->create_page();
		$this->assertIsInt( $source );
		$copy            = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->created[] = $copy;
		$method          = new ReflectionMethod( Admin::class, 'copy_kayzart_post_data' );
		$method->setAccessible( true );
		$method->invoke( null, get_post( $source ), $copy );
		$this->assertSame( 'general-service-lp', get_post_meta( $copy, Template_Page_Creator::ID_META, true ) );
		$this->assertSame( 'ja', get_post_meta( $copy, Template_Page_Creator::LOCALE_META, true ) );
		$this->assertSame( '', get_post_meta( $copy, Template_Page_Creator::REQUEST_META, true ) );
		$this->assertSame( '', get_post_meta( $copy, Template_Page_Creator::COMPLETE_META, true ) );
	}

	public function test_image_metadata_exception_also_collects_the_inserted_attachment(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$break  = static function () {
			throw new RuntimeException( 'Fixture metadata exception' );
		};
		add_filter( 'wp_generate_attachment_metadata', $break );
		try {
			$this->assertWPError( $this->create_page() );
			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		} finally {
			remove_filter( 'wp_generate_attachment_metadata', $break );
		}
	}

	public function test_draft_insert_exception_collects_the_created_draft(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$break = static function ( $post_id ) {
			if ( get_post_meta( $post_id, Template_Page_Creator::REQUEST_META, true ) ) {
				throw new RuntimeException( 'Fixture insert hook exception' );
			}
		};
		add_action( 'wp_after_insert_post', $break );
		try {
			$this->assertWPError( $this->create_page() );
			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		} finally {
			remove_action( 'wp_after_insert_post', $break );
		}
	}

	public function test_original_png_works_without_an_image_editor(): void {
		add_filter( 'wp_image_editors', '__return_empty_array' );
		try {
			$id = $this->create_page();
			$this->assertIsInt( $id );
			$attachments = get_children( array( 'post_parent' => $id, 'post_type' => 'attachment' ) );
			$this->assertCount( 1, $attachments );
			$this->assertFileExists( get_attached_file( (int) key( $attachments ) ) );
		} finally {
			remove_filter( 'wp_image_editors', '__return_empty_array' );
		}
	}

	public function test_disabled_revisions_do_not_prevent_creation(): void {
		add_filter( 'wp_revisions_to_keep', '__return_zero' );
		try {
			$id = $this->create_page();
			$this->assertIsInt( $id );
			$this->assertSame( array(), wp_get_post_revisions( $id ) );
		} finally {
			remove_filter( 'wp_revisions_to_keep', '__return_zero' );
		}
	}

	public function test_page_creation_permission_is_required_without_side_effects(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( current_user_can( 'upload_files' ) );
		$result = $this->create_page();
		$this->assertWPError( $result );
		$this->assertSame( 'template_forbidden', $result->get_error_code() );
	}

	public function test_invalid_nonce_never_reaches_creation(): void {
		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
		$old_request = $_REQUEST;
		$_REQUEST = array( '_wpnonce' => 'invalid', 'start_mode' => 'template' );
		$reject = static function () {
			return static function () { throw new RuntimeException( 'Fixture nonce rejected' ); };
		};
		add_filter( 'wp_die_handler', $reject );
		try {
			try {
				Admin::action_create_new_page();
				$this->fail( 'An invalid nonce must be rejected.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'Fixture nonce rejected', $error->getMessage() );
			}
			$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) );
		} finally {
			$_REQUEST = $old_request;
			remove_filter( 'wp_die_handler', $reject );
		}
	}
}
