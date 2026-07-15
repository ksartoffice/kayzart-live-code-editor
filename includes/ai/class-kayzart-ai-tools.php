<?php
/**
 * Pure snapshot-editing tools for the AI agent loop.
 *
 * Faithful PHP port of the tool implementations from the legacy
 * kayzart-server (`src/ai-jobs.ts`). Every method here operates on an
 * in-memory snapshot array and performs no WordPress, database or network
 * access, so it is fully unit-testable in isolation.
 *
 * Snapshot shape:
 *   array{
 *     html:string, customHead:string, css:string, js:string,
 *     jsMode:string, baseHash:string
 *   }
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless AI edit tools operating on a snapshot array.
 */
class Ai_Tools {

	/**
	 * Editable / addressable snapshot targets exposed to the model.
	 */
	const TARGETS = array( 'html', 'head', 'css', 'js' );

	/**
	 * Map a model-facing target name to its snapshot array key.
	 *
	 * @param string $target Target name (html|head|css|js).
	 * @return string Snapshot key, or empty string when unknown.
	 */
	public static function target_to_key( string $target ): string {
		switch ( $target ) {
			case 'html':
				return 'html';
			case 'head':
				return 'customHead';
			case 'css':
				return 'css';
			case 'js':
				return 'js';
		}
		return '';
	}

	/**
	 * Compute the stable snapshot base hash.
	 *
	 * Bit-for-bit compatible with the JavaScript editor implementation: it
	 * folds an FNV-1a 32-bit hash over the UTF-16 code units of the four
	 * fields joined by a "\n\0" separator.
	 *
	 * @param string $html        HTML source.
	 * @param string $custom_head Custom head source.
	 * @param string $css         CSS source.
	 * @param string $js          JS source.
	 * @return string Eight-character lowercase hexadecimal hash.
	 */
	public static function compute_base_hash( string $html, string $custom_head, string $css, string $js ): string {
		$separator = "\n\x00";
		$source    = $html . $separator . $custom_head . $separator . $css . $separator . $js;

		$hash = 0x811c9dc5;
		foreach ( self::utf16_code_units( $source ) as $unit ) {
			$hash ^= $unit;
			$hash  = ( $hash * 0x01000193 ) & 0xFFFFFFFF;
		}

		return str_pad( dechex( $hash ), 8, '0', STR_PAD_LEFT );
	}

	/**
	 * Decompose a UTF-8 string into UTF-16 code units.
	 *
	 * Mirrors JavaScript `String.prototype.charCodeAt`, including surrogate
	 * pairs for characters outside the Basic Multilingual Plane.
	 *
	 * @param string $value UTF-8 string.
	 * @return array<int,int> List of UTF-16 code unit values.
	 */
	private static function utf16_code_units( string $value ): array {
		if ( '' === $value ) {
			return array();
		}
		$utf16 = mb_convert_encoding( $value, 'UTF-16BE', 'UTF-8' );
		if ( ! is_string( $utf16 ) ) {
			return array();
		}
		$units  = array();
		$length = strlen( $utf16 );
		for ( $i = 0; $i + 1 < $length; $i += 2 ) {
			$units[] = ( ord( $utf16[ $i ] ) << 8 ) | ord( $utf16[ $i + 1 ] );
		}
		return $units;
	}

	/**
	 * Read a snapshot field by target.
	 *
	 * @param array  $snapshot Snapshot array.
	 * @param string $target   Target name.
	 * @return string
	 */
	public static function get_snapshot_source( array $snapshot, string $target ): string {
		$key = self::target_to_key( $target );
		if ( '' === $key ) {
			return '';
		}
		return isset( $snapshot[ $key ] ) ? (string) $snapshot[ $key ] : '';
	}

	/**
	 * Return a new snapshot with one field replaced and its hash recomputed.
	 *
	 * @param array  $snapshot Snapshot array.
	 * @param string $target   Target name.
	 * @param string $source   Replacement source for the target field.
	 * @return array New snapshot.
	 */
	public static function replace_snapshot_source( array $snapshot, string $target, string $source ): array {
		$html        = 'html' === $target ? $source : (string) ( $snapshot['html'] ?? '' );
		$custom_head = 'head' === $target ? $source : (string) ( $snapshot['customHead'] ?? '' );
		$css         = 'css' === $target ? $source : (string) ( $snapshot['css'] ?? '' );
		$js          = 'js' === $target ? $source : (string) ( $snapshot['js'] ?? '' );

		return array(
			'html'       => $html,
			'customHead' => $custom_head,
			'css'        => $css,
			'js'         => $js,
			'jsMode'     => isset( $snapshot['jsMode'] ) ? (string) $snapshot['jsMode'] : 'classic',
			'baseHash'   => self::compute_base_hash( $html, $custom_head, $css, $js ),
		);
	}

