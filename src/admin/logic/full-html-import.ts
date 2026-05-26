import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';
import type { ExternalResource, ExternalResourceAttrs } from '../types/external-resource';

export type FullHtmlImportResult = {
  html: string;
  bodyAttrs: string;
  customHead: string;
  removedHeadTags: string[];
  css: string;
  js: string;
  externalStyles: ExternalResource[];
  externalScripts: ExternalResource[];
  summary: {
    styleCount: number;
    inlineScriptCount: number;
    externalStyleCount: number;
    externalScriptCount: number;
  };
};

export type FullHtmlImportSelection = {
  html: boolean;
  customHead: boolean;
  css: boolean;
  js: boolean;
  externalStyles: boolean;
  externalScripts: boolean;
};

export const createFullHtmlImportSelection = (
  overrides: Partial<FullHtmlImportSelection> = {}
): FullHtmlImportSelection => ({
  html: true,
  customHead: true,
  css: true,
  js: true,
  externalStyles: true,
  externalScripts: true,
  ...overrides,
});

type ElementNode = DefaultTreeAdapterTypes.Element;
type Node = DefaultTreeAdapterTypes.Node;

const getAttr = (node: ElementNode, name: string): string | null => {
  const attr = node.attrs.find((item) => item.name.toLowerCase() === name.toLowerCase());
  return attr ? attr.value : null;
};

const BOOLEAN_SCRIPT_ATTRS = new Set(['async', 'defer', 'nomodule']);
const STYLE_ATTRS = new Set(['media', 'integrity', 'crossorigin', 'referrerpolicy', 'title']);
const SCRIPT_ATTRS = new Set([
  'type',
  'async',
  'defer',
  'nomodule',
  'integrity',
  'crossorigin',
  'referrerpolicy',
  'fetchpriority',
]);

const sanitizeAttrValue = (name: string, value: string): string | boolean | null => {
  const trimmed = value.trim();
  if (BOOLEAN_SCRIPT_ATTRS.has(name)) {
    return trimmed === '' ? true : trimmed;
  }
  if (!trimmed || /^javascript:/i.test(trimmed)) {
    return null;
  }
  return trimmed;
};

const extractExternalResource = (
  node: ElementNode,
  urlAttr: 'href' | 'src',
  allowedAttrs: Set<string>
): ExternalResource | null => {
  const url = getAttr(node, urlAttr)?.trim();
  if (!url) {
    return null;
  }
  const attrs: ExternalResourceAttrs = {};
  node.attrs.forEach((attr) => {
    const name = attr.name.toLowerCase();
    if (name === urlAttr || name === 'rel' || name.startsWith('on') || !allowedAttrs.has(name)) {
      return;
    }
    const value = sanitizeAttrValue(name, attr.value);
    if (value !== null) {
      attrs[name] = value;
    }
  });
  return { url, attrs };
};

const serializeAttrs = (node: ElementNode): string =>
  node.attrs
    .map((attr) => `${attr.name}="${attr.value.replace(/&/g, '&amp;').replace(/"/g, '&quot;')}"`)
    .join(' ')
    .trim();

const hasStylesheetRel = (rel: string | null): boolean => {
  if (!rel) {
    return false;
  }
  return rel
    .split(/\s+/)
    .map((part) => part.toLowerCase())
    .includes('stylesheet');
};

const unique = (items: string[]): string[] => Array.from(new Set(items));

const isViewportMeta = (node: ElementNode): boolean =>
  (getAttr(node, 'name') || '').trim().toLowerCase() === 'viewport';

const isJsonLdScript = (node: ElementNode): boolean =>
  node.tagName.toLowerCase() === 'script' &&
  (getAttr(node, 'type') || '').trim().toLowerCase() === 'application/ld+json';

const classifyForbiddenHeadTag = (node: ElementNode): string | null => {
  const tagName = node.tagName.toLowerCase();
  if (tagName === 'title') {
    return 'title';
  }
  if (tagName === 'base') {
    return 'base';
  }
  if (tagName === 'meta') {
    if (getAttr(node, 'charset') !== null) {
      return 'meta charset';
    }
    if (isViewportMeta(node)) {
      return 'meta viewport';
    }
  }
  return null;
};

const isElement = (node: Node): node is ElementNode => 'tagName' in node;

const getTextContent = (node: Node): string => {
  if ('value' in node && typeof node.value === 'string') {
    return node.value;
  }
  if (!('childNodes' in node)) {
    return '';
  }
  return node.childNodes.map(getTextContent).join('');
};

const serializeOriginalNode = (source: string, node: ElementNode): string => {
  const location = node.sourceCodeLocation;
  if (location && typeof location.startOffset === 'number' && typeof location.endOffset === 'number') {
    return source.slice(location.startOffset, location.endOffset);
  }
  return parse5.serialize(node);
};

const serializeBodyChild = (source: string, node: Node): string => {
  if (isElement(node)) {
    return serializeOriginalNode(source, node);
  }
  if ('value' in node && typeof node.value === 'string') {
    return node.value;
  }
  if ('data' in node && typeof node.data === 'string') {
    return `<!--${node.data}-->`;
  }
  return '';
};

