<?php
/**
 * Structural CSS validation for AI edits.
 *
 * A model that authors a whole stylesheet in one tool call can emit a stray or
 * missing brace. Nothing downstream notices: the tool call succeeds, the job
 * reports "completed", and the breakage only surfaces when the editor asks the
 * Tailwind compiler for CSS and gets `Unexpected closing }` back. In Tailwind
 * mode the preview keeps whatever compiled last, which for a new page is
 * nothing at all, so the page renders completely unstyled and saving fails.
 *
 * This checks bracket structure only. It deliberately does not attempt to
 * validate properties, at-rules or Tailwind utilities: the goal is to reject
 * source no CSS parser could accept, never to second-guess valid CSS.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bracket-balance validation for CSS the model writes.
 */
class Ai_Css_Syntax {

	/**
	 * Characters of surrounding source reported back to the model.
	 */
	const CONTEXT_CHARS = 80;

	/**
	 * Closing character expected for each opening bracket.
	 */
	const PAIRS = array(
		'{' => '}',
		'(' => ')',
		'[' => ']',
	);

	/**
	 * Locate the first bracket imbalance in a stylesheet.
	 *
	 * Comments and quoted strings are skipped so that `content: "}"` or a
	 * commented-out block never reads as structure.
	 *
	 * @param string $css CSS source.
	 * @return array|null Imbalance details, or null when the source is balanced.
	 */
	public static function find_imbalance( string $css ) {
		$length = strlen( $css );
		$stack  = array();

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '\\' === $char ) {
				++$i;
				continue;
			}

			if ( '/' === $char && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $length : $end + 1;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$i = self::skip_string( $css, $i, $char, $length );
				continue;
			}

			if ( isset( self::PAIRS[ $char ] ) ) {
				$stack[] = array(
					'closer' => self::PAIRS[ $char ],
					'opener' => $char,
					'offset' => $i,
				);
				continue;
			}

			if ( '}' !== $char && ')' !== $char && ']' !== $char ) {
				continue;
			}

			$open = array_pop( $stack );
			if ( null === $open ) {
				return self::imbalance(
					$css,
					$i,
					sprintf( 'Unexpected closing `%s` with no matching opening bracket.', $char )
				);
			}
			if ( $open['closer'] !== $char ) {
				return self::imbalance(
					$css,
					$i,
					sprintf(
						'Closing `%s` does not match the `%s` opened on line %d.',
						$char,
						$open['opener'],
						self::line_at( $css, $open['offset'] )
					)
				);
			}
		}

		$unclosed = array_pop( $stack );
		if ( null !== $unclosed ) {
			return self::imbalance(
				$css,
				$unclosed['offset'],
				sprintf( 'Unclosed `%s`; the matching `%s` is missing.', $unclosed['opener'], $unclosed['closer'] )
			);
		}

		return null;
	}

	/**
	 * Reject CSS the model just broke.
	 *
	 * Source that was already imbalanced before the edit is left alone: the
	 * model may be part-way through repairing it, and blaming it for damage it
	 * did not cause would only stall the loop.
	 *
	 * @param string $before CSS before the edit.
	 * @param string $after  CSS after the edit.
	 * @throws Ai_Tool_Error When the edit introduced a bracket imbalance.
	 */
	public static function assert_no_new_imbalance( string $before, string $after ): void {
		if ( $before === $after ) {
			return;
		}
		$imbalance = self::find_imbalance( $after );
		if ( null === $imbalance ) {
			return;
		}
		if ( null !== self::find_imbalance( $before ) ) {
			return;
		}

		throw new Ai_Tool_Error(
			'The CSS you wrote has unbalanced brackets and cannot compile: ' . $imbalance['message']
				. ' Re-read the CSS around line ' . $imbalance['line'] . ' and fix the bracket, then continue.',
			true,
			array_merge( array( 'code' => 'css_bracket_imbalance' ), $imbalance )
		);
	}

	/**
	 * Advance past a quoted string.
	 *
	 * @param string $css    CSS source.
	 * @param int    $start  Offset of the opening quote.
	 * @param string $quote  Quote character.
	 * @param int    $length Source length.
	 * @return int Offset of the closing quote, or the last consumed offset.
	 */
	private static function skip_string( string $css, int $start, string $quote, int $length ): int {
		for ( $i = $start + 1; $i < $length; $i++ ) {
			if ( '\\' === $css[ $i ] ) {
				++$i;
				continue;
			}
			if ( $css[ $i ] === $quote ) {
				return $i;
			}
		}
		return $length;
	}

	/**
	 * Build the reported imbalance record.
	 *
	 * @param string $css     CSS source.
	 * @param int    $offset  Offset of the offending bracket.
	 * @param string $message Human readable explanation.
	 * @return array
	 */
	private static function imbalance( string $css, int $offset, string $message ): array {
		return array(
			'message' => $message,
			'line'    => self::line_at( $css, $offset ),
			'context' => self::context_at( $css, $offset ),
		);
	}

	/**
	 * Resolve the 1-based line number of an offset.
	 *
	 * @param string $css    CSS source.
	 * @param int    $offset Byte offset.
	 * @return int
	 */
	private static function line_at( string $css, int $offset ): int {
		return substr_count( $css, "\n", 0, min( $offset, strlen( $css ) ) ) + 1;
	}

	/**
	 * Quote the source around an offset so the model can locate the bracket.
	 *
	 * @param string $css    CSS source.
	 * @param int    $offset Byte offset.
	 * @return string
	 */
	private static function context_at( string $css, int $offset ): string {
		$start   = max( 0, $offset - self::CONTEXT_CHARS );
		$excerpt = substr( $css, $start, self::CONTEXT_CHARS * 2 );

		// The excerpt is cut on byte boundaries, so trim any partial UTF-8
		// sequence at either edge before this reaches wp_json_encode().
		$excerpt = (string) preg_replace( '/^[\x80-\xBF]+/', '', $excerpt );
		while ( '' !== $excerpt && 1 !== preg_match( '//u', $excerpt ) ) {
			$excerpt = substr( $excerpt, 0, -1 );
		}

		return trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) );
	}
}