	/**
	 * Validate a target against the editable allow list.
	 *
	 * @param mixed         $value           Raw target value.
	 * @param array<string> $allowed_targets Allowed target names.
	 * @return string Validated target.
	 * @throws Ai_Tool_Error When the target is invalid or not editable.
	 */
	public static function parse_snapshot_target( $value, array $allowed_targets ): string {
		if ( ! in_array( $value, self::TARGETS, true ) ) {
			// Internal tool error surfaced to the model as JSON, not HTML output.
			throw new Ai_Tool_Error( 'Invalid target: ' . ( is_string( $value ) ? $value : '' ), false );
		}
		if ( ! in_array( $value, $allowed_targets, true ) ) {
			// Internal tool error surfaced to the model as JSON, not HTML output.
			throw new Ai_Tool_Error( 'Target "' . $value . '" is not editable in this mode.', false );
		}
		return (string) $value;
	}

	/**
	 * Validate a jsMode value.
	 *
	 * @param mixed $value Raw jsMode value.
	 * @return string 'classic' or 'module'.
	 * @throws Ai_Tool_Error When invalid.
	 */
	public static function parse_js_mode( $value ): string {
		if ( 'classic' === $value || 'module' === $value ) {
			return (string) $value;
		}
		// Internal tool error surfaced to the model as JSON, not HTML output.
		throw new Ai_Tool_Error( 'Invalid jsMode: ' . ( is_string( $value ) ? $value : '' ), false );
	}

	/**
	 * Count non-overlapping occurrences of a needle.
	 *
	 * @param string $haystack Source string.
	 * @param string $needle   Needle string (must be non-empty).
	 * @return int
	 */
	private static function count_occurrences( string $haystack, string $needle ): int {
		if ( '' === $needle ) {
			return 0;
		}
		return substr_count( $haystack, $needle );
	}

	/**
	 * Find plain-text matches across snapshot fields (search_text tool).
	 *
	 * @param array $args     Tool arguments (query, target, limit).
	 * @param array $snapshot Snapshot array.
	 * @return array
	 */
	public static function run_search_text( array $args, array $snapshot ): array {
		$query = isset( $args['query'] ) ? (string) $args['query'] : '';
		if ( '' === $query ) {
			return array( 'matches' => array() );
		}

		$limit = isset( $args['limit'] ) && is_numeric( $args['limit'] ) ? (int) $args['limit'] : 20;
		if ( $limit < 1 ) {
			$limit = 20;
		}
		$limit = max( 1, min( 50, $limit ) );

		$target = isset( $args['target'] ) ? (string) $args['target'] : 'all';
		if ( ! in_array( $target, array( 'all', 'html', 'head', 'css', 'js' ), true ) ) {
			$target = 'all';
		}

		$documents = array();
		foreach ( array( 'html', 'head', 'css', 'js' ) as $doc_target ) {
			if ( 'all' === $target || $target === $doc_target ) {
				$documents[] = array(
					'target' => $doc_target,
					'text'   => self::get_snapshot_source( $snapshot, $doc_target ),
				);
			}
		}

		$query_length = mb_strlen( $query );
		$matches      = array();
		$match_count  = 0;
		foreach ( $documents as $document ) {
			$text        = $document['text'];
			$text_length = mb_strlen( $text );
			$cursor      = 0;
			while ( $match_count < $limit ) {
				$found = mb_strpos( $text, $query, $cursor );
				if ( false === $found ) {
					break;
				}
				$line          = substr_count( mb_substr( $text, 0, $found ), "\n" ) + 1;
				$preview_start = max( 0, $found - 40 );
				$preview_end   = min( $text_length, $found + $query_length + 40 );
				$preview       = preg_replace( '/\s+/', ' ', mb_substr( $text, $preview_start, $preview_end - $preview_start ) );
				$matches[]     = array(
					'target'  => $document['target'],
					'line'    => $line,
					'index'   => $found,
					'preview' => null === $preview ? '' : $preview,
				);
				++$match_count;
				$cursor = $found + max( 1, $query_length );
			}
			if ( $match_count >= $limit ) {
				break;
			}
		}

		return array(
			'query'   => $query,
			'target'  => $target,
			'count'   => $match_count,
			'matches' => $matches,
		);
	}

