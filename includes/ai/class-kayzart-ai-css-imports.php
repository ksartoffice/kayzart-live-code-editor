<?php
/**
 * Entry-import validation for AI edits to Tailwind CSS source.
 *
 * Tailwind mode compiles the CSS tab as Tailwind input, and everything the
 * compiler emits hangs off the `@import "tailwindcss";` line. Drop that line and
 * the compiler still succeeds; it just produces no utility classes at all. The
 * tool call reports success, the job completes, the save returns 200, and the
 * page renders completely unstyled with no error anywhere. That silence is what
 * makes this worth a guard: unlike a broken brace, nothing downstream ever
 * complains.
 *
 * This is a deliberately separate concern from Ai_Css_Syntax, which validates
 * bracket structure and refuses to second-guess otherwise valid CSS. A
 * stylesheet missing this import is perfectly well-formed.
 *
 * @package KayzArt
 */

namespace KayzArt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards the Tailwind entry import in CSS the model writes.
 */
class Ai_Css_Imports {

	/**
	 * Matches the Tailwind entry import in either quoting or url() form.
	 *
	 * Kept in sync by hand with TAILWIND_IMPORT_PATTERN in
	 * src/admin/logic/css-mode.ts, which decides when the editor seeds a page
	 * with the default Tailwind source.
	 *
	 * The closing quote must follow `tailwindcss` directly, so a sub-import such
	 * as `@import "tailwindcss/theme";` does not count as the entry import.
	 */
	const TAILWIND_IMPORT_PATTERN = '/@import\s+(?:url\(\s*)?["\']tailwindcss["\']/i';

	/**
	 * Matches a CSS block comment, including one spanning several lines.
	 */
	const COMMENT_PATTERN = '!/\*.*?\*/!s';

	/**
	 * Whether a stylesheet pulls in the Tailwind entry point.
	 *
	 * Comments are removed first. A commented-out import still matches the
	 * pattern but means nothing to the compiler, which would then emit no
	 * utilities at all -- precisely the silent failure this class exists to
	 * catch.
	 *
	 * Stripping comments without tracking string literals is imprecise in
	 * general, but harmless here: an @import is only honoured before any rule,
	 * so no string that could open a comment can legitimately precede it. In the
	 * pathological case the cost is a retryable message asking the model to
	 * restore an import it already has, against CSS that was broken regardless.
	 *
	 * @param string $css CSS source.
	 * @return bool
	 */
	public static function has_tailwind_import( string $css ): bool {
		if ( '' === $css ) {
			return false;
		}
		$without_comments = preg_replace( self::COMMENT_PATTERN, '', $css );
		if ( ! is_string( $without_comments ) ) {
			$without_comments = $css;
		}
		return 1 === preg_match( self::TAILWIND_IMPORT_PATTERN, $without_comments );
	}

	/**
	 * Reject an edit that dropped the Tailwind entry import.
	 *
	 * Differential by design: a stylesheet that never had the import is a plain
	 * CSS page and is none of this guard's business. That keeps the check free
	 * of any editor-mode plumbing, which the tool layer does not have.
	 *
	 * @param string $before CSS before the edit.
	 * @param string $after  CSS after the edit.
	 * @throws Ai_Tool_Error When the edit removed the Tailwind entry import.
	 */
	public static function assert_tailwind_import_kept( string $before, string $after ): void {
		if ( $before === $after ) {
			return;
		}
		if ( ! self::has_tailwind_import( $before ) ) {
			return;
		}
		if ( self::has_tailwind_import( $after ) ) {
			return;
		}

		throw new Ai_Tool_Error(
			'Your edit removed `@import "tailwindcss";` from the CSS. Without that line the compiler'
				. ' emits no utility classes at all and the page renders unstyled. Restore the import at the'
				. ' top of the CSS, then continue.',
			true,
			array( 'code' => 'css_tailwind_import_removed' )
		);
	}
}
