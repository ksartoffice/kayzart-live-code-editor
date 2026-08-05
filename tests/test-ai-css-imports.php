<?php
/**
 * Unit tests for the Tailwind entry-import guard.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Css_Imports;
use KayzArt\Ai_Tool_Error;

/**
 * Verify detection of the Tailwind entry import and the differential guard.
 */
class Test_Kayzart_Ai_Css_Imports extends WP_UnitTestCase {

	/**
	 * The seed a new Tailwind page carries. Kept byte-identical to
	 * TAILWIND_DEFAULT_CSS in src/admin/logic/css-mode.ts.
	 */
	const TAILWIND_DEFAULT_CSS = "@import \"tailwindcss\";\n\n@theme {\n  /* ... */\n}\n";

	/**
	 * Every quoting form the editor and the model actually produce.
	 *
	 * @dataProvider provide_css_with_the_import
	 * @param string $label Case description.
	 * @param string $css   CSS source.
	 */
	public function test_has_tailwind_import_accepts_every_quoting_form( string $label, string $css ): void {
		$this->assertTrue( Ai_Css_Imports::has_tailwind_import( $css ), $label );
	}

	/**
	 * Sources that carry the entry import.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function provide_css_with_the_import(): array {
		return array(
			'double quoted' => array( 'double quoted', '@import "tailwindcss";' ),
			'single quoted' => array( 'single quoted', "@import 'tailwindcss';" ),
			'url form'      => array( 'url form', '@import url("tailwindcss");' ),
			'url spaced'    => array( 'url spaced', "@import url( 'tailwindcss' );" ),
			'uppercase'     => array( 'uppercase', '@IMPORT  "tailwindcss";' ),
			'editor seed'   => array( 'editor seed', self::TAILWIND_DEFAULT_CSS ),
			'after a rule'  => array( 'after a rule', "/* theme */\n@import \"tailwindcss\";\n.a { color: red; }" ),
		);
	}

	/**
	 * A sub-import is not the entry point, so it must not satisfy the guard.
	 * This is subtle enough to pin rather than leave to inference.
	 */
	public function test_has_tailwind_import_rejects_non_entry_sources(): void {
		$this->assertFalse( Ai_Css_Imports::has_tailwind_import( '' ) );
		$this->assertFalse( Ai_Css_Imports::has_tailwind_import( 'body { margin: 0; }' ) );
		$this->assertFalse( Ai_Css_Imports::has_tailwind_import( '@import "tailwindcss/theme";' ) );
		$this->assertFalse( Ai_Css_Imports::has_tailwind_import( '@import "tailwind";' ) );
	}

	/**
	 * Dropping the import compiles cleanly and emits no utilities at all, so the
	 * model has to be told before the page reaches the user unstyled.
	 */
	public function test_assert_tailwind_import_kept_rejects_a_removed_import(): void {
		try {
			Ai_Css_Imports::assert_tailwind_import_kept(
				self::TAILWIND_DEFAULT_CSS,
				"@theme {\n  --color-a: #fff;\n}\n"
			);
			$this->fail( 'Expected the removed import to be rejected.' );
		} catch ( Ai_Tool_Error $error ) {
			$details = $error->get_details();
			$this->assertTrue( $error->is_retryable() );
			$this->assertSame( 'css_tailwind_import_removed', $details['code'] );
			$this->assertStringContainsString( 'no utility classes', $error->getMessage() );
		}
	}

	/**
	 * A plain CSS page never had the import, and the tool layer has no editor
	 * mode to consult. The differential check is what keeps it out of the way.
	 */
	public function test_assert_tailwind_import_kept_ignores_plain_css_pages(): void {
		Ai_Css_Imports::assert_tailwind_import_kept( '.a { color: red; }', '.a { color: blue; }' );

		$this->assertTrue( true );
	}

	/**
	 * Edits that keep the import pass, including a change of quoting form.
	 *
	 * @dataProvider provide_permitted_transitions
	 * @param string $label  Case description.
	 * @param string $before CSS before the edit.
	 * @param string $after  CSS after the edit.
	 */
	public function test_assert_tailwind_import_kept_accepts_valid_edits( string $label, string $before, string $after ): void {
		Ai_Css_Imports::assert_tailwind_import_kept( $before, $after );

		$this->assertTrue( true, $label );
	}

	/**
	 * Transitions the guard must not interfere with.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provide_permitted_transitions(): array {
		return array(
			'unchanged'      => array( 'unchanged', self::TAILWIND_DEFAULT_CSS, self::TAILWIND_DEFAULT_CSS ),
			'import kept'    => array( 'import kept', self::TAILWIND_DEFAULT_CSS, '@import "tailwindcss";' . "\n.a { color: red; }" ),
			'quoting change' => array( 'quoting change', '@import "tailwindcss";', "@import url('tailwindcss');" ),
			'import added'   => array( 'import added', '.a { color: red; }', '@import "tailwindcss";' . "\n.a { color: red; }" ),
		);
	}
}
