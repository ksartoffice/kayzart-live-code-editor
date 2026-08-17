<?php
/**
 * Tool schema definitions and edit-target policy for the AI agent loop.
 *
 * Faithful PHP port of `buildToolDefinitions`, `resolveEditPolicy` and
 * `hasExplicitCssEditIntent` from the legacy kayzart-server (`src/ai-jobs.ts`).
 *
 * The tool definitions are returned in a provider-agnostic array form
 * (`type`/`name`/`description`/`parameters`). The AI client wrapper converts
 * them into the concrete `FunctionDeclaration` objects the SDK expects.
 *
 * JSON Schema maps (`parameters`, `properties`, `items`) are represented as PHP
 * associative arrays, matching the WordPress 7.0 FunctionDeclaration API.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds AI tool schemas and resolves the editable-target policy.
 */
class Ai_Tool_Schema {

	/**
	 * Every editable target, used for the normal editor mode.
	 */
	const ALL_EDITABLE_TARGETS = array( 'html', 'head', 'css' );

	/**
	 * Resolve which targets are editable for a request.
	 *
	 * Every mode and intent gets the CSS tab. Tailwind mode used to withhold it
	 * unless the prompt matched a keyword list, to stop the model writing
	 * hand-rolled CSS instead of using utilities. That gate was aimed at the
	 * wrong thing: the problem was never access to the CSS tab but the kind of
	 * CSS written there, and withholding the target broke the legitimate use --
	 * a request like "change the button colour everywhere" is a @theme token
	 * edit and has nowhere else to go. Ai_Tailwind_Css_Policy now enforces the
	 * idiom on the written CSS itself, with a retryable message the model can
	 * act on.
	 *
	 * @param string $editor_mode   Editor mode ('normal' or 'tailwind').
	 * @param string $prompt        User prompt. Unused; kept so callers and stored jobs need no change.
	 * @param bool   $can_edit_head Whether the job creator may persist custom-head edits.
	 * @param string $intent        Ai_Prompt::INTENT_CREATE or Ai_Prompt::INTENT_EDIT.
	 * @return array{editableTargets:array<int,string>,cssExplicitlyRequested:bool}
	 */
	public static function resolve_edit_policy( string $editor_mode, string $prompt, bool $can_edit_head, string $intent = Ai_Prompt::INTENT_EDIT ): array {
		unset( $editor_mode, $prompt, $intent );

		$editable_targets = self::ALL_EDITABLE_TARGETS;
		if ( ! $can_edit_head ) {
			$editable_targets = array_values( array_diff( $editable_targets, array( 'head' ) ) );
		}

		return array(
			'editableTargets'        => $editable_targets,
			'cssExplicitlyRequested' => true,
		);
	}

