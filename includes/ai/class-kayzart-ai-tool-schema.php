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
	const ALL_EDITABLE_TARGETS = array( 'html', 'head', 'css', 'js' );

	/**
	 * Default editable targets for tailwind mode when CSS is not requested.
	 */
	const TAILWIND_DEFAULT_EDITABLE_TARGETS = array( 'html', 'head', 'js' );

	/**
	 * Keywords signalling that the user explicitly wants CSS-tab edits.
	 *
	 * @var array<int,string>
	 */
	const CSS_EXPLICIT_INTENT_KEYWORDS = array(
		'css',
		'stylesheet',
		'@apply',
		'@theme',
		'@layer',
		'@utility',
		'@variant',
		'@plugin',
		'@config',
		'@source',
		'@reference',
		'スタイルシート',
		'cssタブ',
	);

	/**
	 * Whether a prompt explicitly asks for CSS-tab edits.
	 *
	 * @param string $prompt User prompt.
	 * @return bool
	 */
	public static function has_explicit_css_edit_intent( string $prompt ): bool {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt ) : strtolower( $prompt );
		foreach ( self::CSS_EXPLICIT_INTENT_KEYWORDS as $keyword ) {
			if ( false !== strpos( $lower, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Resolve which targets are editable for a request.
	 *
	 * @param string $editor_mode Editor mode ('normal' or 'tailwind').
	 * @param string $prompt      User prompt.
	 * @return array{editableTargets:array<int,string>,cssExplicitlyRequested:bool}
	 */
	public static function resolve_edit_policy( string $editor_mode, string $prompt ): array {
		if ( 'normal' === $editor_mode ) {
			return array(
				'editableTargets'        => self::ALL_EDITABLE_TARGETS,
				'cssExplicitlyRequested' => true,
			);
		}

		$css_explicitly_requested = self::has_explicit_css_edit_intent( $prompt );
		return array(
			'editableTargets'        => $css_explicitly_requested
				? self::ALL_EDITABLE_TARGETS
				: self::TAILWIND_DEFAULT_EDITABLE_TARGETS,
			'cssExplicitlyRequested' => $css_explicitly_requested,
		);
	}

	/**
	 * Build the provider-agnostic tool definitions.
	 *
	 * @param array<int,string> $editable_targets Editable target allow list.
	 * @param bool              $has_history_tool Whether to expose history tools.
	 * @return array<int,array> Tool definitions.
	 */
	public static function build_tool_definitions( array $editable_targets, bool $has_history_tool = false ): array {
		$editable_target_enum = array_values( $editable_targets );

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
				'description' => 'Read lines from html/head/css/js for close inspection.',
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
					),
					'required'             => array( 'target' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'get_selected_context',
				'description' => 'Return selected element context list from the editor if available.',
				'parameters'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'replace_string',
				'description' => 'Replace one or more exact string matches in editable targets and update the working snapshot. from may be empty only when the target document is blank (for initialization).',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'target'     => array(
							'type' => 'string',
							'enum' => $editable_target_enum,
						),
						'from'       => array( 'type' => 'string' ),
						'to'         => array( 'type' => 'string' ),
						'replaceAll' => array( 'type' => 'boolean' ),
					),
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
					'properties'           => array(
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
					),
					'required'             => array( 'target', 'replacements' ),
					'additionalProperties' => false,
				),
			),
			array(
				'type'        => 'function',
				'name'        => 'set_js_mode',
				'description' => 'Set jsMode for the working snapshot.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'jsMode' => array(
							'type' => 'string',
							'enum' => array( 'classic', 'module' ),
						),
					),
					'required'             => array( 'jsMode' ),
					'additionalProperties' => false,
				),
			),
		);

		if ( $has_history_tool ) {
			$tools[] = array(
				'type'        => 'function',
				'name'        => 'list_ai_edits',
				'description' => 'List previous AI edit history summaries for this post. Use only when recent context is insufficient to identify an earlier edit.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 50,
						),
					),
					'additionalProperties' => false,
				),
			);
			$tools[] = array(
				'type'        => 'function',
				'name'        => 'get_ai_edit',
				'description' => 'Fetch one previous AI edit detail, including input/output snapshots. Use only when a specific prior version is needed.',
				'parameters'  => array(
					'type'                 => 'object',
					'properties'           => array(
						'versionId' => array( 'type' => 'string' ),
					),
					'required'             => array( 'versionId' ),
					'additionalProperties' => false,
				),
			);
		}

		return $tools;
	}
}
