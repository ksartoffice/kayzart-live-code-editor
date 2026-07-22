<?php
/**
 * Unit tests for the pure AI edit tools.
 *
 * These tests exercise the faithful PHP port of the legacy kayzart-server
 * tool implementations. The tools have no WordPress dependencies, so the
 * assertions here focus on snapshot manipulation and the base-hash port.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Tools;
use KayzArt\Ai_Tool_Error;

/**
 * Verify snapshot tool behavior and base-hash compatibility.
 */
class Test_Kayzart_Ai_Tools extends WP_UnitTestCase {

	/**
	 * Build a snapshot with a freshly computed base hash.
	 *
	 * @param string $html HTML source.
	 * @param string $head Custom head source.
	 * @param string $css  CSS source.
	 * @param string $js   JS source.
	 * @param string $mode jsMode.
	 * @return array
	 */
	private function snapshot( string $html = '', string $head = '', string $css = '', string $js = '', string $mode = 'classic' ): array {
		return array(
			'html'       => $html,
			'customHead' => $head,
			'css'        => $css,
			'js'         => $js,
			'jsMode'     => $mode,
			'baseHash'   => Ai_Tools::compute_base_hash( $html, $head, $css, $js ),
		);
	}

	/** Capture a replacement error for diagnostic assertions.
	 *
	 * @param array $args      Replacement arguments.
	 * @param array $snapshot  Current snapshot.
	 * @param array $selections Selection records.
	 * @return Ai_Tool_Error
	 * @throws RuntimeException When the expected tool error is not raised.
	 */
	private function replace_error( array $args, array $snapshot, array $selections = array() ): Ai_Tool_Error {
		try {
			Ai_Tools::run_replace_string( $args, $snapshot, $selections );
			$this->fail( 'Expected replace_string to fail.' );
		} catch ( Ai_Tool_Error $error ) {
			return $error;
		}
		throw new RuntimeException( 'Unreachable test branch.' );
	}

	/**
	 * The base hash must match the JavaScript editor implementation exactly.
	 *
	 * Reference values were generated from the canonical TypeScript
	 * `computeBaseHash` (FNV-1a over UTF-16 code units).
	 */
	public function test_compute_base_hash_matches_reference_vectors(): void {
		$emoji = "\xF0\x9F\x98\x80"; // U+1F600 grinning face.
		$party = "\xF0\x9F\x8E\x89"; // U+1F389 party popper.
		$jp    = "\xE3\x81\x93\xE3\x82\x93\xE3\x81\xAB\xE3\x81\xA1\xE3\x81\xAF"; // konnichiwa.

		$this->assertSame( 'b7f579d7', Ai_Tools::compute_base_hash( '', '', '', '' ) );
		$this->assertSame( 'ae0ae41f', Ai_Tools::compute_base_hash( '<main>Hi</main>', '', 'body{color:red}', '' ) );
		$this->assertSame( '33ed78a0', Ai_Tools::compute_base_hash( '<h1>JP</h1>', '<meta>', 'h1{font-size:2rem}', 'console.log(1)' ) );
		$this->assertSame( 'e4aed676', Ai_Tools::compute_base_hash( $jp, '', '', '' ) );
		$this->assertSame( '082bdae7', Ai_Tools::compute_base_hash( '<p>' . $emoji . '</p>', '', '', "alert('" . $party . "')" ) );
		$this->assertSame( '2a1fca73', Ai_Tools::compute_base_hash( 'abc', 'def', 'ghi', 'jkl' ) );
	}

	/**
	 * A single exact match is replaced and the hash stays consistent.
	 */
	public function test_replace_string_single_match(): void {
		$snapshot = $this->snapshot( '<main>Hello</main>' );
		$result   = Ai_Tools::run_replace_string(
			array(
				'target' => 'html',
				'from'   => 'Hello',
				'to'     => 'World',
			),
			$snapshot
		);

		$this->assertSame( '<main>World</main>', $result['snapshot']['html'] );
		$this->assertTrue( $result['appliedEditOperation'] );
		$this->assertSame( 1, $result['output']['replacedCount'] );
		$this->assertSame(
			Ai_Tools::compute_base_hash( '<main>World</main>', '', '', '' ),
			$result['snapshot']['baseHash']
		);
	}