const serializeHeadChild = (source: string, node: Node): string => {
  if (isElement(node)) {
    return serializeOriginalNode(source, node).trim();
  }
  if ('data' in node && typeof node.data === 'string') {
    return `<!--${node.data}-->`;
  }
  return '';
};

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

export function isFullHtmlDocumentPaste(text: string): boolean {
  const lowered = text.toLowerCase();
  return (
    (lowered.includes('<!doctype') || lowered.includes('<html')) &&
    lowered.includes('<body')
  );
}

export function parseFullHtmlDocument(source: string): FullHtmlImportResult | null {
  if (!isFullHtmlDocumentPaste(source)) {
    return null;
  }

  const document = parse5.parse(source, { sourceCodeLocationInfo: true });
  const head = findElement(document, 'head');
  const body = findElement(document, 'body');
  if (!body) {
    return null;
  }

  const cssParts: string[] = [];
  const jsParts: string[] = [];
  const bodyParts: string[] = [];
  const customHeadParts: string[] = [];
  const removedHeadTags: string[] = [];
  const externalStyles: ExternalResource[] = [];
  const externalScripts: ExternalResource[] = [];
  let styleCount = 0;
  let inlineScriptCount = 0;

  const visitExtractable = (node: Node) => {
    if (!isElement(node)) {
      return;
    }

    const tagName = node.tagName.toLowerCase();
    if (tagName === 'style') {
      const css = getTextContent(node).trim();
      if (css) {
        cssParts.push(css);
      }
      styleCount += 1;
      return;
    }

    if (tagName === 'script') {
      if (isJsonLdScript(node)) {
        return;
      }
      const src = getAttr(node, 'src');
      if (src) {
        const resource = extractExternalResource(node, 'src', SCRIPT_ATTRS);
        if (resource) {
          externalScripts.push(resource);
        }
        return;
      }
      const js = getTextContent(node).trim();
      if (js) {
        jsParts.push(js);
      }
      inlineScriptCount += 1;
      return;
    }

    if (tagName === 'link' && hasStylesheetRel(getAttr(node, 'rel')) && getAttr(node, 'href')) {
      const resource = extractExternalResource(node, 'href', STYLE_ATTRS);
      if (resource) {
        externalStyles.push(resource);
      }
      return;
    }

    if ('childNodes' in node) {
      node.childNodes.forEach(visitExtractable);
    }
  };

  document.childNodes.forEach(visitExtractable);

  head?.childNodes.forEach((child) => {
    if (!isElement(child)) {
      const serialized = serializeHeadChild(source, child);
      if (serialized) {
        customHeadParts.push(serialized);
      }
      return;
    }

    const forbiddenTag = classifyForbiddenHeadTag(child);
    if (forbiddenTag) {
      removedHeadTags.push(forbiddenTag);
      return;
    }

    const tagName = child.tagName.toLowerCase();
    const isExtractedStyle = tagName === 'style';
    const isExtractedStylesheet =
      tagName === 'link' && hasStylesheetRel(getAttr(child, 'rel')) && getAttr(child, 'href');
    const isExternalScript = tagName === 'script' && Boolean(getAttr(child, 'src'));
    const isInlineScript = tagName === 'script' && !isJsonLdScript(child);
    if (isExtractedStyle || isExtractedStylesheet || isExternalScript || isInlineScript) {
      return;
    }

    const serialized = serializeHeadChild(source, child);
    if (serialized) {
      customHeadParts.push(serialized);
    }
  });

  body.childNodes.forEach((child) => {
    if (isElement(child)) {
      const tagName = child.tagName.toLowerCase();
      const isExtractedStyle = tagName === 'style';
      const isExtractedScript = tagName === 'script';
      const isExtractedStylesheet =
        tagName === 'link' && hasStylesheetRel(getAttr(child, 'rel')) && getAttr(child, 'href');
      if (isExtractedStyle || isExtractedScript || isExtractedStylesheet) {
        return;
      }
    }
    bodyParts.push(serializeBodyChild(source, child));
  });

  return {
    html: bodyParts.join('').trim(),
    bodyAttrs: serializeAttrs(body),
    customHead: customHeadParts.join('\n').trim(),
    removedHeadTags: unique(removedHeadTags),
    css: cssParts.join('\n\n'),
    js: jsParts.join('\n\n'),
    externalStyles,
    externalScripts,
    summary: {
      styleCount,
      inlineScriptCount,
      externalStyleCount: externalStyles.length,
      externalScriptCount: externalScripts.length,
    },
  };
}

export function buildImportedHtml(
  result: FullHtmlImportResult,
  _canEditJs: boolean,
  selection: FullHtmlImportSelection = createFullHtmlImportSelection()
): string {
  const parts: string[] = [];
  const html = result.html.trim();
  const bodyAttrs = result.bodyAttrs.trim();
  if (selection.html && (html || bodyAttrs)) {
    parts.push(bodyAttrs ? `<body ${bodyAttrs}>\n${html}\n</body>` : html);
  }
  return parts.join('\n\n');
}
