<?php
/**
 * AI prompt improver tests.
 *
 * @package KayzArt
 */

use KayzArt\Admin;
use KayzArt\Ai_Client_Exception;
use KayzArt\Ai_Client_Fake;
use KayzArt\Ai_Prompt_Improver;

require_once dirname( __DIR__ ) . '/includes/ai/class-kayzart-ai-client-fake.php';

/**
 * Verifies the model contract used before page creation.
 */
class Test_Kayzart_Ai_Prompt_Improver extends WP_UnitTestCase {

	/**
	 * The improver sends only the source instruction and optional title context.
	 */
	public function test_improve_builds_landing_page_brief_request(): void {
		update_option( Admin::OPTION_AI_DEFAULT_MODEL, 'provider/model' );
		$fake = new Ai_Client_Fake( array( array( 'text' => 'Improved landing page brief.' ) ) );

		$result = ( new Ai_Prompt_Improver( $fake ) )->improve( 'Build a salon page.', 'Salon launch' );

		$this->assertSame( 'Improved landing page brief.', $result );
		$calls = $fake->calls();
		$this->assertCount( 1, $calls );
		$this->assertSame( array(), $calls[0]['tools'] );
		$this->assertSame( array( 'provider/model' ), $calls[0]['options']['modelPreference'] );
		$this->assertStringContainsString( 'Do not invent prices', $calls[0]['options']['systemInstruction'] );
		$this->assertStringContainsString( 'Build a salon page.', $calls[0]['messages'][0]['text'] );
		$this->assertStringContainsString( 'Salon launch', $calls[0]['messages'][0]['text'] );
		$this->assertStringNotContainsString( 'editorMode', $calls[0]['messages'][0]['text'] );
		$this->assertStringNotContainsString( 'post_type', $calls[0]['messages'][0]['text'] );
	}

	/**
	 * Empty and oversized provider output is rejected.
	 */
	public function test_improve_rejects_invalid_provider_output(): void {
		$fake = new Ai_Client_Fake( array( array( 'text' => '' ) ) );
		$this->expectException( Ai_Client_Exception::class );
		( new Ai_Prompt_Improver( $fake ) )->improve( 'Build a page.' );
	}

	/**
	 * Restore options changed by a test.
	 */
	protected function tearDown(): void {
		delete_option( Admin::OPTION_AI_DEFAULT_MODEL );
		parent::tearDown();
	}
}
