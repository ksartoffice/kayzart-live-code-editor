<?php
/**
 * Unit tests for AI prompt construction.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Prompt;

/**
 * Verify the system prompt and user prompt builder.
 */
class Test_Kayzart_Ai_Prompt extends WP_UnitTestCase {

	/**
	 * The system prompt carries the engine identity and security rules.
	 */
	public function test_system_prompt_contains_core_rules(): void {
		$prompt = Ai_Prompt::system_prompt();
		$this->assertStringContainsString( 'You are the Kayzart AI edit engine.', $prompt );
		$this->assertStringContainsString( 'Do not create or preserve <script> tags', $prompt );
		$this->assertStringContainsString( '{"summary":"..."}', $prompt );
		// trim() removes the leading/trailing blank lines from the source block.
		$this->assertSame( trim( $prompt ), $prompt );
	}

	/**
	 * The user prompt echoes the instruction, mode and editable targets.
	 */
	public function test_build_user_prompt_normal_mode(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'normal',
				'prompt'     => 'make the heading blue',
				'html'       => '<h1>Hi</h1>',
				'customHead' => '',
				'css'        => '',
				'js'         => '',
			)
		);

		$this->assertStringContainsString( 'User prompt: make the heading blue', $prompt );
		$this->assertStringContainsString( 'Editor mode: normal', $prompt );
		$this->assertStringContainsString( 'Editable targets for this request: html, head, css, js', $prompt );
		$this->assertStringContainsString( 'Selected contexts: none', $prompt );
		$this->assertStringContainsString( 'Recent edit context: none', $prompt );
		$this->assertStringContainsString( 'History tools available: none', $prompt );
		$this->assertStringNotContainsString( 'Tailwind mode policy:', $prompt );
	}

	/**
	 * Tailwind mode without CSS intent adds the tailwind policy and trims CSS.
	 */
	public function test_build_user_prompt_tailwind_mode(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'tailwind',
				'prompt'     => 'make the hero taller',
				'html'       => '<section></section>',
			)
		);

		$this->assertStringContainsString( 'Editor mode: tailwind', $prompt );
		$this->assertStringContainsString( 'Editable targets for this request: html, head, js', $prompt );
		$this->assertStringContainsString( 'Tailwind mode policy:', $prompt );
	}

	/**
	 * Selected contexts add the context list and its edit policy.
	 */
	public function test_build_user_prompt_with_selected_contexts(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'       => 'normal',
				'prompt'           => 'red background',
				'selectedContexts' => array(
					array(
						'lcId'    => 'el-1',
						'tagName' => 'div',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Selected contexts:', $prompt );
		$this->assertStringContainsString( '"lcId": "el-1"', $prompt );
		$this->assertStringContainsString( 'Selected context edit policy:', $prompt );
		$this->assertStringNotContainsString( 'Selected contexts: none', $prompt );
	}

	/**
	 * History availability switches the history tool guidance.
	 */
	public function test_build_user_prompt_history_tool_available(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'normal',
				'prompt'      => 'restore previous change',
				'historyTool' => array( 'token' => 'abc' ),
			)
		);
		$this->assertStringContainsString( 'History tools available: list_ai_edits and get_ai_edit', $prompt );
	}

	/**
	 * Empty leading sources render an explicit empty marker.
	 */
	public function test_leading_context_empty_marker(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'normal',
				'prompt'     => 'noop',
			)
		);
		$this->assertStringContainsString( '<<<html>>>', $prompt );
		$this->assertStringContainsString( "<<<css>>>\n[empty]\n<<<end>>>", $prompt );
	}

	/**
	 * Oversized leading sources are truncated with a status marker.
	 */
	public function test_leading_context_truncation(): void {
		$long   = str_repeat( 'a', 1500 );
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'normal',
				'prompt'     => 'noop',
				'html'       => $long,
			)
		);
		$this->assertStringContainsString( 'truncated to 1200/1500 chars', $prompt );
	}
}
