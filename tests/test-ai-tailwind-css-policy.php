<?php
/**
 * Unit tests for the Tailwind authoring-idiom guard.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Tailwind_Css_Policy;
use KayzArt\Ai_Tool_Error;

/**
 * Verify top-level rule detection and the differential guard.
 */
class Test_Kayzart_Ai_Tailwind_Css_Policy extends WP_UnitTestCase {

	/**
	 * The seed a new Tailwind page carries. Kept byte-identical to
	 * TAILWIND_DEFAULT_CSS in src/admin/logic/css-mode.ts.
	 */
	const TAILWIND_DEFAULT_CSS = "@import \"tailwindcss\";\n\n@theme {\n  /* ... */\n}\n";

	/**
	 * Assert that an edit is rejected and return the error for inspection.
	 *
	 * @param string $before  CSS before the edit.
	 * @param string $after   CSS after the edit.
	 * @param string $message Failure description.
	 * @return Ai_Tool_Error
	 */
	private function reject( string $before, string $after, string $message ): Ai_Tool_Error {
		try {
			Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( $before, $after );
		} catch ( Ai_Tool_Error $error ) {
			return $error;
		}
		$this->fail( $message );
	}

	/**
	 * Only rules at depth zero whose prelude is a selector are collected.
	 *
	 * @dataProvider provide_selector_sources
	 * @param string            $label    Case description.
	 * @param string            $css      CSS source.
	 * @param array<int,string> $expected Expected selectors.
	 */
	public function test_top_level_selectors( string $label, string $css, array $expected ): void {
		$this->assertSame( $expected, Ai_Tailwind_Css_Policy::top_level_selectors( $css ), $label );
	}

	/**
	 * Sources covering each construct the parser has to tell apart.
	 *
	 * @return array<string,array{0:string,1:string,2:array<int,string>}>
	 */
	public function provide_selector_sources(): array {
		return array(
			'the default seed'          => array(
				'the default seed',
				self::TAILWIND_DEFAULT_CSS,
				array(),
			),
			'a bare rule'               => array(
				'a bare rule',
				"@import \"tailwindcss\";\n.btn { background: red; }\n",
				array( '.btn' ),
			),
			'at-rule bodies are theirs' => array(
				'at-rule bodies are theirs',
				"@import \"tailwindcss\";\n@media (min-width: 40rem) {\n  .btn { color: red; }\n}\n@utility card {\n  padding: 1rem;\n}\n",
				array(),
			),
			'nested at-rules'           => array(
				'nested at-rules',
				"@layer components {\n  @media print {\n    .a { color: red; }\n  }\n}\n",
				array(),
			),
			'whitespace is collapsed'   => array(
				'whitespace is collapsed',
				"h1,\n  h2   >   span\n{ margin: 0 }\n",
				array( 'h1, h2 > span' ),
			),
			'commented-out rule'        => array(
				'commented-out rule',
				"@import \"tailwindcss\";\n/* .btn { background: red; } */\n",
				array(),
			),
			'a brace inside a string'   => array(
				'a brace inside a string',
				"@import \"tailwindcss\";\n@theme {\n  --x: 1;\n}\n.a::after { content: \"}\"; }\n",
				array( '.a::after' ),
			),
			'statement then rule'       => array(
				'statement then rule',
				"@import \"tailwindcss\";\n@source \"./src\";\n.a { color: red; }\n",
				array( '.a' ),
			),
		);
	}

	/**
	 * The regression this class exists for: a hand-rolled rule that compiles and
	 * renders but sits outside the design system.
	 */
	public function test_assert_no_adhoc_rules_rejects_an_added_rule(): void {
		$error = $this->reject(
			self::TAILWIND_DEFAULT_CSS,
			self::TAILWIND_DEFAULT_CSS . "\n.btn {\n  background: #ff0000;\n}\n",
			'Expected the hand-written rule to be rejected.'
		);

		$details = $error->get_details();
		$this->assertTrue( $error->is_retryable(), 'The model has to be able to correct itself.' );
		$this->assertSame( 'css_adhoc_rule_added', $details['code'] );
		$this->assertSame( array( '.btn' ), $details['selectors'] );
		$this->assertStringContainsString( '`.btn`', $error->getMessage() );
		$this->assertStringContainsString( '@theme token', $error->getMessage() );
	}

