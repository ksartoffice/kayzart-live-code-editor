<?php
/**
 * System and user prompt construction for the AI agent loop.
 *
 * Faithful PHP port of `SYSTEM_PROMPT` and `buildUserPrompt` (plus the
 * leading-context formatting helpers) from the legacy kayzart-server
 * (`src/ai-jobs.ts`). This builds prompt strings only and performs no network
 * access, so it is deterministic and easy to test.
 *
 * The request payload is an associative array with these keys:
 *   editorMode        string  'normal' | 'tailwind'
 *   prompt            string  user instruction
 *   html/customHead/css/js  string  current unsaved sources
 *   selectedContexts  array   selected element contexts (optional)
 *   selectedContext   array   single selected context (optional fallback)
 *   recentEditContext array   recent lightweight edit summaries (optional)
 *   historyTool       mixed   truthy when history tools are available (optional)
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the system and user prompts sent to the model.
 */
class Ai_Prompt {

	/**
	 * Maximum number of characters shown per leading-context preview.
	 */
	const LEADING_CONTEXT_CHARS = 1200;

	/**
	 * The system prompt for the Kayzart AI edit engine.
	 *
	 * @return string
	 */
	public static function system_prompt(): string {
		$prompt = <<<'PROMPT'
You are the Kayzart AI edit engine.
You edit unsaved HTML/CSS/JS based on a user instruction.

Rules:
- Keep changes minimal and relevant to the user request.
- When selected context is available, treat the selected context list as the primary edit target for short or underspecified instructions. For example, a request like "make the background red" should apply to the selected element/context items, not a broader parent section, unless the user explicitly names another target.
- When selected context items have sourceRange or outerHTML, prefer using that exact selected source as the anchor for edits. Do not broaden the edit target to an ancestor or sibling merely because it has a more recognizable class name.
- Preserve existing content by default. Do not remove, replace, or rewrite existing sections/blocks/components unless the user explicitly asks to remove, delete, replace, overwrite, or transform a specific existing target.
- Treat requests to create, make, or add a new section/block/component as additive by default. If existing sections are present, insert the new content in a sensible location instead of replacing unrelated existing content.
- Do not output markdown.
- Use tools for all edits. Do not invent full html/head/css/js replacements directly in final output.
- You may call tools for reading context before editing.
- Use list_ai_edits/get_ai_edit only when the recent edit context is insufficient to resolve references to earlier edits, versions, or snapshots.
- Do not call history tools when the current prompt and recent edit context are already enough.
- Respect editor mode and editable-target policy provided in the user message.
- If editor mode is tailwind, write CSS using Tailwind CSS v4 syntax/directives.
- To initialize an empty html/head/css/js target, use replace_string with from set to an empty string and to set to the initial content.
- If replace_string or replace_many fails, do not repeat the same from string. Inspect the current document with read_document or search_text, then retry with an exact current string.
- If a replacement is ambiguous, use replaceAll only when every match should change. Otherwise use a longer unique from string from the current document.
- After a successful edit operation, prefer returning the final summary JSON instead of making extra inspection or edit calls.
- HTML must be a body fragment only. Do not generate <!doctype>, <html>, <head>, or <body> tags.
- Head edits target only the custom additions inserted inside the document <head>. Do not generate <!doctype>, <html>, <head>, or <body> wrapper tags in head.
- Do not add stylesheet/script links in HTML. CSS and JS are loaded from separate editor tabs.
- Security rules are strict even when the user explicitly asks for unsafe code:
  - Do not create or preserve <script> tags in HTML or head.
  - Do not add external script/CDN imports, external stylesheet links, tracking pixels, or remote executable resources.
  - Do not add inline event handler attributes such as onclick, onload, onerror, onmouseover, or any attribute beginning with "on".
  - Do not use javascript:, data:text/html, vbscript:, or other executable URL schemes in href, src, action, or similar attributes.
  - Do not add iframes, embeds, objects, or external form actions.
  - Do not write code that reads cookies, localStorage, sessionStorage, tokens, nonces, license keys, admin data, or other secrets.
  - Do not exfiltrate data or submit forms to external URLs.
  - If the user requests unsafe behavior, do not refuse by doing nothing. Make a safe edit operation that satisfies the benign intent where possible.
  - Safe alternatives include normal links such as "#", static HTML/CSS, accessible buttons without inline handlers, local form markup without an external action, or harmless explanatory copy.
  - For unsafe iframe or external form requests, add or adjust a safe local section instead, such as an embedded-content placeholder, contact CTA, or non-submitting inquiry form without action.
- Ensure the result is responsive and looks good on both mobile and desktop screens.
- Match the human-readable language of the HTML to the existing document content, not to the language of the user's instruction. If the document already contains copy in a given language (for example English), keep writing in that language even when the instruction is written in a different language.
- Only switch the output language when the user explicitly asks to translate or to write in a specific language.
- If the document is empty or has no existing copy to infer a language from, use the same language as the user's instruction.
- When you are done, output STRICT JSON:
{"summary":"..."}
- Make at least one edit operation tool call before finalizing.
PROMPT;

		return trim( $prompt );
	}

