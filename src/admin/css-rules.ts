export type CssRuleInfo = {
  selectorText: string;
  startOffset: number;
  endOffset: number;
  mediaQueries: string[];
};

export function splitSelectors(selectorText: string): string[] {
  const result: string[] = [];
  let buffer = '';
  let inString: string | null = null;
  let parenDepth = 0;
  let bracketDepth = 0;

  for (let i = 0; i < selectorText.length; i++) {
    const char = selectorText[i];
    if (inString) {
      if (char === '\\') {
        buffer += char;
        i += 1;
        if (i < selectorText.length) {
          buffer += selectorText[i];
        }
        continue;
      }
      if (char === inString) {
        inString = null;
      }
      buffer += char;
      continue;
    }

    if (char === '"' || char === "'") {
      inString = char;
      buffer += char;
      continue;
    }

    if (char === '(') {
      parenDepth += 1;
      buffer += char;
      continue;
    }

    if (char === ')') {
      parenDepth = Math.max(0, parenDepth - 1);
      buffer += char;
      continue;
    }

    if (char === '[') {
      bracketDepth += 1;
      buffer += char;
      continue;
    }

    if (char === ']') {
      bracketDepth = Math.max(0, bracketDepth - 1);
      buffer += char;
      continue;
    }

    if (char === ',' && parenDepth === 0 && bracketDepth === 0) {
      const trimmed = buffer.trim();
      if (trimmed) {
        result.push(trimmed);
      }
      buffer = '';
      continue;
    }

    buffer += char;
  }

  const trimmed = buffer.trim();
  if (trimmed) {
    result.push(trimmed);
  }

  return result;
}

export function parseCssRules(cssText: string): CssRuleInfo[] {
  const rules: CssRuleInfo[] = [];
  const stack: Array<{
    type: 'rule' | 'at-rule';
    selectorText?: string;
    startOffset: number;
    atRuleName?: string;
    mediaQueries?: string[];
  }> = [];
  const mediaStack: string[] = [];
  let preludeStart = 0;
  let inComment = false;
  let inString: string | null = null;
  let ruleDepth = 0;

  const pushRule = (selectorText: string, startOffset: number) => {
    stack.push({
      type: 'rule',
      selectorText,
      startOffset,
      mediaQueries: [...mediaStack],
    });
    ruleDepth += 1;
  };

  const pushAtRule = (name: string, startOffset: number, params: string) => {
    stack.push({ type: 'at-rule', atRuleName: name, startOffset });
    if (name === 'media') {
      mediaStack.push(params.trim());
    }
  };

  for (let i = 0; i < cssText.length; i++) {
    const char = cssText[i];
    const next = cssText[i + 1];

    if (inComment) {
      if (char === '*' && next === '/') {
        inComment = false;
        i += 1;
      }
      continue;
    }

    if (inString) {
      if (char === '\\') {
        i += 1;
        continue;
      }
      if (char === inString) {
        inString = null;
      }
      continue;
    }

    if (char === '/' && next === '*') {
      inComment = true;
      i += 1;
      continue;
    }

    if (char === '"' || char === "'") {
      inString = char;
      continue;
    }

    if (char === '{') {
      const rawPrelude = cssText.slice(preludeStart, i);
      const prelude = rawPrelude.trim();
      const preludeOffset = rawPrelude.search(/\S/);
      const actualPreludeStart =
        preludeOffset >= 0 ? preludeStart + preludeOffset : preludeStart;
      if (prelude) {
        if (prelude.startsWith('@')) {
          const match = /^@([\\w-]+)\\s*(.*)$/.exec(prelude);
          const name = match ? match[1].toLowerCase() : '';
          const params = match ? match[2] : '';
          pushAtRule(name, actualPreludeStart, params);
        } else {
          pushRule(prelude, actualPreludeStart);
        }
      } else {
        stack.push({ type: 'at-rule', atRuleName: '', startOffset: preludeStart });
      }
      preludeStart = i + 1;
      continue;
    }

    if (char === '}') {
      const ctx = stack.pop();
      if (ctx?.type === 'rule') {
        ruleDepth = Math.max(0, ruleDepth - 1);
        rules.push({
          selectorText: ctx.selectorText || '',
          startOffset: ctx.startOffset,
          endOffset: i + 1,
          mediaQueries: ctx.mediaQueries || [],
        });
      } else if (ctx?.type === 'at-rule' && ctx.atRuleName === 'media') {
        mediaStack.pop();
      }
      preludeStart = i + 1;
      continue;
    }

    if (char === ';' && ruleDepth === 0) {
      preludeStart = i + 1;
      continue;
    }
  }

  return rules;
}

export function selectorMatches(element: Element, selector: string): boolean {
  const trimmed = selector.trim();
  if (!trimmed) return false;
  try {
    return element.matches(trimmed);
  } catch (error) {
    const cleaned = trimmed.replace(
      /::?(before|after|first-line|first-letter|selection|placeholder|marker|backdrop|file-selector-button|cue|part\\([^)]*\\)|slotted\\([^)]*\\))/gi,
      ''
    );
    if (cleaned !== trimmed) {
      try {
        return element.matches(cleaned);
      } catch {
        return false;
      }
    }
    return false;
  }
}

export function mediaQueriesMatch(queries: string[]): boolean {
  if (!queries.length) return true;
  return queries.every((query) => {
    if (!query) return true;
    try {
      return window.matchMedia(query).matches;
    } catch {
      return true;
    }
  });
}
