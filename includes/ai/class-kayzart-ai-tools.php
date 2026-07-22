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
	const DEFAULT_READ_CHARS                     = 8000;
	const MAX_READ_CHARS                         = 12000;
	const MAX_READ_LINES                         = 200;
	const MAX_REPLACE_DIAGNOSTIC_CANDIDATES      = 2;
	const MAX_REPLACE_DIAGNOSTIC_CANDIDATE_CHARS = 600;
	const MAX_REPLACE_DIAGNOSTIC_TOTAL_CHARS     = 1200;
	const MAX_REPLACE_DIAGNOSTIC_ANCHORS         = 6;

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

	/** Return the number of JavaScript-compatible UTF-16 code units.
	 *
	 * @param string $value UTF-8 value.
	 * @return int
	 */
	public static function utf16_length( string $value ): int {
		return count( self::utf16_code_units( $value ) );
	}

	/** Slice a UTF-8 string using JavaScript-compatible UTF-16 offsets.
	 *
	 * @param string $value  UTF-8 value.
	 * @param int    $start  UTF-16 start offset.
	 * @param int    $length UTF-16 length.
	 * @return string
	 */
	public static function utf16_slice( string $value, int $start, int $length ): string {
		$utf16 = mb_convert_encoding( $value, 'UTF-16BE', 'UTF-8' );
		$slice = substr( $utf16, max( 0, $start ) * 2, max( 0, $length ) * 2 );
		return (string) mb_convert_encoding( $slice, 'UTF-8', 'UTF-16BE' );
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
	private static function replace_snapshot_source( array $snapshot, string $target, string $source ): array {
		$html        = 'html' === $target ? $source : (string) ( $snapshot['html'] ?? '' );
		$custom_head = 'head' === $target ? $source : (string) ( $snapshot['customHead'] ?? '' );
		$css         = 'css' === $target ? $source : (string) ( $snapshot['css'] ?? '' );
		$js          = (string) ( $snapshot['js'] ?? '' );

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

	/** Build bounded diagnostics for an exact replacement that matched nothing.
	 *
	 * @param string $target       Snapshot target.
	 * @param string $from         Failed exact source string.
	 * @param string $current      Current source inside the effective scope.
	 * @param string $base_hash    Current snapshot hash.
	 * @param string $selection_id Optional selection identifier.
	 * @return array
	 */
	private static function build_replace_no_match_details( string $target, string $from, string $current, string $base_hash, string $selection_id = '' ): array {
		$candidates = self::find_replace_diagnostic_candidates( $from, $current );
		$details    = array(
			'code'              => 'replace_no_match',
			'target'            => $target,
			'baseHash'          => $base_hash,
			'scope'             => '' === $selection_id ? 'document' : 'selection',
			'attemptedFromHash' => hash( 'sha256', $from ),
			'sourceCharacters'  => mb_strlen( $current ),
			'candidateCount'    => count( $candidates ),
			'candidates'        => $candidates,
			'guidance'          => 'Do not retry the same from value. Copy an exact substring from a candidate content field. If the candidates are insufficient, use one targeted read or search call.',
		);
		if ( '' !== $selection_id ) {
			$details['selectionId'] = $selection_id;
		}
		return $details;
	}

	/** Find at most two bounded exact-source contexts related to a failed value.
	 *
	 * @param string $from    Failed exact source string.
	 * @param string $current Current scoped source.
	 * @return array<int,array>
	 */
	private static function find_replace_diagnostic_candidates( string $from, string $current ): array {
		if ( '' === trim( $from ) || '' === $current ) {
			return array();
		}

		$tokens = self::diagnostic_tokens( $from );
		if ( count( $tokens ) > 0 && count( $tokens ) <= 200 && mb_strlen( $from ) <= 2000 ) {
			$quoted  = array_map(
				static function ( $token ) {
					return preg_quote( $token, '~' );
				},
				$tokens
			);
			$pattern = '~' . implode( '\\s*', $quoted ) . '~u';
			$matched = preg_match( $pattern, $current, $matches, PREG_OFFSET_CAPTURE );
			if ( 1 === $matched && isset( $matches[0][0], $matches[0][1] ) ) {
				$match_text = (string) $matches[0][0];
				$start      = mb_strlen( substr( $current, 0, (int) $matches[0][1] ) );
				return array( self::build_diagnostic_candidate( $current, $start, mb_strlen( $match_text ), 'whitespace_equivalent' ) );
			}
		}

		$anchors = self::diagnostic_anchors( $from );
		$ranked  = array();
		foreach ( $anchors as $anchor ) {
			$offset = 0;
			$found  = 0;
			while ( $found < 3 ) {
				$position = mb_strpos( $current, $anchor, $offset );
				if ( false === $position ) {
					break;
				}
				$candidate = self::build_diagnostic_candidate( $current, $position, mb_strlen( $anchor ), 'anchor_context' );
				$score     = 0;
				foreach ( $anchors as $score_anchor ) {
					if ( false !== mb_strpos( $candidate['content'], $score_anchor ) ) {
						++$score;
					}
				}
				$hash = $candidate['contentHash'];
				if ( ! isset( $ranked[ $hash ] ) || $score > $ranked[ $hash ]['score'] ) {
					$ranked[ $hash ] = array(
						'score'     => $score,
						'candidate' => $candidate,
					);
				}
				$offset = $position + max( 1, mb_strlen( $anchor ) );
				++$found;
			}
		}

		usort(
			$ranked,
			static function ( $left, $right ) {
				if ( $left['score'] === $right['score'] ) {
					return $left['candidate']['line'] - $right['candidate']['line'];
				}
				return $right['score'] - $left['score'];
			}
		);
		$candidates = array();
		$total      = 0;
		foreach ( $ranked as $item ) {
			$content_length = mb_strlen( $item['candidate']['content'] );
			if ( $total + $content_length > self::MAX_REPLACE_DIAGNOSTIC_TOTAL_CHARS ) {
				continue;
			}
			$candidates[] = $item['candidate'];
			$total       += $content_length;
			if ( count( $candidates ) >= self::MAX_REPLACE_DIAGNOSTIC_CANDIDATES ) {
				break;
			}
		}
		return $candidates;
	}

	/** Tokenize an attempted replacement for whitespace-tolerant diagnostics.
	 *
	 * @param string $value Source value.
	 * @return array<int,string>
	 */
	private static function diagnostic_tokens( string $value ): array {
		$matched = preg_match_all( '/[\p{L}\p{N}_-]+|[^\s]/u', $value, $matches );
		return false !== $matched && isset( $matches[0] ) ? array_values( $matches[0] ) : array();
	}

	/** Extract a short ordered set of useful exact-search anchors.
	 *
	 * @param string $value Failed replacement source.
	 * @return array<int,string>
	 */
	private static function diagnostic_anchors( string $value ): array {
		$pool  = array();
		$lines = preg_split( '/\R/u', $value );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( $line );
			if ( 4 <= mb_strlen( $line ) && trim( $value ) !== $line && 160 >= mb_strlen( $line ) ) {
				$pool[] = $line;
			}
		}
		preg_match_all( '/[\p{L}\p{N}_-]{4,}/u', $value, $word_matches );
		foreach ( isset( $word_matches[0] ) ? $word_matches[0] : array() as $word ) {
			$pool[] = $word;
		}
		preg_match_all( '/[\p{L}\p{N}]{4,}/u', $value, $plain_word_matches );
		foreach ( isset( $plain_word_matches[0] ) ? $plain_word_matches[0] : array() as $word ) {
			$pool[] = $word;
		}
		$pool = array_values( array_unique( $pool ) );
		usort(
			$pool,
			static function ( $left, $right ) {
				return mb_strlen( $right ) - mb_strlen( $left );
			}
		);
		return array_slice( $pool, 0, self::MAX_REPLACE_DIAGNOSTIC_ANCHORS );
	}

	/** Build one exact, bounded source context.
	 *
	 * @param string $current      Current scoped source.
	 * @param int    $match_start  Match start in UTF-8 characters.
	 * @param int    $match_length Match length in UTF-8 characters.
	 * @param string $match_kind   Diagnostic matching strategy.
	 * @return array
	 */
	private static function build_diagnostic_candidate( string $current, int $match_start, int $match_length, string $match_kind ): array {
		$source_length = mb_strlen( $current );
		$limit         = self::MAX_REPLACE_DIAGNOSTIC_CANDIDATE_CHARS;
		$context       = max( 0, $limit - min( $limit, $match_length ) );
		$start         = max( 0, $match_start - (int) floor( $context / 2 ) );
		$start         = min( $start, max( 0, $source_length - $limit ) );
		$content       = mb_substr( $current, $start, $limit );
		return array(
			'matchKind'   => $match_kind,
			'line'        => substr_count( mb_substr( $current, 0, $start ), "\n" ) + 1,
			'content'     => $content,
			'contentHash' => hash( 'sha256', $content ),
			'truncated'   => $start > 0 || $start + mb_strlen( $content ) < $source_length,
		);
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
	 * @throws Ai_Tool_Error When a cursor is invalid or stale.
	 */
	public static function run_read_document( array $args, array $snapshot ): array {
		$target = isset( $args['target'] ) ? (string) $args['target'] : 'html';
		if ( ! in_array( $target, self::TARGETS, true ) ) {
			$target = 'html';
		}
		$source       = self::get_snapshot_source( $snapshot, $target );
		$base_hash    = isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '';
		$lines        = explode( "\n", $source );
		$total_lines  = count( $lines );
		$cursor       = self::parse_optional_cursor( $args['cursor'] ?? null );
		$start_offset = null;
		if ( null !== $cursor ) {
			if ( 'document' !== ( $cursor['type'] ?? '' ) || ( $cursor['target'] ?? '' ) !== $target || ( $cursor['baseHash'] ?? '' ) !== $base_hash ) {
				throw new Ai_Tool_Error( 'read_document cursor is stale or belongs to another document. Omit cursor to restart from the first page; for continuation, copy nextCursor exactly.', false );
			}
			$start_byte = max( 0, (int) ( $cursor['nextByteOffset'] ?? 0 ) );
			$end_byte   = max( $start_byte, (int) ( $cursor['endByteOffset'] ?? strlen( $source ) ) );
			if ( $end_byte > strlen( $source ) || ! mb_check_encoding( substr( $source, 0, $start_byte ), 'UTF-8' ) || ! mb_check_encoding( substr( $source, 0, $end_byte ), 'UTF-8' ) ) {
				throw new Ai_Tool_Error( 'read_document cursor does not point to a valid UTF-8 boundary. Omit cursor to restart from the first page; for continuation, copy nextCursor exactly.', false );
			}
			$start_offset = mb_strlen( substr( $source, 0, $start_byte ) );
			$start_line   = substr_count( mb_substr( $source, 0, $start_offset ), "\n" ) + 1;
			$end_offset   = mb_strlen( substr( $source, 0, $end_byte ) );
		} else {
			$start_line   = self::to_int_default( $args['startLine'] ?? null, 1 );
			$start_line   = min( $total_lines, max( 1, $start_line ) );
			$end_line     = self::to_int_default( $args['endLine'] ?? null, $total_lines );
			$end_line     = min( $total_lines, max( $start_line, $end_line ) );
			$start_offset = mb_strlen( implode( "\n", array_slice( $lines, 0, $start_line - 1 ) ) );
			if ( $start_line > 1 ) {
				++$start_offset;
			}
			$requested  = implode( "\n", array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );
			$end_offset = $start_offset + mb_strlen( $requested );
		}
		$page_end_offset = $end_offset;
		$line_cursor     = $start_offset;
		for ( $line_count = 0; $line_count < self::MAX_READ_LINES; $line_count++ ) {
			$newline = mb_strpos( $source, "\n", $line_cursor );
			if ( false === $newline || $newline >= $end_offset ) {
				break;
			}
			$line_cursor = $newline + 1;
			if ( self::MAX_READ_LINES === $line_count + 1 ) {
				$page_end_offset = $line_cursor;
			}
		}
		$max_chars  = self::read_char_limit( $args );
		$content    = mb_substr( $source, $start_offset, min( $max_chars, $page_end_offset - $start_offset ) );
		$next_at    = $start_offset + mb_strlen( $content );
		$actual_end = $start_line + substr_count( $content, "\n" );
		$truncated  = $next_at < $end_offset;
		$next_byte  = strlen( mb_substr( $source, 0, $next_at ) );
		$end_byte   = strlen( mb_substr( $source, 0, $end_offset ) );
		$next       = $truncated ? self::encode_cursor(
			array(
				'v'              => 1,
				'type'           => 'document',
				'target'         => $target,
				'baseHash'       => $base_hash,
				'nextByteOffset' => $next_byte,
				'endByteOffset'  => $end_byte,
			)
		) : null;

		return array(
			'target'      => $target,
			'baseHash'    => $base_hash,
			'startLine'   => $start_line,
			'endLine'     => $actual_end,
			'startOffset' => $start_offset,
			'endOffset'   => $next_at,
			'totalLines'  => $total_lines,
			'content'     => $content,
			'truncated'   => $truncated,
			'nextCursor'  => $next,
		);
	}

	/** Read one validated selection without exposing all selected HTML.
	 *
	 * @param array $args     Tool arguments.
	 * @param array $snapshot Current snapshot.
	 * @param array $records  Selection records keyed by ID.
	 * @return array
	 * @throws Ai_Tool_Error When the selection or cursor is invalid or stale.
	 */
	public static function run_read_selection( array $args, array $snapshot, array $records ): array {
		$id     = isset( $args['selectionId'] ) ? (string) $args['selectionId'] : '';
		$record = isset( $records[ $id ] ) && is_array( $records[ $id ] ) ? $records[ $id ] : null;
		if ( null === $record || empty( $record['resolvable'] ) ) {
			throw new Ai_Tool_Error( 'Selection is missing or cannot be resolved.', false );
		}
		$source  = self::get_snapshot_source( $snapshot, 'html' );
		$start   = (int) $record['startOffset'];
		$length  = (int) $record['endOffset'] - $start;
		$content = self::utf16_slice( $source, $start, $length );
		$hash    = hash( 'sha256', $content );
		if ( ! hash_equals( (string) $record['contentHash'], $hash ) ) {
			throw new Ai_Tool_Error( 'Selection is stale because the selected source changed.', false );
		}
		$offset = 0;
		$cursor = self::parse_optional_cursor( $args['cursor'] ?? null );
		if ( null !== $cursor ) {
			$base_hash = isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '';
			if ( 'selection' !== ( $cursor['type'] ?? '' ) || ( $cursor['selectionId'] ?? '' ) !== $id || ( $cursor['contentHash'] ?? '' ) !== $hash || ( $cursor['baseHash'] ?? '' ) !== $base_hash ) {
				throw new Ai_Tool_Error( 'read_selection cursor is stale or belongs to another selection. Omit cursor to restart from the first page; for continuation, copy nextCursor exactly.', false );
			}
			$byte_offset = max( 0, (int) ( $cursor['nextByteOffset'] ?? 0 ) );
			if ( $byte_offset > strlen( $content ) || ! mb_check_encoding( substr( $content, 0, $byte_offset ), 'UTF-8' ) ) {
				throw new Ai_Tool_Error( 'read_selection cursor does not point to a valid UTF-8 boundary. Omit cursor to restart from the first page; for continuation, copy nextCursor exactly.', false );
			}
			$offset = mb_strlen( substr( $content, 0, $byte_offset ) );
		}
		$max_chars = self::read_char_limit( $args );
		$chunk     = mb_substr( $content, $offset, $max_chars );
		$next_at   = $offset + mb_strlen( $chunk );
		$truncated = $next_at < mb_strlen( $content );
		return array(
			'selectionId' => $id,
			'baseHash'    => isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '',
			'contentHash' => $hash,
			'startOffset' => $offset,
			'endOffset'   => $next_at,
			'totalChars'  => mb_strlen( $content ),
			'content'     => $chunk,
			'truncated'   => $truncated,
			'nextCursor'  => $truncated ? self::encode_cursor(
				array(
					'v'              => 1,
					'type'           => 'selection',
					'selectionId'    => $id,
					'baseHash'       => isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '',
					'contentHash'    => $hash,
					'nextByteOffset' => strlen( mb_substr( $content, 0, $next_at ) ),
				)
			) : null,
		);
	}

	/** Resolve the exact current source used by a replacement operation.
	 *
	 * @param string $target            Replacement target.
	 * @param array  $snapshot          Current snapshot.
	 * @param array  $selection_records Selection records keyed by ID.
	 * @param string $selection_id      Optional normalized selection ID.
	 * @return array{current:string,scope:array|null}
	 * @throws Ai_Tool_Error When the requested selection scope is invalid.
	 */
	private static function resolve_replacement_scope( string $target, array $snapshot, array $selection_records, string $selection_id ): array {
		$current = self::get_snapshot_source( $snapshot, $target );
		$scope   = null;
		if ( '' !== $selection_id ) {
			if ( 'html' !== $target ) {
				throw new Ai_Tool_Error( 'selectionId can only scope HTML replacements.', false );
			}
			$scope = isset( $selection_records[ $selection_id ] ) ? $selection_records[ $selection_id ] : null;
			if ( ! is_array( $scope ) || empty( $scope['resolvable'] ) ) {
				throw new Ai_Tool_Error( 'Selection is missing or cannot be resolved.', false );
			}
			$current = self::utf16_slice( $current, (int) $scope['startOffset'], (int) $scope['endOffset'] - (int) $scope['startOffset'] );
			if ( ! hash_equals( (string) $scope['contentHash'], hash( 'sha256', $current ) ) ) {
				throw new Ai_Tool_Error( 'Selection is stale because the selected source changed.', false );
			}
		}
		return array(
			'current' => $current,
			'scope'   => $scope,
		);
	}

	/**
	 * Replace exact string matches in one field (replace_string tool).
	 *
	 * @param array $args     Tool arguments (target, from, to, replaceAll).
	 * @param array $snapshot Snapshot array.
	 * @param array $selection_records Selection records keyed by ID.
	 * @return array Tool call result (output, snapshot, appliedEditOperation).
	 * @throws Ai_Tool_Error On ambiguous / missing / invalid replacements.
	 */
	public static function run_replace_string( array $args, array $snapshot, array $selection_records = array() ): array {
		$target = (string) $args['target'];
		if ( 'js' === $target ) {
			throw new Ai_Tool_Error( 'JavaScript source is read-only for AI edits.', false );
		}
		$selection_id = self::normalize_optional_selection_id( $args['selectionId'] ?? null, $selection_records );
		$scope_info   = self::resolve_replacement_scope( $target, $snapshot, $selection_records, $selection_id );
		$current      = $scope_info['current'];
		$scope        = $scope_info['scope'];
		$from         = isset( $args['from'] ) ? (string) $args['from'] : '';
		$to           = isset( $args['to'] ) ? (string) $args['to'] : '';
		$replace_all  = ! empty( $args['replaceAll'] );

		if ( '' === $from ) {
			if ( null !== $scope ) {
				throw new Ai_Tool_Error( 'replace_string.from cannot be empty inside a selection.', false );
			}
			if ( '' !== trim( $current ) ) {
				throw new Ai_Tool_Error( 'replace_string.from may be empty only when target is blank.', false );
			}
			$next = self::replace_snapshot_source( $snapshot, $target, $to );
			if ( 'html' === $target ) {
				foreach ( $selection_records as $record_id => $record ) {
					$selection_records[ $record_id ]['resolvable'] = false;
				}
			}
			return array(
				'output'               => array(
					'target'        => $target,
					'replacedCount' => 1,
					'replaceAll'    => false,
					'nextBaseHash'  => $next['baseHash'],
				),
				'snapshot'             => $next,
				'selectionRecords'     => $selection_records,
				'appliedEditOperation' => true,
			);
		}

		$count = self::count_occurrences( $current, $from );
		if ( 0 === $count ) {
			// Internal tool error surfaced to the model as JSON, not HTML output.
			throw new Ai_Tool_Error(
				'replace_string matched 0 occurrences in ' . $target . '.',
				true,
				self::build_replace_no_match_details(
					$target,
					$from,
					$current,
					isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '',
					$selection_id
				)
			);
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

		if ( null !== $scope ) {
			$full_source = self::get_snapshot_source( $snapshot, 'html' );
			$before      = self::utf16_slice( $full_source, 0, (int) $scope['startOffset'] );
			$after       = self::utf16_slice( $full_source, (int) $scope['endOffset'], self::utf16_length( $full_source ) - (int) $scope['endOffset'] );
			$replaced    = $before . $replaced . $after;
		}
		$next = self::replace_snapshot_source( $snapshot, $target, $replaced );
		if ( null !== $scope ) {
			$old_end  = (int) $scope['endOffset'];
			$new_body = self::utf16_slice( $replaced, (int) $scope['startOffset'], self::utf16_length( $replaced ) - self::utf16_length( $before ) - self::utf16_length( $after ) );
			$new_end  = (int) $scope['startOffset'] + self::utf16_length( $new_body );
			$delta    = $new_end - $old_end;
			foreach ( $selection_records as $record_id => $record ) {
				if ( $record_id === $selection_id ) {
					$selection_records[ $record_id ]['endOffset']   = $new_end;
					$selection_records[ $record_id ]['contentHash'] = hash( 'sha256', $new_body );
				} elseif ( isset( $record['startOffset'] ) && (int) $record['startOffset'] >= $old_end ) {
					$selection_records[ $record_id ]['startOffset'] = (int) $record['startOffset'] + $delta;
					$selection_records[ $record_id ]['endOffset']   = (int) $record['endOffset'] + $delta;
				} elseif ( isset( $record['endOffset'] ) && (int) $record['endOffset'] > (int) $scope['startOffset'] ) {
					$selection_records[ $record_id ]['resolvable'] = false;
				}
			}
		} elseif ( 'html' === $target ) {
			foreach ( $selection_records as $record_id => $record ) {
				$selection_records[ $record_id ]['resolvable'] = false;
			}
		}
		return array(
			'output'               => array(
				'target'        => $target,
				'replacedCount' => $replaced_count,
				'replaceAll'    => $replace_all,
				'nextBaseHash'  => $next['baseHash'],
			),
			'snapshot'             => $next,
			'selectionRecords'     => $selection_records,
			'appliedEditOperation' => true,
		);
	}

	/**
	 * Apply an ordered list of exact replacements (replace_many tool).
	 *
	 * @param array $args     Tool arguments (target, replacements[]).
	 * @param array $snapshot Snapshot array.
	 * @param array $selection_records Selection records keyed by ID.
	 * @return array Tool call result.
	 * @throws Ai_Tool_Error On empty list or a failed replacement step.
	 */
	public static function run_replace_many( array $args, array $snapshot, array $selection_records = array() ): array {
		$target       = (string) $args['target'];
		$replacements = isset( $args['replacements'] ) && is_array( $args['replacements'] ) ? $args['replacements'] : array();
		if ( 0 === count( $replacements ) ) {
			throw new Ai_Tool_Error( 'replace_many.replacements must not be empty.', false );
		}

		$current_snapshot = $snapshot;
		$total_replaced   = 0;
		$steps            = array();
		$index            = 0;
		$selection_id     = self::normalize_optional_selection_id( $args['selectionId'] ?? null, $selection_records );
		$original_scope   = self::resolve_replacement_scope( $target, $snapshot, $selection_records, $selection_id );
		foreach ( $replacements as $replacement ) {
			$replacement = is_array( $replacement ) ? $replacement : array();
			$failed_from = isset( $replacement['from'] ) ? (string) $replacement['from'] : '';
			try {
				$result = self::run_replace_string(
					array(
						'target'      => $target,
						'selectionId' => $selection_id,
						'from'        => $failed_from,
						'to'          => isset( $replacement['to'] ) ? (string) $replacement['to'] : '',
						'replaceAll'  => ! empty( $replacement['replaceAll'] ),
					),
					$current_snapshot,
					$selection_records
				);
			} catch ( Ai_Tool_Error $error ) {
				$details = $error->get_details();
				if ( isset( $details['code'] ) && 'replace_no_match' === $details['code'] ) {
					$details                          = self::build_replace_no_match_details(
						$target,
						$failed_from,
						$original_scope['current'],
						isset( $snapshot['baseHash'] ) ? (string) $snapshot['baseHash'] : '',
						$selection_id
					);
					$details['failedStepIndex']       = $index;
					$details['transactionRolledBack'] = true;
					throw new Ai_Tool_Error( $error->getMessage(), $error->is_retryable(), $details );
				}
				throw $error;
			}
			Ai_Output_Policy::assert_safe_transition( $current_snapshot, $result['snapshot'] );
			$current_snapshot  = isset( $result['snapshot'] ) ? $result['snapshot'] : $current_snapshot;
			$selection_records = isset( $result['selectionRecords'] ) ? $result['selectionRecords'] : $selection_records;
			$replaced_count    = isset( $result['output']['replacedCount'] ) ? (int) $result['output']['replacedCount'] : 0;
			$total_replaced   += $replaced_count;
			$steps[]           = array(
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
			'selectionRecords'     => $selection_records,
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
			case 'read_selection':
				return array(
					'output'               => self::run_read_selection( $args, $snapshot, is_array( $selected_contexts ) ? $selected_contexts : array() ),
					'appliedEditOperation' => false,
				);
			case 'replace_string':
				$args['target'] = self::parse_snapshot_target( $args['target'] ?? null, $allowed_targets );
				$result         = self::run_replace_string( $args, $snapshot, is_array( $selected_contexts ) ? $selected_contexts : array() );
				Ai_Output_Policy::assert_safe_transition( $snapshot, $result['snapshot'] );
				return $result;
			case 'replace_many':
				$args['target'] = self::parse_snapshot_target( $args['target'] ?? null, $allowed_targets );
				$result         = self::run_replace_many( $args, $snapshot, is_array( $selected_contexts ) ? $selected_contexts : array() );
				Ai_Output_Policy::assert_safe_transition( $snapshot, $result['snapshot'] );
				return $result;
			case 'set_js_mode':
				throw new Ai_Tool_Error( 'JavaScript mode is read-only for AI edits.', false );
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

	/** Clamp a requested read size.
	 *
	 * @param array $args Tool arguments.
	 * @return int
	 */
	private static function read_char_limit( array $args ): int {
		$value = isset( $args['maxChars'] ) && is_numeric( $args['maxChars'] ) ? (int) $args['maxChars'] : self::DEFAULT_READ_CHARS;
		return max( 1, min( self::MAX_READ_CHARS, $value ) );
	}

	/** Parse an optional read cursor, treating blank strings as omitted.
	 *
	 * @param mixed $value Raw cursor value.
	 * @return array|null
	 * @throws Ai_Tool_Error When a non-empty cursor is malformed.
	 */
	private static function parse_optional_cursor( $value ) {
		if ( null === $value ) {
			return null;
		}
		$cursor = trim( (string) $value );
		if ( '' === $cursor ) {
			return null;
		}
		return self::decode_cursor( $cursor );
	}

	/** Normalize an optional selection identifier.
	 *
	 * Placeholder values are tolerated only when no selection records exist,
	 * which keeps older model output safe while preserving strict scoping when
	 * a real selection is available.
	 *
	 * @param mixed $value             Raw selection identifier.
	 * @param array $selection_records Selection records keyed by ID.
	 * @return string
	 * @throws Ai_Tool_Error When a placeholder is used despite available selections.
	 */
	private static function normalize_optional_selection_id( $value, array $selection_records ): string {
		$selection_id = trim( (string) ( null === $value ? '' : $value ) );
		if ( '' === $selection_id ) {
			return '';
		}
		$lower = strtolower( $selection_id );
		if ( in_array( $lower, array( 'none', 'null' ), true ) ) {
			if ( 0 === count( $selection_records ) ) {
				return '';
			}
			throw new Ai_Tool_Error( 'Invalid selectionId. Omit selectionId for a global edit, or copy an available selectionId exactly.', false );
		}
		return $selection_id;
	}

	/** Encode an opaque pagination cursor.
	 *
	 * @param array $value Cursor payload.
	 * @return string
	 */
	private static function encode_cursor( array $value ): string {
		$json = wp_json_encode( $value );
		return rtrim( strtr( base64_encode( (string) $json ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination token, not code obfuscation.
	}

	/** Decode and validate an opaque pagination cursor.
	 *
	 * @param string $value Encoded cursor.
	 * @return array
	 * @throws Ai_Tool_Error When malformed.
	 */
	private static function decode_cursor( string $value ): array {
		$padding = strlen( $value ) % 4;
		if ( $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		$json = base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes our opaque pagination token.
		$data = is_string( $json ) ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) ) {
			throw new Ai_Tool_Error( 'Invalid read cursor. Omit cursor for the first page; for continuation, copy nextCursor exactly from the previous read response.', false );
		}
		return $data;
	}
}
