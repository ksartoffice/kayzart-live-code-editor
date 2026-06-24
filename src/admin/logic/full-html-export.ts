import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';
import { sanitizeCustomHeadInput } from './custom-head';
import type { JsMode } from '../types/js-mode';

type ElementNode = DefaultTreeAdapterTypes.Element;
type Node = DefaultTreeAdapterTypes.Node;

export type FullHtmlExportInput = {
  html: string;
  customHead: string;
  css: string;
  cssMode?: 'standard' | 'tailwind-source';
  js: string;
  jsMode: JsMode;
  canEditJs: boolean;
};

const isElement = (node: Node): node is ElementNode => 'tagName' in node;

const findElement = (node: Node, tagName: string): ElementNode | null => {
  if (isElement(node) && node.tagName.toLowerCase() === tagName.toLowerCase()) {
    return node;
  }
  if (!('childNodes' in node)) {
    return null;
  }
  for (const child of node.childNodes) {
    const match = findElement(child, tagName);
    if (match) {
      return match;
    }
  }
  return null;
};

const serializeAttrs = (node: ElementNode): string =>
  node.attrs
    .map((attr) => `${attr.name}="${attr.value.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"`)
    .join(' ')
    .trim();

const escapeStyleText = (css: string): string => css.replace(/<\/style/gi, '<\\/style');

const escapeScriptText = (js: string): string => js.replace(/<\/script/gi, '<\\/script');

function splitEditorHtml(html: string): { bodyAttrs: string; bodyHtml: string } {
  if (!html.toLowerCase().includes('<body')) {
    return {
      bodyAttrs: '',
      bodyHtml: html.trim(),
    };
  }

  try {
    const document = parse5.parse(html);
    const body = findElement(document, 'body');
    if (!body) {
      return {
        bodyAttrs: '',
        bodyHtml: html.trim(),
      };
    }

    return {
      bodyAttrs: serializeAttrs(body),
      bodyHtml: parse5.serialize(body).trim(),
    };
  } catch {
    return {
      bodyAttrs: '',
      bodyHtml: html.trim(),
    };
  }
}

export function buildFullHtmlExport(input: FullHtmlExportInput): string {
  const { bodyAttrs, bodyHtml } = splitEditorHtml(input.html);
  const headParts = [
    '<meta charset="utf-8">',
    '<meta name="viewport" content="width=device-width, initial-scale=1">',
  ];
  const customHead = input.canEditJs ? sanitizeCustomHeadInput(input.customHead).html : '';
  const css = input.css.trim();
  const js = input.canEditJs ? input.js.trim() : '';

  if (customHead) {
    headParts.push(customHead);
  }
  if (css) {
    const styleAttrs = input.cssMode === 'tailwind-source' ? ' type="text/tailwindcss"' : '';
    headParts.push(`<style${styleAttrs}>\n${escapeStyleText(css)}\n</style>`);
  }

  const bodyParts = [bodyHtml];
  if (js) {
    const scriptAttrs = input.jsMode === 'module' ? ' type="module"' : '';
    bodyParts.push(`<script${scriptAttrs}>\n${escapeScriptText(js)}\n</script>`);
  }

  const bodyOpen = bodyAttrs ? `<body ${bodyAttrs}>` : '<body>';

  return [
    '<!doctype html>',
    '<html lang="ja">',
    '<head>',
    headParts.join('\n'),
    '</head>',
    bodyOpen,
    bodyParts.filter((part) => part !== '').join('\n'),
    '</body>',
    '</html>',
  ].join('\n');
}
