<?php
/**
 * Unit tests for the AI tool schema and edit-target policy.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Tool_Schema;

/**
 * Verify tool schema generation and edit-policy resolution.
 */
class Test_Kayzart_Ai_Tool_Schema extends WP_UnitTestCase {

	/**
	 * Collect the tool names from a definition list.
	 *
	 * @param array $tools Tool definitions.
	 * @return array<int,string>
	 */
	private function tool_names( array $tools ): array {
		return array_map(
			static function ( $tool ) {
				return $tool['name'];
			},
			$tools
		);
	}

	/**
	 * Locate a tool definition by name.
	 *
	 * @param array  $tools Tool definitions.
	 * @param string $name  Tool name.
	 * @return array|null
	 */
	private function find_tool( array $tools, string $name ) {
		foreach ( $tools as $tool ) {
			if ( $tool['name'] === $name ) {
				return $tool;
			}
		}
		return null;
	}

	/**
	 * Normal mode exposes every target and treats CSS as requested.
	 */
	public function test_resolve_edit_policy_normal_mode(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'normal', 'make the background red' );
		$this->assertSame( array( 'html', 'head', 'css', 'js' ), $policy['editableTargets'] );
		$this->assertTrue( $policy['cssExplicitlyRequested'] );
	}

	/**
	 * Tailwind mode without CSS intent excludes the CSS target.
	 */
	public function test_resolve_edit_policy_tailwind_without_css_intent(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'make the hero bigger' );
		$this->assertSame( array( 'html', 'head', 'js' ), $policy['editableTargets'] );
		$this->assertFalse( $policy['cssExplicitlyRequested'] );
	}

	/**
	 * Tailwind mode with explicit CSS intent unlocks the CSS target.
	 */
	public function test_resolve_edit_policy_tailwind_with_css_intent(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'edit the stylesheet spacing' );
		$this->assertSame( array( 'html', 'head', 'css', 'js' ), $policy['editableTargets'] );
		$this->assertTrue( $policy['cssExplicitlyRequested'] );
	}

	/**
	 * Explicit CSS intent is detected for English and Japanese keywords.
	 */
	public function test_has_explicit_css_edit_intent(): void {
		$this->assertTrue( Ai_Tool_Schema::has_explicit_css_edit_intent( 'Update the CSS grid' ) );
		$this->assertTrue( Ai_Tool_Schema::has_explicit_css_edit_intent( '@layer utilities tweak' ) );
		$this->assertTrue( Ai_Tool_Schema::has_explicit_css_edit_intent( 'スタイルシートを直して' ) );
		$this->assertFalse( Ai_Tool_Schema::has_explicit_css_edit_intent( 'make the button rounder' ) );
	}

	/**
	 * The default tool set exposes the six editing tools only.
	 */
	public function test_build_tool_definitions_default_set(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css', 'js' ) );
		$this->assertSame(
			array( 'search_text', 'read_document', 'read_selection', 'replace_string', 'replace_many', 'set_js_mode' ),
			$this->tool_names( $tools )
		);
		$selected = $this->find_tool( $tools, 'read_selection' );
		$this->assertContains( 'selectionId', $selected['parameters']['required'] );
	}

	/**
	 * History tools are appended only when requested.
	 */
	public function test_build_tool_definitions_with_history_tools(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css', 'js' ), true );
		$names = $this->tool_names( $tools );
		$this->assertContains( 'list_ai_edits', $names );
		$this->assertContains( 'get_ai_edit', $names );
		$this->assertCount( 8, $tools );
	}

	/**
	 * The replace tools advertise exactly the editable targets in their enum.
	 */
	public function test_editable_targets_flow_into_replace_enums(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'js' ) );

		$replace_string = $this->find_tool( $tools, 'replace_string' );
		$this->assertSame(
			array( 'html', 'head', 'js' ),
			$replace_string['parameters']['properties']['target']['enum']
		);

		$replace_many = $this->find_tool( $tools, 'replace_many' );
		$this->assertSame(
			array( 'html', 'head', 'js' ),
			$replace_many['parameters']['properties']['target']['enum']
		);
	}
}