	/**
	 * Return a line range from a snapshot field (read_document tool).
	 *
	 * @param array $args     Tool arguments (target, startLine, endLine).
	 * @param array $snapshot Snapshot array.
	 * @return array
	 */
	public static function run_read_document( array $args, array $snapshot ): array {
		$target = isset( $args['target'] ) ? (string) $args['target'] : 'html';
		if ( ! in_array( $target, self::TARGETS, true ) ) {
			$target = 'html';
		}
		$source      = self::get_snapshot_source( $snapshot, $target );
		$lines       = explode( "\n", $source );
		$total_lines = count( $lines );

		$start_line = self::to_int_default( $args['startLine'] ?? null, 1 );
		$start_line = max( 1, $start_line );
		$end_line   = self::to_int_default( $args['endLine'] ?? null, $total_lines );
		$end_line   = max( $start_line, $end_line );

		$slice = array_slice( $lines, $start_line - 1, $end_line - ( $start_line - 1 ) );

		return array(
			'target'     => $target,
			'startLine'  => $start_line,
			'endLine'    => $end_line,
			'totalLines' => $total_lines,
			'content'    => implode( "\n", $slice ),
		);
	}

	/**
	 * Echo back the selected element context (get_selected_context tool).
	 *
	 * @param array|null $contexts Selected contexts.
	 * @return array|null
	 */
	public static function run_get_selected_context( $contexts ) {
		return is_array( $contexts ) && count( $contexts ) > 0 ? $contexts : null;
	}

	/**
	 * Replace exact string matches in one field (replace_string tool).
	 *
	 * @param array $args     Tool arguments (target, from, to, replaceAll).
	 * @param array $snapshot Snapshot array.
	 * @return array Tool call result (output, snapshot, appliedEditOperation).
	 * @throws Ai_Tool_Error On ambiguous / missing / invalid replacements.
	 */
	public static function run_replace_string( array $args, array $snapshot ): array {
		$target      = (string) $args['target'];
		$current     = self::get_snapshot_source( $snapshot, $target );
		$from        = isset( $args['from'] ) ? (string) $args['from'] : '';
		$to          = isset( $args['to'] ) ? (string) $args['to'] : '';
		$replace_all = ! empty( $args['replaceAll'] );

		if ( '' === $from ) {
			if ( '' !== trim( $current ) ) {
				throw new Ai_Tool_Error( 'replace_string.from may be empty only when target is blank.', false );
			}
			$next = self::replace_snapshot_source( $snapshot, $target, $to );
			return array(
				'output'               => array(
					'target'        => $target,
					'replacedCount' => 1,
					'replaceAll'    => false,
					'nextBaseHash'  => $next['baseHash'],
				),
				'snapshot'             => $next,
				'appliedEditOperation' => true,
			);
		}

		$count = self::count_occurrences( $current, $from );
		if ( 0 === $count ) {
			// Internal tool error surfaced to the model as JSON, not HTML output.
			throw new Ai_Tool_Error( 'replace_string matched 0 occurrences in ' . $target . '.', false );
		}
		if ( ! $replace_all && $count > 1 ) {
			// Internal tool error surfaced to the model as JSON, not HTML output.
			throw new Ai_Tool_Error( 'replace_string is ambiguous in ' . $target . '; ' . $count . ' matches found.', false );
		}

		if ( $replace_all ) {
			$replaced       = str_replace( $from, $to, $current );
			$replaced_count = $count;
		} else {
			$position       = strpos( $current, $from );
			$replaced       = substr_replace( $current, $to, $position, strlen( $from ) );
			$replaced_count = 1;
		}

		$next = self::replace_snapshot_source( $snapshot, $target, $replaced );
		return array(
			'output'               => array(
				'target'        => $target,
				'replacedCount' => $replaced_count,
				'replaceAll'    => $replace_all,
				'nextBaseHash'  => $next['baseHash'],
			),
			'snapshot'             => $next,
			'appliedEditOperation' => true,
		);
	}