	/**
	 * Build the provider-agnostic tool definitions.
	 *
	 * @param array<int,string> $editable_targets Editable target allow list.
	 * @param bool              $has_history_tool Whether to expose history tools.
	 * @param bool              $has_selection_context Whether resolvable selection context exists.
	 * @param bool              $has_font_tool Whether to expose the font catalog tool.
	 * @return array<int,array> Tool definitions.
	 */
	public static function build_tool_definitions( array $editable_targets, bool $has_history_tool = false, bool $has_selection_context = true, bool $has_font_tool = false ): array {
		$editable_target_enum = array_values( array_intersect( $editable_targets, self::ALL_EDITABLE_TARGETS ) );
		$replace_properties   = array(
			'target'     => array(
				'type' => 'string',
				'enum' => $editable_target_enum,
			),
			'from'       => array( 'type' => 'string' ),
			'to'         => array( 'type' => 'string' ),
			'replaceAll' => array( 'type' => 'boolean' ),
		);
		$many_properties      = array(
			'target'       => array(
				'type' => 'string',
				'enum' => $editable_target_enum,
			),
			'replacements' => array(
				'type'     => 'array',
				'minItems' => 1,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'from'       => array( 'type' => 'string' ),
						'to'         => array( 'type' => 'string' ),
						'replaceAll' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'from', 'to' ),
					'additionalProperties' => false,
				),
			),
		);
		if ( $has_selection_context ) {
			$replace_properties['selectionId'] = array( 'type' => 'string' );
			$many_properties['selectionId']    = array( 'type' => 'string' );
		}

		$tools = array(
			array(
				'type'        => 'function',
				'name'        => 'search_text',
				'description' => 'Search plain text in html/head/css/js and return compact match snippets.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'query'  => array( 'type' => 'string' ),
						'target' => array(
							'type' => 'string',
							'enum' => array( 'all', 'html', 'head', 'css', 'js' ),
						),
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'required'             => array( 'query' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'read_document',
				'description' => 'Read lines from html/head/css/js for close inspection. Omit cursor on the first read; only for continuation, copy the previous nextCursor exactly.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'target'    => array(
							'type' => 'string',
							'enum' => array( 'html', 'head', 'css', 'js' ),
						),
						'startLine' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'endLine'   => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'cursor'    => array( 'type' => 'string' ),
						'maxChars'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Ai_Tools::MAX_READ_CHARS,
						),
					),
					'required'             => array( 'target' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'replace_string',
				'description' => 'Replace one or more exact string matches in editable targets and update the working snapshot. from may be empty only when the target document is blank (for initialization).',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => $replace_properties,
					'required'             => array( 'target', 'from', 'to' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'replace_many',
				'description' => 'Apply multiple exact string replacements in order against editable targets. The same empty-from rule as replace_string applies to each step.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => $many_properties,
					'required'             => array( 'target', 'replacements' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'finish_edit',
				'description' => 'Finish the edit with a concise summary. Prefer including this with the final successful edit tools; it may also be called alone after a prior successful edit when there are no unresolved tool errors.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'summary' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 1000,
						),
					),
					'required'             => array( 'summary' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'finish_without_edit',
				'description' => 'Finish without changing the snapshot only when the request requires JavaScript/jsMode changes or another prohibited operation and no relevant safe HTML/CSS edit is possible.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'summary' => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 1000,
						),
					),
					'required'             => array( 'summary' ),
					'additionalProperties' => false,
				),
			),
		);

		if ( $has_selection_context ) {
			array_splice(
				$tools,
				2,
				0,
				array(
					array(
						'type'        => 'function',
						'name'        => 'read_selection',
						'description' => 'Read the exact current source for one selected HTML element. Omit cursor on the first read; only for continuation, copy the previous nextCursor exactly.',
						'parameters'  => array(
							'type'                 => 'object',
							'properties'           => array(
								'selectionId' => array( 'type' => 'string' ),
								'cursor'      => array( 'type' => 'string' ),
								'maxChars'    => array(
									'type'    => 'integer',
									'minimum' => 1,
									'maximum' => Ai_Tools::MAX_READ_CHARS,
								),
							),
							'required'             => array( 'selectionId' ),
							'additionalProperties' => false,
						),
					),
				)
			);
		}

		if ( $has_history_tool ) {
			$tools[] = array(
				'type'        => 'function',
				'name'        => 'list_ai_edits',
				'description' => 'List previous AI edit history summaries for this post, newest first. Omit cursor on the first page; when older results are needed, copy nextCursor exactly.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Ai_Timeline_Store::AI_HISTORY_PAGE_SIZE,
						),
						'cursor' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'type'        => 'function',
				'name'        => 'get_ai_edit',
				'description' => 'Fetch metadata for one previous AI edit. To inspect retained source, provide snapshot and target; omit cursor on the first read and copy nextCursor exactly for continuation.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'versionId' => array( 'type' => 'string' ),
						'snapshot'  => array(
							'type' => 'string',
							'enum' => array( 'before', 'after' ),
						),
						'target'    => array(
							'type' => 'string',
							'enum' => array( 'html', 'head', 'css', 'js' ),
						),
						'cursor'    => array( 'type' => 'string' ),
						'maxChars'  => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Ai_Tools::MAX_READ_CHARS,
						),
					),
					'required'             => array( 'versionId' ),
					'additionalProperties' => false,
				),
			);
		}

		if ( $has_font_tool ) {
			$tools[] = array(
				'type'        => 'function',
				'name'        => 'list_available_fonts',
				'description' => 'List font families that can render on this page. Call once before introducing or changing a font family or Tailwind --font-* token, then use a returned cssValue exactly.',
				'parameters'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
			);
		}

		return $tools;
	}
}