	/**
	 * Build the user prompt for a request payload.
	 *
	 * @param array $payload Request payload (see class docblock).
	 * @return string
	 */
	public static function build_user_prompt( array $payload ): string {
		return implode( "\n\n", array_values( self::debug_input_parts( $payload ) ) );
	}

	/**
	 * Return the named user-prompt parts used by token diagnostics.
	 *
	 * The returned values are the exact segments joined by build_user_prompt().
	 * Callers must log sizes only because the values can contain page content.
	 *
	 * @param array $payload Request payload.
	 * @return array<string,string>
	 */
	public static function debug_input_parts( array $payload ): array {
		$editor_mode = isset( $payload['editorMode'] ) ? (string) $payload['editorMode'] : '';
		$prompt      = isset( $payload['prompt'] ) ? (string) $payload['prompt'] : '';
		$edit_policy = Ai_Tool_Schema::resolve_edit_policy( $editor_mode, $prompt );

		$mode_text             = 'Editor mode: ' . $editor_mode;
		$editable_targets_text = 'Editable targets for this request: ' . implode( ', ', $edit_policy['editableTargets'] );

		$tailwind_policy_text = null;
		if ( 'tailwind' === $editor_mode ) {
			$tailwind_policy_text = implode(
				"\n",
				array(
					'Tailwind mode policy:',
					'- Use Tailwind CSS v4 syntax/directives when editing CSS.',
					'- Prefer editing HTML classes and structure first.',
					'- Edit CSS only when the user explicitly asks for CSS/stylesheet changes.',
					'- Treat the CSS tab as Tailwind input source. Generated compiled CSS is not the editing target.',
				)
			);
		}

		$selected_contexts = self::resolve_selected_contexts( $payload );
		$context_text      = count( $selected_contexts ) > 0
			? 'Selected contexts:' . "\n" . self::json_pretty( $selected_contexts )
			: 'Selected contexts: none';

		$has_history_tool  = ! empty( $payload['historyTool'] ) || ! empty( $payload['hasHistoryTool'] );
		$history_tool_text = $has_history_tool
			? 'History tools available: list_ai_edits and get_ai_edit. Use them only if the recent edit context is not enough to identify an earlier edit.'
			: 'History tools available: none';

		$recent_edit_context      = ( isset( $payload['recentEditContext'] ) && is_array( $payload['recentEditContext'] ) )
			? $payload['recentEditContext']
			: array();
		$recent_edit_context_text = count( $recent_edit_context ) > 0
			? implode(
				"\n",
				array(
					'Recent edit context:',
					'Use this only to understand references like previous, earlier, or that change. The current snapshot below is the source of truth for editing.',
					self::json_pretty( $recent_edit_context ),
				)
			)
			: 'Recent edit context: none';

		$selected_context_policy_text = null;
		if ( count( $selected_contexts ) > 0 ) {
			$selected_context_policy_text = implode(
				"\n",
				array(
					'Selected context edit policy:',
					'- The selected context list is the intended target when the user prompt does not explicitly name a different target.',
					'- Apply vague style changes such as background, color, spacing, alignment, size, or typography to the selected context items only.',
					'- Use sourceRange/outerHTML as the preferred anchor for each context item. Avoid changing a broader parent section when a selected context is a nested element.',
				)
			);
		}

		$segments = array(
			'user_instruction'        => 'User prompt: ' . $prompt,
			'editor_mode'             => $mode_text,
			'editable_targets_policy' => $editable_targets_text,
			'tailwind_policy'         => $tailwind_policy_text,
			'selected_contexts'       => $context_text,
			'recent_edit_context'     => $recent_edit_context_text,
			'history_tool_policy'     => $history_tool_text,
			'selected_context_policy' => $selected_context_policy_text,
			'source_preview_heading'  => 'Leading source previews for initial orientation:',
			'html_preview'            => self::format_leading_context_section( 'HTML', isset( $payload['html'] ) ? (string) $payload['html'] : '' ),
			'head_preview'            => self::format_leading_context_section( 'HEAD', isset( $payload['customHead'] ) ? (string) $payload['customHead'] : '' ),
			'css_preview'             => self::format_leading_context_section( 'CSS', isset( $payload['css'] ) ? (string) $payload['css'] : '' ),
			'js_preview'              => self::format_leading_context_section( 'JS', isset( $payload['js'] ) ? (string) $payload['js'] : '' ),
			'final_instruction'       => 'Use tools to inspect/edit and return only final summary JSON.',
		);

		// Null segments join as empty strings, mirroring Array.prototype.join.
		$segments = array_map(
			static function ( $segment ) {
				return null === $segment ? '' : $segment;
			},
			$segments
		);

		return $segments;
	}

