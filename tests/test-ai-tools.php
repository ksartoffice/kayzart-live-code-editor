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
	 * Selected context echoes non-empty lists and null otherwise.
	 */
	public function test_get_selected_context(): void {
		$this->assertNull( Ai_Tools::run_get_selected_context( null ) );
		$this->assertNull( Ai_Tools::run_get_selected_context( array() ) );
		$contexts = array(
			array(
				'lcId'    => 'a',
				'tagName' => 'div',
			),
		);
		$this->assertSame( $contexts, Ai_Tools::run_get_selected_context( $contexts ) );
	}
}
