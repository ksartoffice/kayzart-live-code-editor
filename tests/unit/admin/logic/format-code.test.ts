import { describe, expect, it } from 'vitest';
import {
  formatCssCode,
  formatHtmlCode,
  formatJavaScriptCode,
} from '../../../../src/admin/logic/format-code';

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

describe('formatCssCode', () => {
  it('formats CSS with two-space indentation', () => {
    expect(formatCssCode('.card{color:red;background:white;}')).toBe(
      '.card {\n  color: red;\n  background: white;\n}'
    );
  });

  it('returns empty and whitespace-only input unchanged', () => {
    expect(formatCssCode('')).toBe('');
    expect(formatCssCode('   \n\t')).toBe('   \n\t');
  });
});

describe('formatJavaScriptCode', () => {
  it('formats JavaScript with two-space indentation', () => {
    expect(formatJavaScriptCode('function demo(){return 1;}')).toBe(
      'function demo() {\n  return 1;\n}'
    );
  });

  it('returns empty and whitespace-only input unchanged', () => {
    expect(formatJavaScriptCode('')).toBe('');
    expect(formatJavaScriptCode('   \n\t')).toBe('   \n\t');
  });
});
