<?php
/**
 * Unit tests for AI prompt construction.
 *
 * @package KayzArt
 */

use KayzArt\Ai_Fonts;
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
		$this->assertStringContainsString( 'call finish_edit by itself', $prompt );
		$this->assertStringContainsString( 'js source and jsMode are read-only', $prompt );
		$this->assertStringContainsString( 'call finish_without_edit', $prompt );
		$this->assertStringContainsString( 'no unresolved tool errors', $prompt );
		$this->assertStringContainsString( 'error.details.candidates', $prompt );
		$this->assertStringContainsString( 'validated editFootprint', $prompt );
		$this->assertStringContainsString( 'must not be broadened to :root', $prompt );
		$this->assertStringContainsString( '{"summary":"..."}', $prompt );
		// trim() removes the leading/trailing blank lines from the source block.
		$this->assertSame( trim( $prompt ), $prompt );
	}

	/**
	 * The edit prompt is unchanged whether or not the intent is passed.
	 */
	public function test_system_prompt_defaults_to_the_editing_intent(): void {
		$this->assertSame( Ai_Prompt::system_prompt(), Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT ) );
		$this->assertStringContainsString( 'Keep changes minimal and relevant to the user request.', Ai_Prompt::system_prompt() );
		$this->assertStringContainsString( 'Search first and read only the smallest relevant range.', Ai_Prompt::system_prompt() );
	}

	/**
	 * Line endings are normalized so a CRLF checkout sends the same bytes.
	 */
	public function test_system_prompt_uses_normalized_line_endings(): void {
		$this->assertStringNotContainsString( "\r", Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT ) );
		$this->assertStringNotContainsString( "\r", Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE ) );
	}

	/**
	 * The creation prompt drops the minimal-diff rules and asks for a whole page.
	 */
	public function test_system_prompt_creation_intent(): void {
		$prompt = Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE );

		$this->assertStringContainsString( 'You are the Kayzart AI page generation engine.', $prompt );
		$this->assertStringContainsString( 'Build one complete, publishable landing page', $prompt );
		$this->assertStringContainsString( 'Compose the page from multiple distinct sections.', $prompt );
		$this->assertStringContainsString( 'Do not invent prices, results, testimonials', $prompt );

		$this->assertStringNotContainsString( 'Keep changes minimal and relevant to the user request.', $prompt );
		$this->assertStringNotContainsString( 'Search first and read only the smallest relevant range.', $prompt );
		$this->assertStringNotContainsString( 'Preserve existing content by default.', $prompt );
		$this->assertStringNotContainsString( 'validated editFootprint', $prompt );
		$this->assertStringNotContainsString( 'list_ai_edits', $prompt );
		$this->assertSame( trim( $prompt ), $prompt );
	}

	/**
	 * Security and output constraints are shared by both intents.
	 */
	public function test_system_prompt_shares_security_and_output_rules(): void {
		$shared = array(
			'Do not create or preserve <script> tags',
			'Do not exfiltrate data or submit forms to external URLs.',
			'HTML must be a body fragment only.',
			'Ensure the result is responsive and looks good on both mobile and desktop screens.',
			'{"summary":"..."}',
		);
		foreach ( $shared as $rule ) {
			$this->assertStringContainsString( $rule, Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT ), $rule );
			$this->assertStringContainsString( $rule, Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE ), $rule );
		}
	}

	/**
	 * Only an explicit create intent switches the prompt away from editing.
	 */
	public function test_resolve_intent(): void {
		$this->assertSame( Ai_Prompt::INTENT_CREATE, Ai_Prompt::resolve_intent( array( 'intent' => 'create' ) ) );
		$this->assertSame( Ai_Prompt::INTENT_EDIT, Ai_Prompt::resolve_intent( array( 'intent' => 'edit' ) ) );
		$this->assertSame( Ai_Prompt::INTENT_EDIT, Ai_Prompt::resolve_intent( array( 'intent' => 'anything-else' ) ) );
		$this->assertSame( Ai_Prompt::INTENT_EDIT, Ai_Prompt::resolve_intent( array() ) );
	}

	/**
	 * The user prompt echoes the instruction, mode and editable targets.
	 */
	public function test_build_user_prompt_normal_mode(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'normal',
				'prompt'      => 'make the heading blue',
				'canEditHead' => true,
				'html'        => '<h1>Hi</h1>',
				'customHead'  => '',
				'css'         => '',
				'js'          => '',
			)
		);

		$this->assertStringContainsString( 'User prompt: make the heading blue', $prompt );
		$this->assertStringContainsString( 'Editor mode: normal', $prompt );
		$this->assertStringContainsString( 'Editable targets for this request: html, head, css', $prompt );
		$this->assertStringNotContainsString( 'Selected contexts:', $prompt );
		$this->assertStringNotContainsString( 'Recent edit context:', $prompt );
		$this->assertStringNotContainsString( 'History tools available:', $prompt );
		$this->assertStringNotContainsString( 'Tailwind mode policy:', $prompt );
	}

	/**
	 * Tailwind mode without CSS intent trims CSS while policy stays in system.
	 */
	public function test_build_user_prompt_tailwind_mode(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'tailwind',
				'prompt'      => 'make the hero taller',
				'canEditHead' => true,
				'html'        => '<section></section>',
			)
		);

		$this->assertStringContainsString( 'Editor mode: tailwind', $prompt );
		$this->assertStringContainsString( 'Editable targets for this request: html, head', $prompt );
		$this->assertStringNotContainsString( 'Tailwind mode rules:', $prompt );
		$this->assertStringContainsString( 'Tailwind mode rules:', Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT, 'tailwind' ) );
	}

	/**
	 * Creation policy stays in the creation system prompt, not request data.
	 */
	public function test_build_user_prompt_creation_policy(): void {
		$payload = array(
			'editorMode'  => 'normal',
			'prompt'      => 'A landing page for a bakery.',
			'canEditHead' => true,
		);

		$editing = Ai_Prompt::build_user_prompt( $payload );
		$this->assertStringNotContainsString( 'Build one complete, publishable landing page', $editing );
		$payload['intent'] = 'create';
		$creating          = Ai_Prompt::build_user_prompt( $payload );
		$this->assertStringNotContainsString( 'Page creation policy:', $creating );
		$this->assertStringContainsString( 'Build one complete, publishable landing page', Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE ) );
	}

	/**
	 * Tailwind creation unlocks CSS and asks for theme tokens instead of gating it.
	 */
	public function test_build_user_prompt_tailwind_creation_unlocks_css(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'tailwind',
				'prompt'      => 'A landing page for a bakery.',
				'canEditHead' => true,
				'intent'      => 'create',
			)
		);

		$this->assertStringContainsString( 'Editable targets for this request: html, head, css', $prompt );
		$system = Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE, 'tailwind' );
		$this->assertStringContainsString( 'Define the page theme in the CSS tab with @theme', $system );
		$this->assertStringNotContainsString( 'Edit CSS only when the user explicitly asks', $prompt );
	}

	/**
	 * Registered families are offered alongside the always-available stacks.
	 */
	public function test_build_user_prompt_lists_registered_fonts(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'     => 'normal',
				'prompt'         => 'A landing page for a bakery.',
				'intent'         => 'create',
				'availableFonts' => array(
					'registered'   => array(
						array(
							'name'       => 'Test Sans JP',
							'fontFamily' => '"Test Sans JP", sans-serif',
						),
					),
					'systemStacks' => Ai_Fonts::SYSTEM_STACKS,
				),
			)
		);

		$this->assertStringContainsString( 'Fonts available for this page:', $prompt );
		$this->assertStringContainsString( 'Test Sans JP -> font-family: "Test Sans JP", sans-serif', $prompt );
		$this->assertStringContainsString( 'Never add a stylesheet link, @import, or @font-face', $prompt );
	}

	/**
	 * Without site fonts the prompt still offers every system stack.
	 */
	public function test_build_user_prompt_falls_back_to_system_stacks(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'normal',
				'prompt'     => 'A landing page for a bakery.',
				'intent'     => 'create',
			)
		);

		$this->assertStringContainsString( 'Registered on this site: none.', $prompt );
		foreach ( array_keys( Ai_Fonts::SYSTEM_STACKS ) as $name ) {
			$this->assertStringContainsString( $name . ' -> font-family:', $prompt );
		}
	}

	/**
	 * Font guidance is inline for creation and tool-backed for editing.
	 */
	public function test_build_user_prompt_fonts_policy_applies_to_creation_only(): void {
		$payload = array(
			'editorMode' => 'normal',
			'prompt'     => 'Make the headings mincho.',
		);

		$this->assertStringNotContainsString( 'Fonts available for this page:', Ai_Prompt::build_user_prompt( $payload ) );
		$payload['intent'] = 'create';
		$this->assertStringContainsString( 'Fonts available for this page:', Ai_Prompt::build_user_prompt( $payload ) );
	}

	/**
	 * Without unfiltered_html the save strips markup after the job has already
	 * reported success, so the model has to know before it writes.
	 */
	public function test_build_user_prompt_warns_about_filtered_markup(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'tailwind',
				'prompt'      => 'Add feature icons to the hero.',
				'canEditHead' => false,
			)
		);

		$this->assertStringContainsString( 'Markup restrictions for this request:', $prompt );
		$this->assertStringContainsString( '<svg> and its children are deleted entirely', $prompt );
		$this->assertStringContainsString( 'the CSS becomes visible body text on the page', $prompt );
		$this->assertStringContainsString( '<picture> and <source> are dropped', $prompt );
		$this->assertStringContainsString( '<template> is unwrapped', $prompt );
	}

	/**
	 * A user whose HTML is saved unfiltered must not be constrained.
	 */
	public function test_build_user_prompt_omits_markup_policy_for_unfiltered_users(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'  => 'tailwind',
				'prompt'      => 'Add feature icons to the hero.',
				'canEditHead' => true,
			)
		);

		$this->assertStringNotContainsString( 'Markup restrictions for this request:', $prompt );
	}

	/**
	 * Payloads stored before canEditHead existed get no policy. This is
	 * deliberately the opposite default from resolve_edit_policy(), which fails
	 * closed: guessing wrong here would tell an administrator to avoid SVG.
	 */
	public function test_build_user_prompt_omits_markup_policy_for_legacy_payloads(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'tailwind',
				'prompt'     => 'Add feature icons to the hero.',
			)
		);

		$this->assertStringNotContainsString( 'Markup restrictions for this request:', $prompt );
	}

	/**
	 * The system prompt points at the user-message section so the two are not
	 * read as unrelated.
	 */
	public function test_system_prompt_defers_to_the_markup_restrictions(): void {
		foreach ( array( Ai_Prompt::INTENT_EDIT, Ai_Prompt::INTENT_CREATE ) as $intent ) {
			$this->assertStringContainsString(
				'any markup restrictions provided in the user message',
				Ai_Prompt::system_prompt( $intent )
			);
		}
	}

	/**
	 * Losing the entry import produces no compiler error at all, only an
	 * unstyled page, so the policy states it in both create and edit wording.
	 */
	public function test_build_user_prompt_tailwind_policy_pins_the_entry_import(): void {
		$expected = '- The CSS must always keep its `@import "tailwindcss";` line.';

		$this->assertStringContainsString(
			$expected,
			Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT, 'tailwind' )
		);

		$this->assertStringContainsString(
			$expected,
			Ai_Prompt::system_prompt( Ai_Prompt::INTENT_CREATE, 'tailwind' )
		);

		$this->assertStringNotContainsString(
			$expected,
			Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT, 'normal' )
		);
	}

	/**
	 * The stack labels read like usable values, and a model that copies one
	 * ships `font-family: gothic`, which resolves to nothing. The policy has to
	 * say the label is not the value.
	 */
	public function test_build_user_prompt_fonts_policy_separates_labels_from_values(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'tailwind',
				'prompt'     => 'A landing page for an apple grower.',
				'intent'     => 'create',
			)
		);

		$this->assertStringContainsString( 'The label is only a name for choosing; the value is the CSS.', $prompt );
		$this->assertStringContainsString( 'Always write the value verbatim.', $prompt );
		$this->assertStringContainsString( '`gothic`', $prompt );
		$this->assertStringContainsString( '--font-* theme token', $prompt );
		$this->assertStringContainsString( "Then write that stack's value, not its label.", $prompt );
	}

	/**
	 * Selected contexts add request data while their policy stays in system.
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
		$this->assertStringNotContainsString( 'Selected context edit policy:', $prompt );
		$this->assertStringContainsString( 'treat the selected context list as the primary edit target', Ai_Prompt::system_prompt() );
		$this->assertStringNotContainsString( 'Selected contexts: none', $prompt );
	}

	/**
	 * History availability is represented by tool declarations, not user text.
	 */
	public function test_build_user_prompt_history_tool_available(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode' => 'normal',
				'prompt'     => 'restore previous change',
			)
		);
		$this->assertStringNotContainsString( 'History tools available:', $prompt );
		$this->assertStringContainsString( 'Use list_ai_edits/get_ai_edit only', Ai_Prompt::system_prompt() );
	}

	/** Normal mode joins system sections without a blank placeholder section. */
	public function test_system_prompt_normal_mode_has_no_empty_mode_section(): void {
		$this->assertStringNotContainsString( "\n\n- Security rules", Ai_Prompt::system_prompt( Ai_Prompt::INTENT_EDIT, 'normal' ) );
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

	/**
	 * Diagnostic parts reassemble to the exact prompt sent to the provider.
	 */
	public function test_debug_input_parts_match_user_prompt(): void {
		$payload = array(
			'editorMode' => 'normal',
			'prompt'     => 'make it blue',
			'html'       => '<h1>Hello</h1>',
			'css'        => 'h1 { color: red; }',
		);
		$this->assertSame(
			Ai_Prompt::build_user_prompt( $payload ),
			implode( "\n\n", array_values( Ai_Prompt::debug_input_parts( $payload ) ) )
		);
	}

	/** A validated footprint is included with implicit-follow-up guidance. */
	public function test_recent_edit_footprint_is_rendered_in_user_prompt(): void {
		$prompt = Ai_Prompt::build_user_prompt(
			array(
				'editorMode'        => 'normal',
				'prompt'            => 'Make it a little calmer.',
				'recentEditContext' => array(
					array(
						'prompt'        => 'Make the main button green.',
						'editFootprint' => array(
							'validation' => 'snapshot_hash',
							'changes'    => array(
								array(
									'target' => 'css',
									'before' => 'background: var(--blue);',
									'after'  => 'background: #16a34a;',
								),
							),
						),
					),
				),
			)
		);
		$this->assertStringContainsString( 'implicit follow-ups', $prompt );
		$this->assertStringContainsString( '"editFootprint"', $prompt );
		$this->assertStringContainsString( 'background: #16a34a;', $prompt );
	}
}
