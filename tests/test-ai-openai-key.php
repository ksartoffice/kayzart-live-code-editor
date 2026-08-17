<?php
/**
 * Direct OpenAI API key storage tests.
 *
 * @package KayzArt
 */

use KayzArt\Ai_OpenAI_Key;

/** Verify that credentials are never stored in autoloaded options. */
class Test_Kayzart_Ai_OpenAI_Key extends WP_UnitTestCase {

	/** Remove the credential and registered default after each test. */
	protected function tearDown(): void {
		delete_option( Ai_OpenAI_Key::OPTION );
		remove_all_filters( 'default_option_' . Ai_OpenAI_Key::OPTION );
		parent::tearDown();
	}

	/** A registered empty default must not hide the absence of the database row. */
	public function test_first_key_is_added_without_autoload_when_default_is_registered(): void {
		add_filter( 'default_option_' . Ai_OpenAI_Key::OPTION, '__return_empty_string' );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-first-save' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-first-save', get_option( Ai_OpenAI_Key::OPTION ) );
		$this->assert_option_is_not_autoloaded();
	}

	/** An empty option created by an older settings save is migrated. */
	public function test_existing_empty_autoloaded_option_is_migrated_when_key_is_saved(): void {
		add_option( Ai_OpenAI_Key::OPTION, '', '', 'yes' );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-later-save' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-later-save', get_option( Ai_OpenAI_Key::OPTION ) );
		$this->assert_option_is_not_autoloaded();
	}

	/** Autoload is migrated even when update_option() receives an unchanged key. */
	public function test_existing_unchanged_key_is_migrated_from_autoload(): void {
		add_option( Ai_OpenAI_Key::OPTION, 'sk-test-existing', '', 'yes' );

		$value = Ai_OpenAI_Key::sanitize( 'sk-test-existing' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-existing', get_option( Ai_OpenAI_Key::OPTION ) );
		$this->assert_option_is_not_autoloaded();
	}

	/** Blank submissions preserve both the current key and non-autoload storage. */
	public function test_blank_submission_preserves_existing_key(): void {
		add_option( Ai_OpenAI_Key::OPTION, 'sk-test-preserved', '', 'no' );

		$value = Ai_OpenAI_Key::sanitize( '   ' );
		update_option( Ai_OpenAI_Key::OPTION, $value );

		$this->assertSame( 'sk-test-preserved', get_option( Ai_OpenAI_Key::OPTION ) );
		$this->assert_option_is_not_autoloaded();
	}

	/** Assert that core stored an explicit non-autoload value. */
	private function assert_option_is_not_autoloaded(): void {
		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM $wpdb->options WHERE option_name = %s",
				Ai_OpenAI_Key::OPTION
			)
		);

		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
	}
}
