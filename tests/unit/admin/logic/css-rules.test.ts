import { describe, expect, it } from 'vitest';
import { parseCssRules } from '../../../../src/admin/css-rules';

describe('parseCssRules', () => {
  it('starts a rule range at the selector instead of the previous rule close brace', () => {
    const css = [
      '.flow-list strong {',
      '  display: block;',
      '}',
      '',
      '.flow-list p {',
      '  margin: 8px 0;',
      '}',
      '',
    ].join('\n');

    const rules = parseCssRules(css);
    expect(rules[1].startOffset).toBe(css.indexOf('.flow-list p'));
    expect(css[rules[1].startOffset]).toBe('.');
  });
});
