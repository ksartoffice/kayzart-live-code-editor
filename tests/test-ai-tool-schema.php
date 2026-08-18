<?php
/**
 * Unit tests for the AI tool schema and edit-target policy.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Prompt;
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
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'normal', 'make the background red', true );
		$this->assertSame( array( 'html', 'head', 'css' ), $policy['editableTargets'] );
		$this->assertTrue( $policy['cssExplicitlyRequested'] );
	}

	/**
	 * Tailwind mode gets the CSS target whatever the prompt says.
	 *
	 * The keyword gate this replaces cost the model the @theme block, which is
	 * the only place a site-wide value can be changed. The system prompt says
	 * what belongs there instead.
	 */
	public function test_resolve_edit_policy_tailwind_always_unlocks_css(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'make the hero bigger', true );
		$this->assertSame( array( 'html', 'head', 'css' ), $policy['editableTargets'] );
		$this->assertTrue( $policy['cssExplicitlyRequested'] );

		$themed = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'ボタンの色を全体的に変えて', true );
		$this->assertSame( array( 'html', 'head', 'css' ), $themed['editableTargets'] );
		$this->assertTrue( $themed['cssExplicitlyRequested'] );
	}

	/** Users who cannot persist custom-head content must never receive head tools. */
	public function test_resolve_edit_policy_excludes_head_without_permission(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'normal', 'add a meta tag', false );
		$this->assertSame( array( 'html', 'css' ), $policy['editableTargets'] );

		$tools = Ai_Tool_Schema::build_tool_definitions( $policy['editableTargets'] );
		$this->assertNotContains( 'head', $this->find_tool( $tools, 'replace_string' )['parameters']['properties']['target']['enum'] );
		$this->assertNotContains( 'head', $this->find_tool( $tools, 'replace_many' )['parameters']['properties']['target']['enum'] );

		$tailwind_policy = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'make the hero bigger', false );
		$this->assertSame( array( 'html', 'css' ), $tailwind_policy['editableTargets'] );
	}

	/** Creating a page needs CSS in tailwind mode, where the theme tokens live. */
	public function test_resolve_edit_policy_create_intent_always_unlocks_css(): void {
		$policy = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'A landing page for a bakery.', true, Ai_Prompt::INTENT_CREATE );
		$this->assertSame( array( 'html', 'head', 'css' ), $policy['editableTargets'] );
		$this->assertTrue( $policy['cssExplicitlyRequested'] );

		$without_head = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'A landing page for a bakery.', false, Ai_Prompt::INTENT_CREATE );
		$this->assertSame( array( 'html', 'css' ), $without_head['editableTargets'] );
	}

	/** Both intents resolve the same targets now that the keyword gate is gone. */
	public function test_resolve_edit_policy_matches_across_intents(): void {
		$edit   = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'make the hero bigger', true, Ai_Prompt::INTENT_EDIT );
		$create = Ai_Tool_Schema::resolve_edit_policy( 'tailwind', 'make the hero bigger', true, Ai_Prompt::INTENT_CREATE );
		$this->assertSame( $create['editableTargets'], $edit['editableTargets'] );
	}

	/**
	 * The default remains compatible with callers that predate dynamic context.
	 */
	public function test_build_tool_definitions_default_set(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css', 'js' ) );
		$this->assertSame(
			array( 'search_text', 'read_document', 'read_selection', 'replace_string', 'replace_many', 'finish_edit', 'finish_without_edit' ),
			$this->tool_names( $tools )
		);
		$this->assertNotNull( $this->find_tool( $tools, 'read_selection' ) );
		$this->assertContains( 'js', $this->find_tool( $tools, 'read_document' )['parameters']['properties']['target']['enum'] );
		$this->assertArrayHasKey( 'selectionId', $this->find_tool( $tools, 'replace_string' )['parameters']['properties'] );
		$this->assertArrayHasKey( 'selectionId', $this->find_tool( $tools, 'replace_many' )['parameters']['properties'] );
	}

	/**
	 * The description is what the model reads while choosing this tool, so it
	 * carries the reason to send the finish with the edits rather than after
	 * them. It used to say "final successful edit tools", which taught the
	 * opposite: an edit is only known to be successful once its result is back,
	 * so the model spent a turn every run waiting to find out.
	 */
	public function test_finish_edit_description_argues_for_the_same_turn(): void {
		$tools       = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css' ) );
		$description = $this->find_tool( $tools, 'finish_edit' )['description'];

		$this->assertStringContainsString( 'Send it in the same response as your final edit tools', $description );
		$this->assertStringContainsString( 'rejected with a retryable error and claims nothing', $description );
		$this->assertStringContainsString( 'waiting a turn to see the result gains you nothing', $description );
		$this->assertStringContainsString( 'Call it alone only to finish work an earlier turn already applied.', $description );
		$this->assertStringNotContainsString( 'successful edit tools', $description );
	}

	/** Selection-specific tools and arguments are omitted when unavailable. */
	public function test_build_tool_definitions_without_selection_context(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css', 'js' ), false, false );
		$this->assertNull( $this->find_tool( $tools, 'read_selection' ) );
		$this->assertArrayNotHasKey( 'selectionId', $this->find_tool( $tools, 'replace_string' )['parameters']['properties'] );
		$this->assertArrayNotHasKey( 'selectionId', $this->find_tool( $tools, 'replace_many' )['parameters']['properties'] );
	}

	/**
	 * History tools are appended only when requested.
	 */
	public function test_build_tool_definitions_with_history_tools(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'css', 'js' ), true );
		$names = $this->tool_names( $tools );
		$this->assertContains( 'list_ai_edits', $names );
		$this->assertContains( 'get_ai_edit', $names );
		$this->assertCount( 9, $tools );
		$list = $this->find_tool( $tools, 'list_ai_edits' );
		$this->assertSame( 10, $list['parameters']['properties']['limit']['maximum'] );
		$this->assertArrayHasKey( 'cursor', $list['parameters']['properties'] );
		$get = $this->find_tool( $tools, 'get_ai_edit' );
		$this->assertArrayHasKey( 'snapshot', $get['parameters']['properties'] );
		$this->assertArrayHasKey( 'target', $get['parameters']['properties'] );
		$this->assertArrayHasKey( 'cursor', $get['parameters']['properties'] );
	}

	/** Font discovery is an independent optional read-only tool. */
	public function test_build_tool_definitions_with_font_tool(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'css' ), false, false, true );
		$font  = $this->find_tool( $tools, 'list_available_fonts' );
		$this->assertNotNull( $font );
		$this->assertArrayNotHasKey( 'properties', $font['parameters'] );
		$this->assertSame( '{"type":"object","additionalProperties":false}', wp_json_encode( $font['parameters'] ) );
		$this->assertStringContainsString( 'cssValue', $font['description'] );
	}

	/**
	 * The replace tools advertise exactly the editable targets in their enum.
	 */
	public function test_editable_targets_flow_into_replace_enums(): void {
		$tools = Ai_Tool_Schema::build_tool_definitions( array( 'html', 'head', 'js' ) );

		$replace_string = $this->find_tool( $tools, 'replace_string' );
		$this->assertSame(
			array( 'html', 'head' ),
			$replace_string['parameters']['properties']['target']['enum']
		);

		$replace_many = $this->find_tool( $tools, 'replace_many' );
		$this->assertSame(
			array( 'html', 'head' ),
			$replace_many['parameters']['properties']['target']['enum']
		);
	}
}
