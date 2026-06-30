export type TextRange = {
  from: number;
  to: number;
};

const normalizeRange = (range: TextRange): TextRange =>
  range.from <= range.to ? range : { from: range.to, to: range.from };

const isInsideDoubleQuotedValue = (content: string, offset: number): boolean => {
  let quoteCount = 0;
  for (let i = 0; i < offset; i += 1) {
    if (content[i] === '"') {
      quoteCount += 1;
    }
  }
  return quoteCount % 2 === 1;
};

export function resolveQuotedValueReplacementRange(
  content: string,
  selection: TextRange
): TextRange | null {
  const range = normalizeRange(selection);
  const from = Math.max(0, Math.min(content.length, range.from));
  const to = Math.max(0, Math.min(content.length, range.to));

  if (
    isInsideDoubleQuotedValue(content, from) &&
    content[from - 1] === '"' &&
    content[to] === '"'
  ) {
    return { from, to };
  }

  if (from !== to || !isInsideDoubleQuotedValue(content, from)) {
    return null;
  }

  const openingQuote = content.lastIndexOf('"', from - 1);
  if (openingQuote < 0) {
    return null;
  }

  const closingQuote = content.indexOf('"', from);
  if (closingQuote < 0) {
    return null;
  }

  return {
    from: openingQuote + 1,
    to: closingQuote,
  };
}
