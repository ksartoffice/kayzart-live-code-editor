<?php
/**
 * Abilities API registration for external AI agents.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Kayzart page abilities so MCP clients can build pages.
 *
 * The Abilities API ships with WordPress 6.9. The MCP Adapter plugin turns
 * abilities marked `mcp.public` into tools for agents such as Claude Code,
 * Codex or Cursor. On older WordPress versions nothing is registered and the
 * plugin works as before.
 */
class Abilities {

	const CATEGORY = 'kayzart';

	/**
	 * Hook registration when the Abilities API is available.
	 */
	public static function init(): void {
		if ( ! self::is_supported() ) {
			return;
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Whether this WordPress has the Abilities API.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	/**
	 * Register the Kayzart ability category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Kayzart', 'kayzart-live-code-editor' ),
				'description' => __( 'Create and edit Kayzart pages built from real HTML and CSS.', 'kayzart-live-code-editor' ),
			)
		);
	}

	/**
	 * Register every Kayzart ability.
	 */
	public static function register_abilities(): void {
		foreach ( self::definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Ability definitions keyed by name.
	 *
	 * @return array<string,array>
	 */
	public static function definitions(): array {
		$page_id = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'Page ID returned by kayzart/list-pages or kayzart/create-page.',
		);
		$sources = array(
			'html' => array(
				'type'        => 'string',
				'description' => 'Complete body fragment HTML. Replaces the current html. No <!doctype>, <html>, <head> or <body> tags.',
			),
			'css'  => array(
				'type'        => 'string',
				'description' => 'Complete CSS source. Replaces the current css. In tailwind mode it must keep @import "tailwindcss";.',
			),
			'head' => array(
				'type'        => 'string',
				'description' => 'Custom additions inside <head>, such as <title> and <meta>. Requires the unfiltered_html capability.',
			),
		);
		$summary = array(
			'type'        => 'string',
			'maxLength'   => 500,
			'description' => 'One sentence describing what you changed, written for the person who owns the page and in their language. Shown in the editor AI history next to this change.',
		);

		return array(
			'kayzart/get-guidelines'   => array(
				'label'               => __( 'Get Kayzart authoring guidelines', 'kayzart-live-code-editor' ),
				'description'         => 'Call this first. Returns the rules every Kayzart page must follow (page structure, CSS mode rules, security policy enforced on save), the font CSS values the site can render, editable targets and size limits.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'cssMode' => array(
							'type'        => 'string',
							'enum'        => Agent_Pages::CSS_MODES,
							'description' => 'CSS mode the page uses or will use. Defaults to normal.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'cssMode'         => array( 'type' => 'string' ),
						'rules'           => array( 'type' => 'string' ),
						'fonts'           => array( 'type' => 'object' ),
						'editableTargets' => self::string_list(),
						'postTypes'       => self::string_list(),
						'publishedPages'  => array( 'type' => 'object' ),
						'limits'          => array( 'type' => 'object' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::guidelines( (string) ( $input['cssMode'] ?? '' ) );
				},
				'permission_callback' => static function () {
					return Agent_Pages::can_use();
				},
				'meta'                => self::meta( true, false, true ),
			),
			'kayzart/list-pages'       => array(
				'label'               => __( 'List Kayzart pages', 'kayzart-live-code-editor' ),
				'description'         => 'Lists Kayzart pages you can edit, most recently modified first. Returns id, title, status, cssMode, editUrl (opens the page in the Kayzart editor) and viewUrl.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'search'  => array(
							'type'        => 'string',
							'description' => 'Filter by words in the title or content.',
						),
						'status'  => array(
							'type' => 'string',
							'enum' => array( 'draft', 'pending', 'private', 'publish', 'future' ),
						),
						'page'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'perPage' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Agent_Pages::MAX_PER_PAGE,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'pages'      => array(
							'type'  => 'array',
							'items' => self::summary_schema(),
						),
						'page'       => array( 'type' => 'integer' ),
						'totalPages' => array( 'type' => 'integer' ),
						'total'      => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::list_pages( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return Agent_Pages::can_use();
				},
				'meta'                => self::meta( true, false, true ),
			),
			'kayzart/get-page'         => array(
				'label'               => __( 'Get a Kayzart page', 'kayzart-live-code-editor' ),
				'description'         => 'Reads one page: html, css, head and js (js is read-only), plus baseHash. Pass baseHash to kayzart/update-page.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $page_id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::page_schema(),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::get_page( (int) $input['id'] );
				},
				'permission_callback' => static function ( $input ) {
					return Agent_Pages::can_edit( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'                => self::meta( true, false, true ),
			),
			'kayzart/get-preview-link' => array(
				'label'               => __( 'Get a Kayzart preview link', 'kayzart-live-code-editor' ),
				'description'         => 'Returns a link that shows the saved page without logging in, valid for a short time (see expiresIn). Open it with your own browser or screenshot tool to check the page visually at mobile and desktop widths. Issuing a new link invalidates the previous one. Do not share it publicly.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array( 'id' => $page_id ),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'url'       => array( 'type' => 'string' ),
						'expiresAt' => array( 'type' => 'string' ),
						'expiresIn' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::preview_link( (int) $input['id'] );
				},
				'permission_callback' => static function ( $input ) {
					return Agent_Pages::can_edit( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'                => self::meta( false, false, false ),
			),
			'kayzart/list-media'       => array(
				'label'               => __( 'List media library files', 'kayzart-live-code-editor' ),
				'description'         => 'Searches the WordPress media library and returns real file URLs with alt text and sizes. Use only these URLs (or ones the person gave you) for images, video and audio.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'default'              => array(),
					'properties'           => array(
						'search'  => array(
							'type'        => 'string',
							'description' => 'Words in the file title, caption or description.',
						),
						'type'    => array(
							'type'        => 'string',
							'enum'        => Agent_Pages::MEDIA_TYPES,
							'description' => 'Media kind. Defaults to image.',
						),
						'page'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'perPage' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => Agent_Pages::MAX_MEDIA_PER_PAGE,
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'media'      => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'       => array( 'type' => 'integer' ),
									'url'      => array( 'type' => 'string' ),
									'title'    => array( 'type' => 'string' ),
									'alt'      => array( 'type' => 'string' ),
									'mimeType' => array( 'type' => 'string' ),
									'width'    => array( 'type' => array( 'integer', 'null' ) ),
									'height'   => array( 'type' => array( 'integer', 'null' ) ),
									'sizes'    => array( 'type' => 'array' ),
								),
							),
						),
						'page'       => array( 'type' => 'integer' ),
						'totalPages' => array( 'type' => 'integer' ),
						'total'      => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::list_media( is_array( $input ) ? $input : array() );
				},
				'permission_callback' => static function () {
					return Agent_Pages::can_list_media();
				},
				'meta'                => self::meta( true, false, true ),
			),
			'kayzart/create-page'      => array(
				'label'               => __( 'Create a Kayzart page', 'kayzart-live-code-editor' ),
				'description'         => 'Creates a new draft page from complete html and css. Follow kayzart/get-guidelines. Nothing is published: return editUrl to the person so they can review and publish it. The save is refused with a list of violations if the page breaks the security policy or the CSS is malformed; fix them and call again. After a save, warnings lists problems found in the saved page (unknown Tailwind classes, missing alt text, dead in-page links, missing media files).',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'title'    => array(
							'type'        => 'string',
							'description' => 'Page title shown in WordPress.',
						),
						'html'     => $sources['html'],
						'css'      => $sources['css'],
						'head'     => $sources['head'],
						'cssMode'  => array(
							'type'        => 'string',
							'enum'        => Agent_Pages::CSS_MODES,
							'description' => 'normal for plain CSS (default); tailwind to style with Tailwind CSS v4 utility classes compiled on the server.',
						),
						'postType' => array(
							'type'        => 'string',
							'description' => 'Post type to create. Defaults to page. Must be one of postTypes from kayzart/get-guidelines.',
						),
						'summary'  => $summary,
					),
					'required'             => array( 'title', 'html' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::write_schema(),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::create_page( $input );
				},
				'permission_callback' => static function ( $input ) {
					$post_type = Agent_Pages::resolve_post_type( isset( $input['postType'] ) ? sanitize_key( (string) $input['postType'] ) : '' );
					return '' !== $post_type && Agent_Pages::can_create( $post_type );
				},
				'meta'                => self::meta( false, false, false ),
			),
			'kayzart/update-page'      => array(
				'label'               => __( 'Update a Kayzart page', 'kayzart-live-code-editor' ),
				'description'         => 'Changes an existing page. Published and scheduled pages can be changed only when the site allows it: check canUpdate from kayzart/get-page, and when it is false, tell the person the updateBlockedReason it gives. Requires baseHash from kayzart/get-page; if the page changed since, the call fails and you must read it again. Use edits for small changes: each edit replaces an exact substring (from) with to, in order; a from that matches more than once fails unless replaceAll is true. Send html, css or head only to replace a whole source. Every change is saved as a revision the person can restore.',
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'       => $page_id,
						'baseHash' => array(
							'type'        => 'string',
							'description' => 'baseHash from the latest kayzart/get-page or write result.',
						),
						'title'    => array( 'type' => 'string' ),
						'html'     => $sources['html'],
						'css'      => $sources['css'],
						'head'     => $sources['head'],
						'summary'  => $summary,
						'edits'    => array(
							'type'     => 'array',
							'maxItems' => Agent_Pages::MAX_EDITS,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'target'     => array(
										'type' => 'string',
										'enum' => array( 'html', 'css', 'head' ),
									),
									'from'       => array(
										'type'        => 'string',
										'description' => 'Exact current text to replace. Copy it from the source.',
									),
									'to'         => array( 'type' => 'string' ),
									'replaceAll' => array( 'type' => 'boolean' ),
								),
								'required'             => array( 'target', 'from', 'to' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'id', 'baseHash' ),
					'additionalProperties' => false,
				),
				'output_schema'       => self::write_schema(),
				'execute_callback'    => static function ( $input ) {
					return Agent_Pages::update_page( $input );
				},
				'permission_callback' => static function ( $input ) {
					return Agent_Pages::can_edit( (int) ( $input['id'] ?? 0 ) );
				},
				'meta'                => self::meta( false, true, false ),
			),
		);
	}

	/**
	 * Ability meta: behavior annotations, REST exposure and MCP exposure.
	 *
	 * @param bool $read_only   Whether the ability only reads.
	 * @param bool $destructive Whether it may overwrite existing content.
	 * @param bool $idempotent  Whether repeating it has no further effect.
	 * @return array
	 */
	private static function meta( bool $read_only, bool $destructive, bool $idempotent ): array {
		return array(
			'annotations'  => array(
				'readonly'    => $read_only,
				'destructive' => $destructive,
				'idempotent'  => $idempotent,
			),
			'show_in_rest' => true,
			'mcp'          => array(
				'public' => true,
				'type'   => 'tool',
			),
		);
	}

	/**
	 * Schema for a list of strings.
	 *
	 * @return array
	 */
	private static function string_list(): array {
		return array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Schema for a page summary.
	 *
	 * @return array
	 */
	private static function summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'title'    => array( 'type' => 'string' ),
				'status'   => array( 'type' => 'string' ),
				'postType' => array( 'type' => 'string' ),
				'cssMode'  => array( 'type' => 'string' ),
				'modified' => array( 'type' => 'string' ),
				'editUrl'  => array( 'type' => 'string' ),
				'viewUrl'  => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Schema for a page with its sources.
	 *
	 * @return array
	 */
	private static function page_schema(): array {
		$schema                = self::summary_schema();
		$schema['properties'] += array(
			'html'                => array( 'type' => 'string' ),
			'css'                 => array( 'type' => 'string' ),
			'head'                => array( 'type' => 'string' ),
			'js'                  => array( 'type' => 'string' ),
			'baseHash'            => array( 'type' => 'string' ),
			'editableTargets'     => self::string_list(),
			'canUpdate'           => array( 'type' => 'boolean' ),
			'updateBlockedReason' => array( 'type' => array( 'string', 'null' ) ),
		);
		return $schema;
	}

	/**
	 * Schema for the result of a create or update.
	 *
	 * @return array
	 */
	private static function write_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'page'       => self::page_schema(),
				'revisionId' => array( 'type' => array( 'integer', 'null' ) ),
				'warnings'   => self::string_list(),
			),
		);
	}
}
