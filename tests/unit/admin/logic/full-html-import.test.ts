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
    expect(result?.customHead).toBe('');
    expect(result?.removedHeadTags).toEqual(['meta charset']);
    expect(result?.css).toBe('.a { color: red; }\n\n.b { color: blue; }');
    expect(result?.js).toBe("console.log('one');\n\nconsole.log('two');");
    expect(result?.summary).toMatchObject({
      styleCount: 2,
      inlineScriptCount: 2,
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

  it('extracts custom head additions and reports unsupported head tags', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <title>Ignored title</title>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width">
  <base href="/">
  <meta property="og:title" content="Hello">
  <meta name="description" content="Landing page">
  <script type="application/ld+json">{"@type":"Thing"}</script>
  <script>console.log('head inline');</script>
  <style>.hero { color: red; }</style>
</head>
<body>
  <main>Hello</main>
</body>
</html>`);

    expect(result).not.toBeNull();
    expect(result?.customHead).toBe(`<meta property="og:title" content="Hello">
<meta name="description" content="Landing page">
<script type="application/ld+json">{"@type":"Thing"}</script>
<script>console.log('head inline');</script>`);
    expect(result?.removedHeadTags).toEqual([
      'title',
      'meta charset',
      'meta viewport',
      'base',
    ]);
    expect(result?.css).toBe('.hero { color: red; }');
    expect(result?.js).toBe('');
    expect(result?.js).not.toContain('Thing');
  });

  it('preserves external css in custom head and external js in body html', () => {
    const result = parseFullHtmlDocument(`<!doctype html>
<html>
<head>
  <link rel="preload stylesheet" href="https://cdn.example.com/a.css" media="screen" integrity="sha384-css" onclick="nope()">
</head>
<body>
  <div id="app"></div>
  <script src="https://cdn.example.com/a.js" defer integrity="sha384-js" data-x="nope"></script>
</body>
</html>`);

    expect(result).not.toBeNull();
    const html = buildImportedHtml(result!, true);

    expect(result?.customHead).toBe('<link rel="preload stylesheet" href="https://cdn.example.com/a.css" media="screen" integrity="sha384-css" onclick="nope()">');
    expect(html).toBe('<div id="app"></div>\n  <script src="https://cdn.example.com/a.js" defer integrity="sha384-js" data-x="nope"></script>');
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
    expect(result?.customHead).toBe('<link rel="stylesheet" href="https://cdn.example.com/a.css">');
    expect(buildImportedHtml(result!, true, createFullHtmlImportSelection())).toBe(`<body class="lp">
<main><h1>Hello</h1></main>
  <script src="https://cdn.example.com/a.js"></script>
</body>`);
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
    ).toBe('');
  });

});