	/**
	 * Zero matches raise a retry-friendly error whose message is stable.
	 */
	public function test_replace_string_zero_matches_throws(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'replace_string matched 0 occurrences' );
		Ai_Tools::run_replace_string(
			array(
				'target' => 'html',
				'from'   => 'Missing',
				'to'     => 'x',
			),
			$this->snapshot( '<main>Hello</main>' )
		);
	}

	/** Whitespace-only differences return exact current CSS as a candidate. */
	public function test_replace_no_match_returns_whitespace_equivalent_candidate(): void {
		$current = ".card {\n  color: red;\n}";
		$error   = $this->replace_error(
			array(
				'target' => 'css',
				'from'   => '.card{color:red;}',
				'to'     => '.card{color:blue;}',
			),
			$this->snapshot( '', '', $current )
		);
		$details = $error->get_details();
		$this->assertTrue( $error->is_retryable() );
		$this->assertSame( 'replace_no_match', $details['code'] );
		$this->assertSame( 'whitespace_equivalent', $details['candidates'][0]['matchKind'] );
		$this->assertSame( $current, $details['candidates'][0]['content'] );
		$this->assertSame( hash( 'sha256', $current ), $details['candidates'][0]['contentHash'] );
	}

	/** Anchor diagnostics work across every editable document target. */
	public function test_replace_no_match_returns_anchor_candidates_for_all_targets(): void {
		$cases = array(
			array( 'html', $this->snapshot( '<section class="hero"><h1>Welcome</h1></section>' ), '<section class="hero"><h1>Hello</h1></section>' ),
			array( 'head', $this->snapshot( '', '<meta name="description" content="Current">' ), '<meta name="description" content="Old">' ),
			array( 'css', $this->snapshot( '', '', '.button { color: blue; }' ), '.button { color: red; }' ),
			array( 'js', $this->snapshot( '', '', '', 'function greet(){ return "Hello"; }' ), 'function greet(){ return "Hi"; }' ),
		);
		foreach ( $cases as $case ) {
			$error      = $this->replace_error(
				array(
					'target' => $case[0],
					'from'   => $case[2],
					'to'     => 'replacement',
				),
				$case[1]
			);
			$candidates = $error->get_details()['candidates'];
			$this->assertNotEmpty( $candidates, $case[0] . ' should return an anchor candidate.' );
			$this->assertSame( 'anchor_context', $candidates[0]['matchKind'] );
		}
	}

	/** Candidate count and content remain strictly bounded and UTF-8 safe. */
	public function test_replace_no_match_candidates_are_bounded(): void {
		$marker  = 'unicode-marker-' . "\xE3\x81\x82\xE3\x81\x84\xE3\x81\x86"; // Japanese hiragana suffix.
		$current = str_repeat( 'a', 700 ) . $marker . str_repeat( 'b', 700 ) . $marker . str_repeat( 'c', 700 );
		$error   = $this->replace_error(
			array(
				'target' => 'css',
				'from'   => $marker . '-missing-value',
				'to'     => 'replacement',
			),
			$this->snapshot( '', '', $current )
		);
		$total   = 0;
		$this->assertLessThanOrEqual( Ai_Tools::MAX_REPLACE_DIAGNOSTIC_CANDIDATES, count( $error->get_details()['candidates'] ) );
		$this->assertNotEmpty( $error->get_details()['candidates'] );
		foreach ( $error->get_details()['candidates'] as $candidate ) {
			$length = mb_strlen( $candidate['content'] );
			$total += $length;
			$this->assertLessThanOrEqual( Ai_Tools::MAX_REPLACE_DIAGNOSTIC_CANDIDATE_CHARS, $length );
			$this->assertTrue( mb_check_encoding( $candidate['content'], 'UTF-8' ) );
			$this->assertSame( hash( 'sha256', $candidate['content'] ), $candidate['contentHash'] );
		}
		$this->assertLessThanOrEqual( Ai_Tools::MAX_REPLACE_DIAGNOSTIC_TOTAL_CHARS, $total );
	}

	/** Missing anchors still return structured guidance with no candidates. */
	public function test_replace_no_match_can_return_empty_candidates(): void {
		$error   = $this->replace_error(
			array(
				'target' => 'html',
				'from'   => 'zzzz-completely-absent',
				'to'     => 'replacement',
			),
			$this->snapshot( '<main>Hello</main>' )
		);
		$details = $error->get_details();
		$this->assertSame( array(), $details['candidates'] );
		$this->assertSame( 0, $details['candidateCount'] );
		$this->assertNotEmpty( $details['guidance'] );
	}

	/**
	 * Multiple matches without replaceAll are rejected as ambiguous.
	 */
	public function test_replace_string_ambiguous_throws(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'replace_string is ambiguous' );
		Ai_Tools::run_replace_string(
			array(
				'target' => 'html',
				'from'   => '<p>a</p>',
				'to'     => '<p>b</p>',
			),
			$this->snapshot( '<p>a</p><p>a</p>' )
		);
	}

	/**
	 * Replacing all occurrences reports the total match count.
	 */
	public function test_replace_string_replace_all(): void {
		$result = Ai_Tools::run_replace_string(
			array(
				'target'     => 'html',
				'from'       => '<p>a</p>',
				'to'         => '<p>b</p>',
				'replaceAll' => true,
			),
			$this->snapshot( '<p>a</p><p>a</p>' )
		);

		$this->assertSame( '<p>b</p><p>b</p>', $result['snapshot']['html'] );
		$this->assertSame( 2, $result['output']['replacedCount'] );
	}

	/**
	 * An empty "from" initializes a blank target but not a populated one.
	 */
	public function test_replace_string_empty_from_initializes_blank_target(): void {
		$result = Ai_Tools::run_replace_string(
			array(
				'target' => 'css',
				'from'   => '',
				'to'     => 'body{margin:0}',
			),
			$this->snapshot()
		);
		$this->assertSame( 'body{margin:0}', $result['snapshot']['css'] );

		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'may be empty only when target is blank' );
		Ai_Tools::run_replace_string(
			array(
				'target' => 'html',
				'from'   => '',
				'to'     => 'x',
			),
			$this->snapshot( '<main>Hello</main>' )
		);
	}

	/**
	 * Ordered replacements accumulate the total count (replace_many).
	 */
	public function test_replace_many_applies_in_order(): void {
		$result = Ai_Tools::run_replace_many(
			array(
				'target'       => 'html',
				'replacements' => array(
					array(
						'from' => 'one',
						'to'   => '1',
					),
					array(
						'from' => 'three',
						'to'   => '3',
					),
				),
			),
			$this->snapshot( 'one two three' )
		);

		$this->assertSame( '1 two 3', $result['snapshot']['html'] );
		$this->assertSame( 2, $result['output']['replacedCount'] );
	}

	/**
	 * Search reports the correct line numbers and match count.
	 */
	public function test_search_text_finds_matches(): void {
		$result = Ai_Tools::run_search_text(
			array(
				'query'  => 'find me',
				'target' => 'html',
			),
			$this->snapshot( "line1\nfind me\nline3" )
		);

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 2, $result['matches'][0]['line'] );
	}

	/**
	 * Reading returns the requested inclusive line range.
	 */
	public function test_read_document_returns_line_range(): void {
		$result = Ai_Tools::run_read_document(
			array(
				'target'    => 'html',
				'startLine' => 2,
				'endLine'   => 3,
			),
			$this->snapshot( "l1\nl2\nl3\nl4" )
		);

		$this->assertSame( "l2\nl3", $result['content'] );
		$this->assertSame( 4, $result['totalLines'] );
	}

	/**
	 * Setting the jsMode updates the working snapshot.
	 */
	public function test_set_js_mode(): void {
		$result = Ai_Tools::run_set_js_mode(
			array( 'jsMode' => 'module' ),
			$this->snapshot()
		);
		$this->assertSame( 'module', $result['snapshot']['jsMode'] );
	}

	/**
	 * The dispatcher enforces the editable-target allow list.
	 */
	public function test_run_tool_rejects_non_editable_target(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'is not editable in this mode' );
		Ai_Tools::run_tool(
			'replace_string',
			array(
				'target' => 'css',
				'from'   => 'a',
				'to'     => 'b',
			),
			$this->snapshot( '', '', 'a', '' ),
			null,
			array( 'html', 'head', 'js' )
		);
	}

	/**
	 * The dispatcher rejects unknown tool names.
	 */
	public function test_run_tool_rejects_unknown_tool(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'Unknown tool' );
		Ai_Tools::run_tool( 'nope', array(), $this->snapshot(), null, Ai_Tools::TARGETS );
	}

	/**
	 * Selection reads are bounded and replacement scope avoids identical siblings.
	 */
	public function test_read_and_replace_selected_context(): void {
		$html     = '<p>Hello</p><p>Hello</p>';
		$selected = '<p>Hello</p>';
		$records  = array(
			's1' => array(
				'startOffset' => 12,
				'endOffset'   => 24,
				'contentHash' => hash( 'sha256', $selected ),
				'resolvable'  => true,
			),
		);
		$read     = Ai_Tools::run_read_selection( array( 'selectionId' => 's1' ), $this->snapshot( $html ), $records );
		$this->assertSame( $selected, $read['content'] );

		$result = Ai_Tools::run_replace_string(
			array(
				'target'      => 'html',
				'from'        => 'Hello',
				'to'          => 'World',
				'selectionId' => 's1',
			),
			$this->snapshot( $html ),
			$records
		);
		$this->assertSame( '<p>Hello</p><p>World</p>', $result['snapshot']['html'] );
	}

	/** No-match diagnostics never include source outside the selected element. */
	public function test_replace_no_match_candidates_stay_inside_selection(): void {
		$html     = '<div>outside-secret</div><section class="hero">Selected current text</section>';
		$selected = '<section class="hero">Selected current text</section>';
		$start    = strpos( $html, $selected );
		$records  = array(
			's1' => array(
				'startOffset' => $start,
				'endOffset'   => $start + strlen( $selected ),
				'contentHash' => hash( 'sha256', $selected ),
				'resolvable'  => true,
			),
		);
		$error    = $this->replace_error(
			array(
				'target'      => 'html',
				'from'        => '<section class="hero">Different text</section>',
				'to'          => 'replacement',
				'selectionId' => 's1',
			),
			$this->snapshot( $html ),
			$records
		);
		$details  = $error->get_details();
		$this->assertSame( 'selection', $details['scope'] );
		$this->assertSame( 's1', $details['selectionId'] );
		$this->assertStringNotContainsString( 'outside-secret', wp_json_encode( $details['candidates'] ) );
	}

	/** Replace_many failures diagnose the original snapshot after rollback. */
	public function test_replace_many_no_match_diagnostics_use_rolled_back_snapshot(): void {
		$snapshot = $this->snapshot( 'alpha beta' );
		try {
			Ai_Tools::run_replace_many(
				array(
					'target'       => 'html',
					'replacements' => array(
						array(
							'from' => 'alpha',
							'to'   => 'one',
						),
						array(
							'from' => 'one beta missing',
							'to'   => 'done',
						),
					),
				),
				$snapshot
			);
			$this->fail( 'Expected replace_many to roll back.' );
		} catch ( Ai_Tool_Error $error ) {
			$details = $error->get_details();
			$this->assertSame( 1, $details['failedStepIndex'] );
			$this->assertTrue( $details['transactionRolledBack'] );
			$this->assertSame( $snapshot['baseHash'], $details['baseHash'] );
			$this->assertStringContainsString( 'alpha beta', wp_json_encode( $details['candidates'] ) );
			$this->assertStringNotContainsString( 'one beta', wp_json_encode( $details['candidates'] ) );
		}
		$this->assertSame( 'alpha beta', $snapshot['html'] );
	}

	/** Long reads return a cursor that resumes without gaps. */
	public function test_read_document_cursor_resumes_content(): void {
		$html  = str_repeat( 'a', 30 );
		$first = Ai_Tools::run_read_document(
			array(
				'target'   => 'html',
				'maxChars' => 10,
			),
			$this->snapshot( $html )
		);
		$next  = Ai_Tools::run_read_document(
			array(
				'target'   => 'html',
				'cursor'   => $first['nextCursor'],
				'maxChars' => 10,
			),
			$this->snapshot( $html )
		);
		$this->assertTrue( $first['truncated'] );
		$this->assertSame( str_repeat( 'a', 20 ), $first['content'] . $next['content'] );
	}

	/** Blank document cursors are treated as an omitted first-page cursor. */
	public function test_read_document_blank_cursor_starts_at_first_page(): void {
		foreach ( array( '', " \t\n" ) as $cursor ) {
			$result = Ai_Tools::run_read_document(
				array(
					'target'   => 'html',
					'cursor'   => $cursor,
					'maxChars' => 5,
				),
				$this->snapshot( 'abcdefghij' )
			);
			$this->assertSame( 'abcde', $result['content'] );
		}
	}

	/** Blank selection cursors likewise start at the beginning. */
	public function test_read_selection_blank_cursor_starts_at_first_page(): void {
		$html    = '<p>Hello</p>';
		$records = array(
			's1' => array(
				'startOffset' => 0,
				'endOffset'   => Ai_Tools::utf16_length( $html ),
				'contentHash' => hash( 'sha256', $html ),
				'resolvable'  => true,
			),
		);
		$result  = Ai_Tools::run_read_selection(
			array(
				'selectionId' => 's1',
				'cursor'      => '   ',
			),
			$this->snapshot( $html ),
			$records
		);
		$this->assertSame( $html, $result['content'] );
	}

	/** Non-empty guessed cursors remain a recoverable error with guidance. */
	public function test_read_document_guessed_cursor_is_rejected(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'Omit cursor for the first page' );
		Ai_Tools::run_read_document(
			array(
				'target' => 'html',
				'cursor' => '0',
			),
			$this->snapshot( 'Hello' )
		);
	}

	/** Legacy none placeholders are global only when no selection exists. */
	public function test_none_selection_placeholder_without_records_is_global(): void {
		$result = Ai_Tools::run_replace_string(
			array(
				'target'      => 'html',
				'from'        => 'Hello',
				'to'          => 'World',
				'selectionId' => 'none',
			),
			$this->snapshot( '<p>Hello</p>' )
		);
		$this->assertSame( '<p>World</p>', $result['snapshot']['html'] );
	}

	/** A placeholder must not bypass a real available selection. */
	public function test_none_selection_placeholder_with_records_is_rejected(): void {
		$this->expectException( Ai_Tool_Error::class );
		$this->expectExceptionMessage( 'Invalid selectionId' );
		Ai_Tools::run_replace_string(
			array(
				'target'      => 'html',
				'from'        => 'Hello',
				'to'          => 'World',
				'selectionId' => 'none',
			),
			$this->snapshot( '<p>Hello</p>' ),
			array( 's1' => array( 'resolvable' => true ) )
		);
	}
}
