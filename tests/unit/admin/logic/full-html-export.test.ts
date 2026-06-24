import { describe, expect, it } from 'vitest';
import { buildFullHtmlExport } from '../../../../src/admin/logic/full-html-export';

describe('full html export logic', () => {
  it('builds a complete document from current editor content', () => {
    const html = buildFullHtmlExport({
      html: '<main><h1>Hello</h1></main>',
      customHead: '<meta name="description" content="Landing page">',
      css: '.hero { color: red; }',
      js: 'console.log("hello");',
      jsMode: 'classic',
      canEditJs: true,
    });

    expect(html).toContain('<!doctype html>');
    expect(html).toContain('<html lang="ja">');
    expect(html).toContain('<meta charset="utf-8">');
    expect(html).toContain('<meta name="viewport" content="width=device-width, initial-scale=1">');
    expect(html).toContain('<meta name="description" content="Landing page">');
    expect(html).toContain('<style>\n.hero { color: red; }\n</style>');
    expect(html).toContain('<body>\n<main><h1>Hello</h1></main>\n<script>');
    expect(html).toContain('console.log("hello");');
  });

  it('preserves body attributes without nesting body tags', () => {
    const html = buildFullHtmlExport({
      html: '<body class="lp" data-page="x"><main>Hi</main></body>',
      customHead: '',
      css: '',
      js: '',
      jsMode: 'classic',
      canEditJs: true,
    });

    expect(html).toContain('<body class="lp" data-page="x">\n<main>Hi</main>\n</body>');
    expect(html.match(/<body/g)).toHaveLength(1);
  });

  it('uses module scripts when the current JavaScript mode is module', () => {
    const html = buildFullHtmlExport({
      html: '<div id="app"></div>',
      customHead: '',
      css: '',
      js: 'import "./app.js";',
      jsMode: 'module',
      canEditJs: true,
    });

    expect(html).toContain('<script type="module">\nimport "./app.js";\n</script>');
  });

  it('uses the CSS editor value directly for Tailwind-style input', () => {
    const html = buildFullHtmlExport({
      html: '<div class="text-red-500">Tailwind</div>',
      customHead: '',
      css: '@import "tailwindcss";\n@theme { --color-brand: #123456; }',
      js: '',
      jsMode: 'classic',
      canEditJs: true,
    });

    expect(html).toContain('@import "tailwindcss";');
    expect(html).toContain('@theme { --color-brand: #123456; }');
  });

  it('escapes closing style and script tag sequences in inline blocks', () => {
    const html = buildFullHtmlExport({
      html: '<main>Safe</main>',
      customHead: '',
      css: '.x::before { content: "</style>"; }',
      js: 'const tag = "</script>";',
      jsMode: 'classic',
      canEditJs: true,
    });

    expect(html).toContain('content: "<\\/style>";');
    expect(html).toContain('const tag = "<\\/script>";');
  });

  it('omits custom head and JavaScript when the user cannot edit unfiltered HTML', () => {
    const html = buildFullHtmlExport({
      html: '<main>Content</main>',
      customHead: '<meta name="robots" content="noindex">',
      css: '.x { color: red; }',
      js: 'alert("nope");',
      jsMode: 'classic',
      canEditJs: false,
    });

    expect(html).not.toContain('robots');
    expect(html).not.toContain('alert("nope")');
    expect(html).toContain('.x { color: red; }');
  });
});
