import { describe, expect, it } from 'vitest';
import {
  buildImportedHtml,
  createFullHtmlImportSelection,
  isFullHtmlDocumentPaste,
  parseFullHtmlDocument,
} from '../../../../src/admin/logic/full-html-import';

describe('full html import logic', () => {
  it('ignores normal html fragments', () => {
    expect(isFullHtmlDocumentPaste('<section><h1>Hello</h1></section>')).toBe(false);
    expect(parseFullHtmlDocument('<section><h1>Hello</h1></section>')).toBeNull();
  });

  it('extracts body html, styles, and inline scripts from a full document', () => {
    const result = parseFullHtmlDocument(`<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <style>.a { color: red; }</style>
  <style>.b { color: blue; }</style>
</head>
<body>
  <main><h1>Hello</h1></main>
  <script>console.log('one');</script>
  <script>console.log('two');</script>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(result?.html).toBe('<main><h1>Hello</h1></main>');
    expect(result?.bodyAttrs).toBe('');
    expect(result?.css).toBe('.a { color: red; }\n\n.b { color: blue; }');
    expect(result?.js).toBe("console.log('one');\n\nconsole.log('two');");
    expect(result?.summary).toMatchObject({
      styleCount: 2,
      inlineScriptCount: 2,
      externalStyleCount: 0,
      externalScriptCount: 0,
    });
  });

  it('preserves body attributes from a full document import', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<body class="lp" data-page="x">
  <main><h1>Hello</h1></main>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(result?.bodyAttrs).toBe('class="lp" data-page="x"');
    expect(buildImportedHtml(result!, true)).toBe(`<body class="lp" data-page="x">
<main><h1>Hello</h1></main>
</body>`);
  });

  it('keeps external css at the top and external js at the bottom of imported html', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <link rel="preload stylesheet" href="https://cdn.example.com/a.css">
</head>
<body>
  <div id="app"></div>
  <script src="https://cdn.example.com/a.js"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    const html = buildImportedHtml(result!, true);

    expect(html).toBe(`<!-- External stylesheets from pasted HTML -->
<link rel="preload stylesheet" href="https://cdn.example.com/a.css">

<div id="app"></div>

<!-- External scripts from pasted HTML -->
<script src="https://cdn.example.com/a.js"></script>`);
  });

  it('omits external scripts when javascript editing is unavailable', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<body>
  <div id="app"></div>
  <script src="https://cdn.example.com/a.js"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(buildImportedHtml(result!, false)).toBe('<div id="app"></div>');
  });

  it('builds html from the selected imported parts', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.example.com/a.css">
</head>
<body class="lp">
  <main><h1>Hello</h1></main>
  <script src="https://cdn.example.com/a.js"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(buildImportedHtml(result!, true, createFullHtmlImportSelection())).toBe(`<!-- External stylesheets from pasted HTML -->
<link rel="stylesheet" href="https://cdn.example.com/a.css">

<body class="lp">
<main><h1>Hello</h1></main>
</body>

<!-- External scripts from pasted HTML -->
<script src="https://cdn.example.com/a.js"></script>`);
  });

  it('skips body html and body attributes together', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.example.com/a.css">
</head>
<body class="lp">
  <main><h1>Hello</h1></main>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(
      buildImportedHtml(
        result!,
        true,
        createFullHtmlImportSelection({ html: false })
      )
    ).toBe(`<!-- External stylesheets from pasted HTML -->
<link rel="stylesheet" href="https://cdn.example.com/a.css">`);
  });

  it('skips external css and external js independently', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.example.com/a.css">
</head>
<body>
  <div id="app"></div>
  <script src="https://cdn.example.com/a.js"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(
      buildImportedHtml(
        result!,
        true,
        createFullHtmlImportSelection({ externalStyles: false, externalScripts: false })
      )
    ).toBe('<div id="app"></div>');
  });

  it('does not output external js when javascript editing is unavailable even if selected', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<body>
  <div id="app"></div>
  <script src="https://cdn.example.com/a.js"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(
      buildImportedHtml(
        result!,
        false,
        createFullHtmlImportSelection({ html: false, externalScripts: true })
      )
    ).toBe('');
  });
});
