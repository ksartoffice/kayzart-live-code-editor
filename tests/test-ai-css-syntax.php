<?php
/**
 * Unit tests for structural CSS validation of AI edits.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Css_Syntax;
use KayzArt\Ai_Tool_Error;

/**
 * Verify bracket-balance detection and its tolerance for valid CSS.
 */
class Test_Kayzart_Ai_Css_Syntax extends WP_UnitTestCase {

	/**
	 * The stray brace that shipped an unstyled page: a whole stylesheet the
	 * model wrote in one call, closed one time too many at the very end.
	 */
	public function test_find_imbalance_reports_a_trailing_stray_brace(): void {
		$css = "@theme {\n  --color-apple: #e55345;\n}\n\n.apple { border-radius: 50%; }\n}\n";

		$imbalance = Ai_Css_Syntax::find_imbalance( $css );

		$this->assertNotNull( $imbalance );
		$this->assertSame( 6, $imbalance['line'] );
		$this->assertStringContainsString( 'Unexpected closing `}`', $imbalance['message'] );
		$this->assertStringContainsString( 'border-radius: 50%;', $imbalance['context'] );
	}

	/**
	 * A truncated stylesheet is the other half of the same failure.
	 */
	public function test_find_imbalance_reports_an_unclosed_block(): void {
		$css = "@layer base {\n  html { scroll-behavior: smooth; }\n";

		$imbalance = Ai_Css_Syntax::find_imbalance( $css );

		$this->assertNotNull( $imbalance );
		$this->assertSame( 1, $imbalance['line'] );
		$this->assertStringContainsString( 'Unclosed `{`', $imbalance['message'] );
	}

	/**
	 * A mismatched pair is reported against the bracket it fails to close.
	 */
	public function test_find_imbalance_reports_a_mismatched_pair(): void {
		$imbalance = Ai_Css_Syntax::find_imbalance( '.a { color: rgb(1, 2, 3} }' );

		$this->assertNotNull( $imbalance );
		$this->assertStringContainsString( 'does not match the `(`', $imbalance['message'] );
	}

	/**
	 * Braces inside strings, comments and escapes are content, not structure.
	 * Flagging them would block valid edits, which is worse than missing one.
	 *
	 * @dataProvider provide_balanced_css
	 * @param string $label Case description.
	 * @param string $css   CSS source.
	 */
	public function test_find_imbalance_accepts_valid_css( string $label, string $css ): void {
		$this->assertNull( Ai_Css_Syntax::find_imbalance( $css ), $label );
	}

	/**
	 * Valid stylesheets that a naive brace count would reject.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_balanced_css(): array {
		return array(
			'brace in a string'      => array( 'brace in a string', '.a::after { content: "}"; }' ),
			'brace in a comment'     => array( 'brace in a comment', "/* } */\n.a { color: red; }" ),
			'escaped brace'          => array( 'escaped brace', '.a\\{b { color: red; }' ),
			'brace in an attribute'  => array( 'brace in an attribute', '.a[data-x="{"] { color: red; }' ),
			'nested at-rules'        => array( 'nested at-rules', '@media screen { @layer base { .a { --x: 1; } } }' ),
			'unicode escape content' => array( 'unicode escape content', '.a::before { content: "\\201C"; }' ),
			'multibyte content'      => array( 'multibyte content', '.a::before { content: "りんご"; }' ),
			'empty stylesheet'       => array( 'empty stylesheet', '' ),
			'apostrophe in a string' => array( 'apostrophe in a string', ".a::after { content: 'it\\'s'; }" ),
		);
	}

	/**
	 * A stray brace introduced by the edit is the model's to fix, so the error
	 * is retryable and carries the line it has to look at.
	 */
	public function test_assert_no_new_imbalance_rejects_a_broken_edit(): void {
		try {
			Ai_Css_Syntax::assert_no_new_imbalance( '.a { color: red; }', ".a { color: red; }\n}\n" );
			$this->fail( 'Expected the imbalance to be rejected.' );
		} catch ( Ai_Tool_Error $error ) {
			$details = $error->get_details();
			$this->assertTrue( $error->is_retryable() );
			$this->assertSame( 'css_bracket_imbalance', $details['code'] );
			$this->assertSame( 2, $details['line'] );
			$this->assertStringContainsString( 'unbalanced brackets', $error->getMessage() );
		}
	}

	/**
	 * CSS the user already broke stays the user's problem. Blaming the model
	 * for it would only stall the loop part-way through a repair.
	 */
	public function test_assert_no_new_imbalance_tolerates_preexisting_damage(): void {
		Ai_Css_Syntax::assert_no_new_imbalance( ".a { color: red; }\n}\n", ".a { color: blue; }\n}\n" );

		$this->assertTrue( true );
	}

	/**
	 * A balanced edit passes whatever it changed.
	 */
	public function test_assert_no_new_imbalance_accepts_a_valid_edit(): void {
		Ai_Css_Syntax::assert_no_new_imbalance( '.a { color: red; }', "@theme {\n  --color-a: #fff;\n}\n.a { color: var(--color-a); }" );

		$this->assertTrue( true );
	}

	/**
	 * The reported context is cut on byte boundaries, so it has to survive
	 * wp_json_encode() when the surrounding CSS is multibyte.
	 */
	public function test_imbalance_context_is_valid_utf8(): void {
		$css = '.a::before { content: "' . str_repeat( 'りんご', 40 ) . '"; } }';

		$imbalance = Ai_Css_Syntax::find_imbalance( $css );

		$this->assertNotNull( $imbalance );
		$this->assertSame( 1, preg_match( '//u', $imbalance['context'] ) );
		$this->assertNotFalse( wp_json_encode( $imbalance ) );
	}
}
