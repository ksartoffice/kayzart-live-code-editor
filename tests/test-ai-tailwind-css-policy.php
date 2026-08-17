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
			'selectors canonicalize'    => array(
				'selectors canonicalize',
				"h1,\n  h2   >   span\n{ margin: 0 }\n",
				array( 'h1,h2>span' ),
			),
			'descendant space is kept'  => array(
				'descendant space is kept',
				".a .b { margin: 0 }\n",
				array( '.a .b' ),
			),
			'quoted combinator'         => array(
				'quoted combinator',
				"[data-x=\"a > b\"] { margin: 0 }\n",
				array( '[data-x="a > b"]' ),
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
	 * Reformatting a selector while changing its value is not a new rule.
	 *
	 * A model rewriting an existing rule commonly respaces the selector on the
	 * way past. Comparing the raw text would call that an addition and reject
	 * the edit, which is precisely the legacy-CSS case this guard promises to
	 * stay out of.
	 *
	 * @dataProvider provide_reformatted_selectors
	 * @param string $label  Case description.
	 * @param string $before Selector as written before the edit.
	 * @param string $after  The same selector, respaced.
	 */
	public function test_assert_no_adhoc_rules_allows_respacing_a_selector( string $label, string $before, string $after ): void {
		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules(
			"@import \"tailwindcss\";\n" . $before . " { color: red }\n",
			"@import \"tailwindcss\";\n" . $after . " { color: blue }\n"
		);
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Selector spellings that must compare equal.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_reformatted_selectors(): array {
		return array(
			'comma'            => array( 'comma', '.a,.b', '.a, .b' ),
			'child'            => array( 'child', '.a>.b', '.a > .b' ),
			'adjacent'         => array( 'adjacent', '.a+.b', '.a + .b' ),
			'sibling'          => array( 'sibling', '.a~.b', '.a ~ .b' ),
			'inside :not()'    => array( 'inside :not()', '.a:not(.b,.c)', '.a:not(.b, .c)' ),
			'wrapped in lines' => array( 'wrapped in lines', '.a, .b, .c', ".a,\n.b,\n.c" ),
		);
	}

	/**
	 * A descendant combinator is a real space and must stay one.
	 *
	 * Canonicalizing spacing must not go so far that `.a .b` and `.a.b` become
	 * the same key, which would let a genuinely new rule through.
	 */
	public function test_assert_no_adhoc_rules_distinguishes_descendant_from_compound(): void {
		$before = "@import \"tailwindcss\";\n.a .b { color: red }\n";
		$error  = $this->reject(
			$before,
			$before . ".a.b { color: blue }\n",
			'Expected a compound selector to differ from a descendant one.'
		);

		$this->assertSame( array( '.a.b' ), $error->get_details()['selectors'] );
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
	 * The entry import counts only where the compiler honours it.
	 *
	 * A regex over the whole source matches the literal text inside
	 * `content: '@import "tailwindcss"'`, which would turn the whole rule set on
	 * for a page that never used Tailwind and reject its ordinary CSS.
	 *
	 * @dataProvider provide_sources_without_a_live_import
	 * @param string $label Case description.
	 * @param string $css   CSS source.
	 */
	public function test_has_live_tailwind_import_rejects_inert_occurrences( string $label, string $css ): void {
		$this->assertFalse( Ai_Tailwind_Css_Policy::has_live_tailwind_import( $css ), $label );

		Ai_Tailwind_Css_Policy::assert_no_adhoc_rules( $css, $css . ".added { color: blue; }\n" );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Sources where the import text appears but means nothing to the compiler.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_sources_without_a_live_import(): array {
		return array(
			'inside a declaration string' => array(
				'inside a declaration string',
				".example::after { content: '@import \"tailwindcss\"'; }\n",
			),
			'inside a comment'            => array(
				'inside a comment',
				"/* @import \"tailwindcss\"; */\n.a { color: red; }\n",
			),
			'nested in a block'           => array(
				'nested in a block',
				"@media print {\n  @import \"tailwindcss\";\n}\n.a { color: red; }\n",
			),
		);
	}

	/**
	 * The ordinary import is still recognised in every quoting form.
	 *
	 * @dataProvider provide_sources_with_a_live_import
	 * @param string $label Case description.
	 * @param string $css   CSS source.
	 */
	public function test_has_live_tailwind_import_accepts_real_imports( string $label, string $css ): void {
		$this->assertTrue( Ai_Tailwind_Css_Policy::has_live_tailwind_import( $css ), $label );
	}

	/**
	 * Sources carrying a live entry import.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_sources_with_a_live_import(): array {
		return array(
			'double quoted'    => array( 'double quoted', "@import \"tailwindcss\";\n" ),
			'single quoted'    => array( 'single quoted', "@import 'tailwindcss';\n" ),
			'url form'         => array( 'url form', "@import url(\"tailwindcss\");\n" ),
			'after a comment'  => array( 'after a comment', "/* theme */\n@import \"tailwindcss\";\n" ),
			'the default seed' => array( 'the default seed', self::TAILWIND_DEFAULT_CSS ),
		);
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
