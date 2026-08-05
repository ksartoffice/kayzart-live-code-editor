/**
 * One alternative per delimiter rather than a single `["']` class.
 *
 * A double-quoted attribute value may legally contain apostrophes and vice
 * versa, and Tailwind v4 arbitrary values use both: `font-['Noto_Sans_JP']`,
 * `bg-[url("a.png")]`, `before:content-['x']`. Matching `[^"']+` stops at the
 * first inner quote, which yields a truncated candidate and silently drops
 * every remaining class in that attribute.
 *
 * A backreference (`(["'])(.*?)\1`) would read better but lets an unbalanced
 * quote anywhere in the document run to the next matching quote and flood the
 * candidate list; bounded classes keep the failure local.
 *
 * Kept in sync by hand with Tailwind_Compiler::extract_candidates().
 */
const CLASS_ATTRIBUTE_PATTERNS = [
  /class\s*=\s*(?:"([^"]*)"|'([^']*)')/g,
  /className\s*=\s*(?:"([^"]*)"|'([^']*)')/g,
];

/**
 * Extract Tailwind candidates from class attributes, avoiding duplicate
 * utility names in compile requests.
 */
export function extractTailwindCandidates(html: string): string[] {
  const candidates: string[] = [];
  const seen = new Set<string>();

  for (const pattern of CLASS_ATTRIBUTE_PATTERNS) {
    pattern.lastIndex = 0;
    let match: RegExpExecArray | null;
    while ((match = pattern.exec(html)) !== null) {
      const value = match[1] ?? match[2] ?? '';
      for (const token of value.split(/\s+/)) {
        const candidate = token.trim();
        if (!candidate || seen.has(candidate)) continue;
        seen.add(candidate);
        candidates.push(candidate);
      }
    }
  }

  return candidates;
}

export function createTailwindCompileSignature(candidates: string[], css: string): string {
  return JSON.stringify([css, candidates]);
}