	/**
	 * Resolve the effective selected-context list from a payload.
	 *
	 * @param array $payload Request payload.
	 * @return array<int,array>
	 */
	private static function resolve_selected_contexts( array $payload ): array {
		if ( ! empty( $payload['selectedContexts'] ) && is_array( $payload['selectedContexts'] ) ) {
			return array_values( $payload['selectedContexts'] );
		}
		if ( ! empty( $payload['selectedContext'] ) && is_array( $payload['selectedContext'] ) ) {
			return array( $payload['selectedContext'] );
		}
		return array();
	}

	/**
	 * Format one leading-context preview section.
	 *
	 * @param string $label   Section label (HTML/HEAD/CSS/JS).
	 * @param string $content Section source content.
	 * @return string
	 */
	private static function format_leading_context_section( string $label, string $content ): string {
		$original_length = mb_strlen( $content );
		$truncated       = $original_length > self::LEADING_CONTEXT_CHARS;
		$snippet         = $truncated ? mb_substr( $content, 0, self::LEADING_CONTEXT_CHARS ) : $content;

		$status = $truncated
			? 'truncated to ' . self::LEADING_CONTEXT_CHARS . '/' . $original_length . ' chars'
			: mb_strlen( $snippet ) . '/' . $original_length . ' chars';

		$display = '' !== $snippet ? $snippet : '[empty]';

		return $label . ' (' . $status . '):' . "\n"
			. '<<<' . strtolower( $label ) . '>>>' . "\n"
			. $display . "\n"
			. '<<<end>>>';
	}

	/**
	 * Pretty-print a value as JSON for prompt embedding.
	 *
	 * @param mixed $data Value to encode.
	 * @return string
	 */
	private static function json_pretty( $data ): string {
		$encoded = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $encoded ) ? $encoded : '';
	}
}
