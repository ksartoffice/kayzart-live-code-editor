<?php
/**
 * Tailwind candidate compiler tests.
 *
 * @package KayzArt
 */

use KayzArt\Limits;
use KayzArt\Tailwind_Compiler;

/**
 * Tailwind compiler coverage.
 */
class KayzArt_Tailwind_Compiler_Test extends WP_UnitTestCase {

	/**
	 * Extraction mirrors the dependency's pattern order and uniqueness.
	 */
	public function test_extract_candidates_matches_tailwindphp_order_and_deduplicates(): void {
		$html = '<div className="md:grid-cols-3 flex"><span class="flex bg-[#123456] before:content-[hello]"></span></div>';

		$this->assertSame(
			array( 'flex', 'bg-[#123456]', 'before:content-[hello]', 'md:grid-cols-3' ),
			Tailwind_Compiler::extract_candidates( $html )
		);
	}

	/**
	 * Tailwind v4 arbitrary values legally contain quotes. The old pattern
	 * stopped at the first inner quote, truncating that candidate and dropping
	 * every later class in the same attribute.
	 */
	public function test_extract_candidates_preserves_quoted_arbitrary_values(): void {
		$html = '<div class="font-[\'Noto_Sans_JP\'] flex md:grid-cols-3"></div>';

		$this->assertSame(
			array( "font-['Noto_Sans_JP']", 'flex', 'md:grid-cols-3' ),
			Tailwind_Compiler::extract_candidates( $html )
		);
	}

	/**
	 * A single-quoted attribute may carry double quotes, and vice versa.
	 */
	public function test_extract_candidates_reads_single_quoted_attributes(): void {
		$html = '<div class=\'bg-[url("a.png")] p-4\'></div>';

		$this->assertSame(
			array( 'bg-[url("a.png")]', 'p-4' ),
			Tailwind_Compiler::extract_candidates( $html )
		);
	}

	/**
	 * An empty attribute matches but contributes nothing.
	 */
	public function test_extract_candidates_skips_empty_class_attributes(): void {
		$this->assertSame(
			array( 'flex' ),
			Tailwind_Compiler::extract_candidates( '<div class=""></div><span class="flex"></span>' )
		);
	}

	/**
	 * Large documents are scanned without an HTML byte ceiling.
	 */
	public function test_extract_candidates_handles_html_larger_than_two_megabytes(): void {
		$html = '<div class="flex text-sm">Large</div>' . str_repeat( 'x', 2 * 1024 * 1024 );

		$this->assertSame( array( 'flex', 'text-sm' ), Tailwind_Compiler::extract_candidates( $html ) );
	}

	/**
	 * The aggregate candidate limit is inclusive.
	 */
	public function test_normalize_candidates_accepts_exact_aggregate_byte_limit(): void {
		$candidates = array();
		for ( $index = 0; $index < 64; $index++ ) {
			$candidates[] = str_repeat( 'a', 4094 ) . str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
		}

		$this->assertSame( Limits::MAX_TAILWIND_CANDIDATE_BYTES, strlen( implode( '', $candidates ) ) );
		$this->assertSame( $candidates, Tailwind_Compiler::normalize_candidates( $candidates ) );
	}

