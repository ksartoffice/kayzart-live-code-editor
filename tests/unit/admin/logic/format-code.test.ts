import { describe, expect, it } from 'vitest';
import { formatHtmlCode } from '../../../../src/admin/logic/format-code';

describe('formatHtmlCode', () => {
  it('formats HTML with two-space indentation', () => {
    expect(formatHtmlCode('<section><div>Hello</div></section>')).toBe(
      '<section>\n  <div>Hello</div>\n</section>'
    );
  });

  it('returns empty and whitespace-only input unchanged', () => {
    expect(formatHtmlCode('')).toBe('');
    expect(formatHtmlCode('   \n\t')).toBe('   \n\t');
  });
});
