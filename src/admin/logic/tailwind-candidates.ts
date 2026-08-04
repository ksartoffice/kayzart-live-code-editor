const CLASS_ATTRIBUTE_PATTERNS = [
  /class\s*=\s*["']([^"']+)["']/g,
  /className\s*=\s*["']([^"']+)["']/g,
];

/**
 * Mirror TailwindPHP 1.3.2.2 candidate extraction while avoiding duplicate
 * utility names in compile requests.
 */
export function extractTailwindCandidates(html: string): string[] {
  const candidates: string[] = [];
  const seen = new Set<string>();

  for (const pattern of CLASS_ATTRIBUTE_PATTERNS) {
    pattern.lastIndex = 0;
    let match: RegExpExecArray | null;
    while ((match = pattern.exec(html)) !== null) {
      for (const token of match[1].split(/\s+/)) {
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
