export type ReplaceMatchResolveErrorCode =
  | 'INVALID_REQUEST'
  | 'NOT_FOUND'
  | 'AMBIGUOUS_MATCH'
  | 'SCOPE_NOT_FOUND'
  | 'AMBIGUOUS_SCOPE';

export type ReplaceMatchResolveResult =
  | {
      ok: true;
      startOffset: number;
      endOffset: number;
    }
  | {
      ok: false;
      code: ReplaceMatchResolveErrorCode;
      message: string;
    };

function findMatchOffsets(
  text: string,
  query: string,
  startOffset = 0,
  endOffset = text.length
): number[] {
  if (query.length === 0) {
    return [];
  }

  const matches: number[] = [];
  let cursor = startOffset;
  while (cursor <= endOffset - query.length) {
    const next = text.indexOf(query, cursor);
    if (next === -1 || next + query.length > endOffset) {
      break;
    }
    matches.push(next);
    cursor = next + 1;
  }
  return matches;
}

export function resolveReplaceMatchRange(
  currentText: string,
  beforeText: string,
  scopeText?: string
): ReplaceMatchResolveResult {
  if (beforeText.length === 0) {
    return {
      ok: false,
      code: 'INVALID_REQUEST',
      message: 'replace_match.beforeText must be a non-empty string.',
    };
  }

  let searchStart = 0;
  let searchEnd = currentText.length;

  if (scopeText !== undefined) {
    if (scopeText.length === 0) {
      return {
        ok: false,
        code: 'INVALID_REQUEST',
        message: 'replace_match.scopeText must be a non-empty string when provided.',
      };
    }

    const scopeMatches = findMatchOffsets(currentText, scopeText);
    if (scopeMatches.length === 0) {
      return {
        ok: false,
        code: 'SCOPE_NOT_FOUND',
        message: 'replace_match.scopeText was not found in the target document.',
      };
    }
    if (scopeMatches.length > 1) {
      return {
        ok: false,
        code: 'AMBIGUOUS_SCOPE',
        message: 'replace_match.scopeText matched multiple regions in the target document.',
      };
    }

    searchStart = scopeMatches[0];
    searchEnd = searchStart + scopeText.length;
  }

  const beforeMatches = findMatchOffsets(
    currentText,
    beforeText,
    searchStart,
    searchEnd
  );
  if (beforeMatches.length === 0) {
    return {
      ok: false,
      code: 'NOT_FOUND',
      message: 'replace_match.beforeText was not found in the selected search scope.',
    };
  }
  if (beforeMatches.length > 1) {
    return {
      ok: false,
      code: 'AMBIGUOUS_MATCH',
      message: 'replace_match.beforeText matched multiple regions. Add scopeText and retry.',
    };
  }

  const startOffset = beforeMatches[0];
  return {
    ok: true,
    startOffset,
    endOffset: startOffset + beforeText.length,
  };
}