	/**
	 * Candidate count, item size, and syntax are validated.
	 */
	public function test_normalize_candidates_rejects_limits_and_invalid_tokens(): void {
		$too_many = array_fill( 0, Limits::MAX_TAILWIND_CANDIDATES + 1, 'flex' );
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( $too_many ) );
		$this->assertWPError(
			Tailwind_Compiler::normalize_candidates(
				array( str_repeat( 'a', Limits::MAX_TAILWIND_CANDIDATE_ITEM_BYTES + 1 ) )
			)
		);
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( array( 'two classes' ) ) );
	}

	/**
	 * Candidate aggregate size is rejected after the inclusive boundary.
	 */
	public function test_normalize_candidates_rejects_aggregate_byte_overflow(): void {
		$candidates = array();
		for ( $index = 0; $index < 64; $index++ ) {
			$candidates[] = str_repeat( 'a', 4094 ) . str_pad( (string) $index, 2, '0', STR_PAD_LEFT );
		}
		$candidates[] = 'x';

		$this->assertWPError( Tailwind_Compiler::normalize_candidates( $candidates ) );
	}

	/**
	 * Quotes are legal inside arbitrary values and must survive validation.
	 */
	public function test_normalize_candidates_accepts_quoted_arbitrary_values(): void {
		$candidates = array( "font-['Noto_Sans_JP']", 'bg-[url("a.png")]', "before:content-['x']" );

		$this->assertSame( $candidates, Tailwind_Compiler::normalize_candidates( $candidates ) );
	}

	/**
	 * Candidates travel through REST and post meta, so nothing that could be
	 * read as markup or a control sequence is accepted.
	 */
	public function test_normalize_candidates_rejects_angle_brackets_and_control_characters(): void {
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( array( 'flex<span>' ) ) );
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( array( "flex\x00" ) ) );
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( array( "flex\x7F" ) ) );
	}

	/**
	 * Pages saved before the extraction fix hold truncated candidates such as
	 * `font-[` in post meta. They must keep validating so no existing page
	 * starts failing to save; they simply compile to nothing until re-saved.
	 */
	public function test_normalize_candidates_accepts_legacy_truncated_candidates(): void {
		$candidates = array( 'font-[', 'flex' );

		$this->assertSame( $candidates, Tailwind_Compiler::normalize_candidates( $candidates ) );
		$this->assertIsString( Tailwind_Compiler::generate( $candidates, '@import "tailwindcss";' ) );
	}

	/**
	 * Compact candidate input produces the expected utilities.
	 */
	public function test_generate_compiles_compact_candidate_input(): void {
		$generated = Tailwind_Compiler::generate(
			array( 'text-sm', 'md:grid-cols-3' ),
			'@import "tailwindcss";'
		);

		$this->assertIsString( $generated );
		$this->assertStringContainsString( '.text-sm', $generated );
		$this->assertStringContainsString( '.md\\:grid-cols-3', $generated );
	}

	/**
	 * The whole point of compiling candidates directly instead of round-tripping
	 * them through an HTML class attribute: quoted values reach the compiler,
	 * and so does everything listed after them.
	 */
	public function test_generate_emits_rules_for_quoted_arbitrary_values(): void {
		$generated = Tailwind_Compiler::generate(
			array( 'p-4', "font-['Noto_Sans_JP']", "before:content-['x']", 'md:grid-cols-3' ),
			'@import "tailwindcss";'
		);

		$this->assertIsString( $generated );
		$this->assertStringContainsString( '.p-4', $generated );
		$this->assertStringContainsString( 'Noto_Sans_JP', $generated );
		$this->assertStringContainsString( '.md\\:grid-cols-3', $generated );
	}

	/**
	 * Allowing quotes would otherwise make `content-['</style>']` compilable for
	 * the first time and let a closing style tag reach the generated stylesheet.
	 * The angle-bracket rule is what stops it at the door; Frontend's
	 * `</style` escaping remains the second layer.
	 */
	public function test_normalize_candidates_rejects_a_quoted_closing_style_tag(): void {
		$this->assertWPError( Tailwind_Compiler::normalize_candidates( array( "before:content-['</style>']" ) ) );
	}

	/**
	 * Compiling candidates directly must not change output for ordinary input.
	 */
	public function test_generate_output_is_stable_for_plain_utilities(): void {
		$generated = Tailwind_Compiler::generate( array( 'flex', 'p-4' ), '@import "tailwindcss";' );

		$this->assertIsString( $generated );
		$this->assertStringContainsString( '.flex', $generated );
		$this->assertStringContainsString( 'display: flex', $generated );
		$this->assertStringContainsString( '.p-4', $generated );
	}

	/**
	 * An empty CSS tab still compiles against the Tailwind entry point, matching
	 * the convenience default tw::generate() applied.
	 */
	public function test_generate_defaults_to_the_tailwind_entry_point(): void {
		$generated = Tailwind_Compiler::generate( array( 'flex' ), '' );

		$this->assertIsString( $generated );
		$this->assertStringContainsString( '.flex', $generated );
	}
}