	/**
	 * The case the removed target gate broke: changing a shared value through a
	 * theme token is the correct Tailwind answer and must pass.
	 */
	public function test_assert_no_adhoc_rules_allows_a_theme_token_change(): void {
		$before = "@import \"tailwindcss\";\n\n@theme {\n  --color-brand: #1d4ed8;\n}\n";
		$after  = "@import \"tailwindcss\";\n\n@theme {\n  --color-brand: #b91c1c;\n}\n";

		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( $before, $after );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tailwind at-rules are the sanctioned places for real declarations.
	 *
	 * Adding an @utility or a @custom-variant is the idiom working, not an
	 * ad-hoc rule.
	 */
	public function test_assert_no_adhoc_rules_allows_new_at_rules(): void {
		$after = self::TAILWIND_DEFAULT_CSS
			. "\n@utility btn-brand {\n  background: var(--color-brand);\n}\n"
			. "\n@custom-variant hocus (&:hover, &:focus);\n";

		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( self::TAILWIND_DEFAULT_CSS, $after );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A page that carried hand-written CSS before it moved to Tailwind stays
	 * editable: the guard is differential, so only new selectors are its
	 * business.
	 */
	public function test_assert_no_adhoc_rules_allows_editing_a_pre_existing_rule(): void {
		$before = "@import \"tailwindcss\";\n.legacy { color: red; }\n";
		$after  = "@import \"tailwindcss\";\n.legacy { color: blue; padding: 1rem; }\n";

		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( $before, $after );
		$this->addToAssertionCount( 1 );

		$error = $this->reject(
			$before,
			$before . ".legacy-2 { color: blue; }\n",
			'Expected a second, newly added rule to be rejected.'
		);
		$this->assertSame( array( '.legacy-2' ), $error->get_details()['selectors'] );
	}

	/**
	 * Removing hand-written CSS is the direction this guard wants, never a
	 * violation.
	 */
	public function test_assert_no_adhoc_rules_allows_removing_a_rule(): void {
		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules(
			"@import \"tailwindcss\";\n.legacy { color: red; }\n",
			self::TAILWIND_DEFAULT_CSS
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A plain CSS page never had the entry import, and the tool layer has no
	 * editor mode to consult. The import gate is what keeps normal mode out of
	 * this entirely.
	 */
	public function test_assert_no_adhoc_rules_ignores_plain_css_pages(): void {
		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules(
			".a { color: red; }\n",
			".a { color: red; }\n.b { color: blue; }\n"
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Twenty instances of one mistake are still one mistake, and listing them
	 * all would bury the instruction that follows.
	 */
	public function test_assert_no_adhoc_rules_caps_the_reported_selectors(): void {
		$after = self::TAILWIND_DEFAULT_CSS;
		for ( $i = 0; $i < 6; $i++ ) {
			$after .= "\n.r{$i} { color: red; }\n";
		}

		$error = $this->reject( self::TAILWIND_DEFAULT_CSS, $after, 'Expected the added rules to be rejected.' );

		$this->assertCount( 3, $error->get_details()['selectors'] );
		$this->assertStringContainsString( 'and 3 more', $error->getMessage() );
	}

	/**
	 * An unchanged stylesheet is never inspected, so a page already carrying
	 * hand-written CSS does not fail an edit that only touched the HTML.
	 */
	public function test_assert_no_adhoc_rules_ignores_an_unchanged_stylesheet(): void {
		$css = "@import \"tailwindcss\";\n.legacy { color: red; }\n";

		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( $css, $css );
		$this->addToAssertionCount( 1 );
	}
}
