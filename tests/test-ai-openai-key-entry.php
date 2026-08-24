<?php
/**
 * Direct OpenAI key entry policy tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_OpenAI_Key;

/**
 * Once WordPress can hold a provider itself, Kayzart stops offering a second
 * place to put one. Sites upgraded from 6.9 keep managing the key they already
 * saved, and removing it is one-way.
 */
class Test_Kayzart_Ai_OpenAI_Key_Entry extends WP_UnitTestCase {

	/**
	 * WordPress version reported before a test overrode it.
	 *
	 * @var string
	 */
	private $original_wp_version = '';

	protected function setUp(): void {
		parent::setUp();
		global $wp_version;
		$this->original_wp_version = (string) $wp_version;
	}

	protected function tearDown(): void {
		global $wp_version;
		$wp_version = $this->original_wp_version;
		remove_all_filters( 'kayzart_ai_sdk_present' );
		remove_all_filters( 'kayzart_show_direct_openai_key_field' );
		delete_option( Ai_OpenAI_Key::OPTION );
		global $wp_settings_errors;
		$wp_settings_errors = array();
		parent::tearDown();
	}

	/** Report whether the WordPress AI Client is present on this site. */
	private function with_ai_client( bool $present ): void {
		add_filter( 'kayzart_ai_sdk_present', $present ? '__return_true' : '__return_false' );
	}

	/** Set the WordPress version this request reports. */
	private function with_wp_version( string $version ): void {
		global $wp_version;
		$wp_version = $version;
	}

	/** Without the AI Client there is no Connectors screen to send anyone to. */
	public function test_entry_is_offered_when_the_ai_client_is_absent(): void {
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( false );

		$this->assertTrue( Ai_OpenAI_Key::is_entry_allowed() );
	}

	/** Below 7.0 the direct key is the only way to reach a provider. */
	public function test_entry_is_offered_below_wordpress_7(): void {
		$this->with_wp_version( '6.9' );
		$this->with_ai_client( true );

		$this->assertTrue( Ai_OpenAI_Key::is_entry_allowed() );
	}

	/**
	 * A Connector-capable site is not asked for a second credential, whether or
	 * not Connectors has been filled in yet.
	 */
	public function test_entry_is_refused_on_a_connector_capable_site_without_a_saved_key(): void {
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( true );

		$this->assertFalse( Ai_OpenAI_Key::is_entry_allowed() );
	}

	/** Hiding the field and refusing the write must not disagree. */
	public function test_a_refused_site_does_not_store_a_submitted_key(): void {
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( true );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-refused' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( '', $value );
		$this->assertSame( '', (string) get_option( Ai_OpenAI_Key::OPTION, '' ) );
		$this->assertNotEmpty(
			wp_list_filter( get_settings_errors( Ai_OpenAI_Key::OPTION ), array( 'code' => 'kayzart_openai_key_not_offered' ) )
		);
	}

	/** An upgraded site keeps the key it already had, and can rotate it. */
	public function test_a_key_saved_before_the_upgrade_can_still_be_replaced(): void {
		add_option( Ai_OpenAI_Key::OPTION, 'sk-test-legacy', '', 'no' );
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( true );

		$this->assertTrue( Ai_OpenAI_Key::is_entry_allowed() );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-rotated' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-rotated', (string) get_option( Ai_OpenAI_Key::OPTION, '' ) );
	}

	/** Removing the saved key closes entry for good. */
	public function test_removing_the_saved_key_closes_entry(): void {
		add_option( Ai_OpenAI_Key::OPTION, 'sk-test-legacy', '', 'no' );
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( true );
		$this->assertTrue( Ai_OpenAI_Key::is_entry_allowed() );

		delete_option( Ai_OpenAI_Key::OPTION );

		$this->assertFalse( Ai_OpenAI_Key::is_entry_allowed() );
		$this->assertSame( '', Ai_OpenAI_Key::sanitize( 'sk-test-second-attempt' ) );
	}

	/** The filter is the way back for a site Connectors cannot serve. */
	public function test_filter_restores_entry_and_the_write(): void {
		$this->with_wp_version( '7.0' );
		$this->with_ai_client( true );
		add_filter( 'kayzart_show_direct_openai_key_field', '__return_true' );

		$this->assertTrue( Ai_OpenAI_Key::is_entry_allowed() );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-filtered-back' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-filtered-back', (string) get_option( Ai_OpenAI_Key::OPTION, '' ) );
	}

	/** The filter can also close entry on a site that would otherwise offer it. */
	public function test_filter_can_close_entry_below_wordpress_7(): void {
		$this->with_wp_version( '6.9' );
		$this->with_ai_client( false );
		add_filter( 'kayzart_show_direct_openai_key_field', '__return_false' );

		$this->assertFalse( Ai_OpenAI_Key::is_entry_allowed() );
	}
}
