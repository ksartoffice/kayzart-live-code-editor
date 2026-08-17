<?php
/**
 * Tailwind-idiom validation for AI edits to Tailwind CSS source.
 *
 * In Tailwind mode the CSS tab is compiler input, not a plain stylesheet. A
 * model left to itself reaches for the CSS it has seen most and writes a bare
 * `.btn { background: #f00 }` rule, which compiles and renders but sits outside
 * the design system: it does not respond to `@theme` tokens, it is invisible to
 * every utility class on the page, and the next edit that goes through the
 * tokens silently disagrees with it. The page ends up styled two ways at once.
 *
 * The first attempt at preventing that removed `css` from the editable targets
 * unless the prompt happened to contain a keyword. It stopped the bare rules,
 * but it also stopped the legitimate use of the CSS tab -- a request such as
 * "change the button colour everywhere" is a `@theme` token change and nothing
 * else -- and it taught the model nothing, because a target missing from the
 * schema cannot explain itself. So the gate moved here: the CSS tab is open
 * again, and what is rejected is the bare rule itself, with a retryable message
 * naming the Tailwind construct to use instead. The model corrects itself
 * within the same job.
 *
 * Differential by design, like Ai_Css_Imports: only selectors the edit *added*
 * are rejected. A page carrying hand-written CSS from before it moved to
 * Tailwind stays editable, and adjusting a value inside such a rule is allowed.
 * Nesting inside an at-rule is allowed too, because `@media`, `@layer` and
 * `@utility` bodies are the sanctioned places for real declarations.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards the Tailwind authoring idiom in CSS the model writes.
 */
class Ai_Tailwind_Css_Policy {

	/**
	 * Maximum number of rejected selectors quoted back to the model.
	 *
	 * One bad rule is a mistake to correct; twenty are the same mistake, and
	 * listing them all only buries the instruction that follows.
	 */
	const REPORTED_SELECTORS = 3;

	/**
	 * Maximum characters kept from a single selector in the message.
	 */
	const SELECTOR_CHARS = 120;

	/**
	 * Collect the selectors of every top-level bare rule in a stylesheet.
	 *
	 * "Top-level" means depth zero: a rule nested inside `@media`, `@layer`,
	 * `@utility` or any other at-rule body is that at-rule's business. "Bare"
	 * means the prelude is a selector rather than an at-rule, so `@theme { ... }`
	 * is skipped along with everything inside it.
	 *
	 * Comments and quoted strings are skipped so that a commented-out rule or a
	 * `content: "{"` never reads as structure. Source with unbalanced braces
	 * yields whatever was parseable; Ai_Css_Syntax rejects that case separately
	 * and its message is the more actionable one.
	 *
	 * @param string $css CSS source.
	 * @return array<int,string> Normalized selectors, in source order.
	 */
	public static function top_level_selectors( string $css ): array {
		$length    = strlen( $css );
		$depth     = 0;
		$prelude   = '';
		$selectors = array();

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '\\' === $char ) {
				if ( 0 === $depth ) {
					$prelude .= substr( $css, $i, 2 );
				}
				++$i;
				continue;
			}

			if ( '/' === $char && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $length : $end + 1;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$end = self::skip_string( $css, $i, $char, $length );
				if ( 0 === $depth ) {
					$prelude .= substr( $css, $i, $end - $i + 1 );
				}
				$i = $end;
				continue;
			}

			if ( '{' === $char ) {
				if ( 0 === $depth ) {
					$selector = self::normalize_selector( $prelude );
					if ( '' !== $selector && '@' !== $selector[0] ) {
						$selectors[] = $selector;
					}
				}
				++$depth;
				$prelude = '';
				continue;
			}

			if ( '}' === $char ) {
				$depth   = max( 0, $depth - 1 );
				$prelude = '';
				continue;
			}

			if ( ';' === $char && 0 === $depth ) {
				// A top-level statement such as `@import "tailwindcss";` ends here
				// and never opens a block, so it must not leak into the next
				// prelude.
				$prelude = '';
				continue;
			}

			if ( 0 === $depth ) {
				$prelude .= $char;
			}
		}

		return $selectors;
	}

	/**
	 * Reject an edit that introduced hand-written CSS rules in Tailwind source.
	 *
	 * Gated on the Tailwind entry import being present before the edit, which is
	 * what makes a stylesheet Tailwind source. That keeps this free of any
	 * editor-mode plumbing the tool layer does not have, and matches how
	 * Ai_Css_Imports scopes itself.
	 *
	 * @param string $before CSS before the edit.
	 * @param string $after  CSS after the edit.
	 * @throws Ai_Tool_Error When the edit added a top-level bare CSS rule.
	 */
	public static function assert_no_adhoc_rules( string $before, string $after ): void {
		if ( $before === $after ) {
			return;
		}
		if ( ! Ai_Css_Imports::has_tailwind_import( $before ) ) {
			return;
		}

		$existing = self::top_level_selectors( $before );
		$added    = array();
		foreach ( self::top_level_selectors( $after ) as $selector ) {
			$known = array_search( $selector, $existing, true );
			if ( false !== $known ) {
				// Consume the match so a rule duplicated by the edit still counts
				// as one addition.
				unset( $existing[ $known ] );
				continue;
			}
			if ( ! in_array( $selector, $added, true ) ) {
				$added[] = $selector;
			}
		}

		if ( 0 === count( $added ) ) {
			return;
		}

		throw new Ai_Tool_Error(
			'Your edit added plain CSS rules to Tailwind source: ' . self::quote_selectors( $added ) . '.'
				. ' Rules written outside the Tailwind system do not follow @theme tokens and conflict with the'
				. ' utility classes on the page. Remove them and express the change the Tailwind way instead:'
				. ' a site-wide value such as a colour, font or spacing step belongs in a @theme token;'
				. ' a reusable class belongs in @utility; a one-off belongs in utility classes on the HTML'
				. ' element. Then continue.',
			true,
			array(
				'code'      => 'css_adhoc_rule_added',
				'selectors' => array_slice( $added, 0, self::REPORTED_SELECTORS ),
			)
		);
	}

	/**
	 * Reduce a rule prelude to a comparable selector.
	 *
	 * Whitespace is collapsed so that reformatting an existing rule does not
	 * read as a new one.
	 *
	 * @param string $prelude Raw text preceding the opening brace.
	 * @return string
	 */
	private static function normalize_selector( string $prelude ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $prelude ) );
	}

	/**
	 * Quote the rejected selectors for the error message.
	 *
	 * @param array<int,string> $selectors Added selectors.
	 * @return string
	 */
	private static function quote_selectors( array $selectors ): string {
		$shown = array_slice( $selectors, 0, self::REPORTED_SELECTORS );
		$parts = array();
		foreach ( $shown as $selector ) {
			$parts[] = '`' . self::truncate( $selector ) . '`';
		}

		$list      = implode( ', ', $parts );
		$remaining = count( $selectors ) - count( $shown );
		if ( $remaining > 0 ) {
			$list .= ' and ' . $remaining . ' more';
		}

		return $list;
	}

	/**
	 * Shorten a long selector without splitting a UTF-8 sequence.
	 *
	 * @param string $selector Normalized selector.
	 * @return string
	 */
	private static function truncate( string $selector ): string {
		if ( mb_strlen( $selector ) <= self::SELECTOR_CHARS ) {
			return $selector;
		}
		return mb_substr( $selector, 0, self::SELECTOR_CHARS ) . '...';
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
		return $length - 1;
	}
}
