import { describe, expect, it } from 'vitest';
import { validateImportPayload } from '../../../../src/admin/setup-wizard/validate-import-payload';

describe('validateImportPayload', () => {
  it('accepts valid payload', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      js: 'console.log(1);',
      jsMode: 'module',
      externalScripts: ['https://example.com/a.js'],
      externalStyles: ['https://example.com/a.css'],
      liveHighlightEnabled: true,
    });

    expect(result.error).toBeUndefined();
    expect(result.data).toBeTruthy();
    expect(result.data?.version).toBe(1);
  });

  it('rejects unsupported version', () => {
    const result = validateImportPayload({
      version: 2,
      html: '<div>Hello</div>',
      css: 'body{}',
    });

    expect(result.error).toBe('Unsupported import version.');
  });

  it('rejects invalid externalScripts', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      externalScripts: ['https://example.com/a.js', 1],
    });

    expect(result.error).toBe('Invalid externalScripts value.');
  });

  it('rejects invalid jsMode', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      jsMode: 'esm',
    });

    expect(result.error).toBe('Invalid jsMode value.');
  });

  it('accepts legacy auto jsMode and normalizes it to classic', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      jsMode: 'auto',
    });

    expect(result.error).toBeUndefined();
    expect(result.data?.jsMode).toBe('classic');
  });

  it('ignores removed legacy embed fields', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      shortcodeEnabled: 1,
      singlePageEnabled: 'true',
    });

    expect(result.error).toBeUndefined();
    expect(result.data).not.toHaveProperty('shortcodeEnabled');
    expect(result.data).not.toHaveProperty('singlePageEnabled');
  });

  it('imports legacy Tailwind JSON as normal CSS using generatedCss', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div class="text-sm">Hello</div>',
      css: '@import "tailwindcss";',
      tailwindEnabled: true,
      generatedCss: '.text-sm{font-size:.875rem;}',
    });

    expect(result.error).toBeUndefined();
    expect(result.data?.css).toBe('.text-sm{font-size:.875rem;}');
    expect(result.data).not.toHaveProperty('tailwindEnabled');
    expect(result.data).not.toHaveProperty('generatedCss');
  });
});