	/**
	 * Apply an ordered list of exact replacements (replace_many tool).
	 *
	 * @param array $args     Tool arguments (target, replacements[]).
	 * @param array $snapshot Snapshot array.
	 * @return array Tool call result.
	 * @throws Ai_Tool_Error On empty list or a failed replacement step.
	 */
	public static function run_replace_many( array $args, array $snapshot ): array {
		$target       = (string) $args['target'];
		$replacements = isset( $args['replacements'] ) && is_array( $args['replacements'] ) ? $args['replacements'] : array();
		if ( 0 === count( $replacements ) ) {
			throw new Ai_Tool_Error( 'replace_many.replacements must not be empty.', false );
		}

		$current_snapshot = $snapshot;
		$total_replaced   = 0;
		$steps            = array();
		$index            = 0;
		foreach ( $replacements as $replacement ) {
			$replacement      = is_array( $replacement ) ? $replacement : array();
			$result           = self::run_replace_string(
				array(
					'target'     => $target,
					'from'       => isset( $replacement['from'] ) ? (string) $replacement['from'] : '',
					'to'         => isset( $replacement['to'] ) ? (string) $replacement['to'] : '',
					'replaceAll' => ! empty( $replacement['replaceAll'] ),
				),
				$current_snapshot
			);
			$current_snapshot = isset( $result['snapshot'] ) ? $result['snapshot'] : $current_snapshot;
			$replaced_count   = isset( $result['output']['replacedCount'] ) ? (int) $result['output']['replacedCount'] : 0;
			$total_replaced  += $replaced_count;
			$steps[]          = array(
				'index'         => $index,
				'replacedCount' => $replaced_count,
				'replaceAll'    => ! empty( $replacement['replaceAll'] ),
			);
			++$index;
		}

		return array(
			'output'               => array(
				'target'        => $target,
				'steps'         => $steps,
				'replacedCount' => $total_replaced,
				'nextBaseHash'  => $current_snapshot['baseHash'],
			),
			'snapshot'             => $current_snapshot,
			'appliedEditOperation' => true,
		);
	}

	/**
	 * Set the working snapshot jsMode (set_js_mode tool).
	 *
	 * @param array $args     Tool arguments (jsMode).
	 * @param array $snapshot Snapshot array.
	 * @return array Tool call result.
	 * @throws Ai_Tool_Error When jsMode is invalid.
	 */
	public static function run_set_js_mode( array $args, array $snapshot ): array {
		$js_mode          = self::parse_js_mode( $args['jsMode'] ?? null );
		$next             = $snapshot;
		$next['jsMode']   = $js_mode;
		$next['baseHash'] = self::compute_base_hash(
			(string) ( $snapshot['html'] ?? '' ),
			(string) ( $snapshot['customHead'] ?? '' ),
			(string) ( $snapshot['css'] ?? '' ),
			(string) ( $snapshot['js'] ?? '' )
		);
		return array(
			'output'               => array(
				'jsMode'       => $next['jsMode'],
				'nextBaseHash' => $next['baseHash'],
			),
			'snapshot'             => $next,
			'appliedEditOperation' => true,
		);
	}

	/**
	 * Dispatch a synchronous (pure) tool call by name.
	 *
	 * History tools (list_ai_edits/get_ai_edit) are handled elsewhere as they
	 * require WordPress/database access.
	 *
	 * @param string        $name             Tool name.
	 * @param array         $args             Decoded tool arguments.
	 * @param array         $snapshot         Working snapshot.
	 * @param array|null    $selected_contexts Selected element contexts.
	 * @param array<string> $allowed_targets  Editable target allow list.
	 * @return array Tool call result (output, snapshot?, appliedEditOperation).
	 * @throws Ai_Tool_Error On invalid arguments or unknown tool.
	 */
	public static function run_tool( string $name, array $args, array $snapshot, $selected_contexts, array $allowed_targets ): array {
		switch ( $name ) {
			case 'search_text':
				return array(
					'output'               => self::run_search_text( $args, $snapshot ),
					'appliedEditOperation' => false,
				);
			case 'read_document':
				return array(
					'output'               => self::run_read_document( $args, $snapshot ),
					'appliedEditOperation' => false,
				);
			case 'get_selected_context':
				return array(
					'output'               => self::run_get_selected_context( $selected_contexts ),
					'appliedEditOperation' => false,
				);
			case 'replace_string':
				$args['target'] = self::parse_snapshot_target( $args['target'] ?? null, $allowed_targets );
				return self::run_replace_string( $args, $snapshot );
			case 'replace_many':
				$args['target'] = self::parse_snapshot_target( $args['target'] ?? null, $allowed_targets );
				return self::run_replace_many( $args, $snapshot );
			case 'set_js_mode':
				return self::run_set_js_mode( $args, $snapshot );
		}
		// Internal tool error surfaced to the model as JSON, not HTML output.
		throw new Ai_Tool_Error( 'Unknown tool: ' . $name, false );
	}

	/**
	 * Coerce a value to an integer with a JS `Number(x) || fallback` fallback.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Fallback when the value is missing or zero.
	 * @return int
	 */
	private static function to_int_default( $value, int $fallback ): int {
		if ( null === $value || ! is_numeric( $value ) ) {
			return $fallback;
		}
		$number = (int) $value;
		return 0 !== $number ? $number : $fallback;
	}
}
