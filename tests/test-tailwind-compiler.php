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
}
