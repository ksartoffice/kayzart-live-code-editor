import { describe, expect, it } from 'vitest';
import { validateImportPayload } from '../../../../src/admin/setup-wizard/validate-import-payload';

describe('validateImportPayload', () => {
  it('accepts valid payload', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      tailwindEnabled: false,
      js: 'console.log(1);',
      jsMode: 'module',
      externalScripts: ['https://example.com/a.js'],
      externalStyles: ['https://example.com/a.css'],
      shadowDomEnabled: true,
      shortcodeEnabled: false,
      singlePageEnabled: true,
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
      tailwindEnabled: false,
    });

    expect(result.error).toBe('Unsupported import version.');
  });

  it('rejects invalid externalScripts', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      tailwindEnabled: false,
      externalScripts: ['https://example.com/a.js', 1],
    });

    expect(result.error).toBe('Invalid externalScripts value.');
  });

  it('rejects invalid jsMode', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      tailwindEnabled: false,
      jsMode: 'esm',
    });

    expect(result.error).toBe('Invalid jsMode value.');
  });

  it('accepts legacy auto jsMode and normalizes it to classic', () => {
    const result = validateImportPayload({
      version: 1,
      html: '<div>Hello</div>',
      css: 'body{}',
      tailwindEnabled: false,
      jsMode: 'auto',
    });

    expect(result.error).toBeUndefined();
    expect(result.data?.jsMode).toBe('classic');
  });
});
