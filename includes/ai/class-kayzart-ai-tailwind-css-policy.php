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
	 * Selector combinators whose surrounding space is optional in CSS.
	 *
	 * The descendant combinator is deliberately absent: it *is* the space, so
	 * canonicalizing it away would merge distinct selectors.
	 */
	const COMBINATORS = ',>+~';

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
		$scan = self::scan( $css );
		return $scan['selectors'];
	}

	/**
	 * Whether a stylesheet pulls in the Tailwind entry point for real.
	 *
	 * Ai_Css_Imports answers the same question with a regex over the whole
	 * source. That is right for its own job -- it only ever fires when an edit
	 * removed an import that was already there, so a false positive costs a
	 * message about an import the page still has. Here the answer decides
	 * whether an entire rule set applies to the page, and a false positive would
	 * reject ordinary CSS on a page that never used Tailwind. The literal text
	 * inside `content: '@import "tailwindcss"'` is enough to trigger that,
	 * because the regex does not skip quoted strings.
	 *
	 * So this asks the stricter question the decision deserves: is there a live
	 * top-level `@import` statement, outside any comment, string, or block? That
	 * is the only position where the compiler honours the entry import anyway.
	 *
	 * @param string $css CSS source.
	 * @return bool
	 */
	public static function has_live_tailwind_import( string $css ): bool {
		foreach ( self::scan( $css )['statements'] as $statement ) {
			if ( 1 === preg_match( Ai_Css_Imports::TAILWIND_IMPORT_PATTERN, $statement ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Walk a stylesheet once, collecting rules and top-level statements.
	 *
	 * Both callers need the same comment- and string-aware pass, and they must
	 * agree on what counts as structure, so they share one scanner.
	 *
	 * Block kind is tracked rather than raw brace depth. An at-rule body is its
	 * at-rule's business and everything inside it is skipped, nested rules
	 * included. A bare rule's body is not: CSS nesting means a whole plain rule
	 * can be written inside an existing one, and a guard that only ever looked
	 * at depth zero would wave it through on any page carrying legacy CSS. So a
	 * nested rule is collected too, keyed by its ancestor chain, which keeps it
	 * distinct from a rule of the same name written elsewhere.
	 *
	 * A block whose prelude is empty is treated as opaque like an at-rule. That
	 * is not valid CSS, and guessing at its contents would be inventing
	 * structure the source does not have.
	 *
	 * Source with unbalanced braces yields whatever was parseable; Ai_Css_Syntax
	 * rejects that case separately and its message is the more actionable one.
	 *
	 * @param string $css CSS source.
	 * @return array{selectors:array<int,string>,statements:array<int,string>}
	 */
	private static function scan( string $css ): array {
		$length     = strlen( $css );
		$prelude    = '';
		$blocks     = array();
		$ancestors  = array();
		$at_depth   = 0;
		$selectors  = array();
		$statements = array();

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '\\' === $char ) {
				$prelude .= substr( $css, $i, 2 );
				++$i;
				continue;
			}

			if ( '/' === $char && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = ( false === $end ) ? $length : $end + 1;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$end      = self::skip_string( $css, $i, $char, $length );
				$prelude .= substr( $css, $i, $end - $i + 1 );
				$i        = $end;
				continue;
			}

			if ( '{' === $char ) {
				$selector = self::normalize_selector( $prelude );
				$is_rule  = '' !== $selector && '@' !== $selector[0];

				if ( $is_rule ) {
					$ancestors[] = $selector;
					if ( 0 === $at_depth ) {
						$selectors[] = implode( ' ', $ancestors );
					}
				} else {
					++$at_depth;
				}

				$blocks[] = $is_rule;
				$prelude  = '';
				continue;
			}

			if ( '}' === $char ) {
				if ( count( $blocks ) > 0 ) {
					if ( array_pop( $blocks ) ) {
						array_pop( $ancestors );
					} else {
						--$at_depth;
					}
				}
				$prelude = '';
				continue;
			}

			if ( ';' === $char ) {
				// A top-level statement such as `@import "tailwindcss";` ends
				// here and never opens a block, so it must not leak into the
				// next prelude. Inside any block it is a declaration.
				if ( 0 === count( $blocks ) ) {
					$statement = trim( $prelude );
					if ( '' !== $statement ) {
						$statements[] = $statement;
					}
				}
				$prelude = '';
				continue;
			}

			$prelude .= $char;
		}

		return array(
			'selectors'  => $selectors,
			'statements' => $statements,
		);
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
		if ( ! self::has_live_tailwind_import( $before ) ) {
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
	 * The result is only ever used as a comparison key and as message text, so
	 * it is canonicalized rather than merely tidied. Collapsing whitespace runs
	 * is not enough on its own: a model that rewrites an existing rule commonly
	 * respaces its selector on the way past, and `.a,.b` reformatted to `.a, .b`
	 * would then read as a rule it had just added -- exactly the legacy-CSS case
	 * this guard promises to stay out of. So the optional space around each
	 * combinator is dropped too, which makes every spelling of one selector
	 * compare equal.
	 *
	 * Canonicalization runs in the same pass that steps over strings, never as a
	 * prepass: whitespace inside a quoted value is significant. `[data-x="a  b"]`
	 * and `[data-x="a b"]` match different attribute values and must not share a
	 * key, or swapping one for the other would read as no change at all.
	 * Combinators are canonicalized at any nesting depth outside strings, which
	 * also settles `:not(.a, .b)` against `:not(.a,.b)`.
	 *
	 * A descendant combinator is a real space and stays one, so `.a .b` remains
	 * distinct from `.a.b`. Going further would collapse two different selectors
	 * onto one key and let a genuinely new rule through.
	 *
	 * @param string $prelude Raw text preceding the opening brace.
	 * @return string
	 */
	private static function normalize_selector( string $prelude ): string {
		$length    = strlen( $prelude );
		$canonical = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $prelude[ $i ];

			if ( '\\' === $char ) {
				$canonical .= substr( $prelude, $i, 2 );
				++$i;
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$end        = self::skip_string( $prelude, $i, $char, $length );
				$canonical .= substr( $prelude, $i, $end - $i + 1 );
				$i          = $end;
				continue;
			}

			if ( ' ' === $char || "\t" === $char || "\n" === $char || "\r" === $char || "\f" === $char ) {
				$last = ( '' === $canonical ) ? '' : substr( $canonical, -1 );
				// Leading space, a run already reduced to one, and the space
				// after a combinator all collapse away.
				if ( '' !== $last && ' ' !== $last && false === strpos( self::COMBINATORS, $last ) ) {
					$canonical .= ' ';
				}
				continue;
			}

			if ( false !== strpos( self::COMBINATORS, $char ) ) {
				$canonical = rtrim( $canonical, ' ' ) . $char;
				continue;
			}

			$canonical .= $char;
		}

		return rtrim( $canonical, ' ' );
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
