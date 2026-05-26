import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';

export type FullHtmlImportResult = {
  html: string;
  bodyAttrs: string;
  css: string;
  js: string;
  externalStyles: string[];
  externalScripts: string[];
  summary: {
    styleCount: number;
    inlineScriptCount: number;
    externalStyleCount: number;
    externalScriptCount: number;
  };
};

export type FullHtmlImportSelection = {
  html: boolean;
  css: boolean;
  js: boolean;
  externalStyles: boolean;
  externalScripts: boolean;
};

export const createFullHtmlImportSelection = (
  overrides: Partial<FullHtmlImportSelection> = {}
): FullHtmlImportSelection => ({
  html: true,
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
  const body = findElement(document, 'body');
  if (!body) {
    return null;
  }

  const cssParts: string[] = [];
  const jsParts: string[] = [];
  const bodyParts: string[] = [];
  const externalStyles: string[] = [];
  const externalScripts: string[] = [];
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
      const src = getAttr(node, 'src');
      if (src) {
        externalScripts.push(serializeOriginalNode(source, node).trim());
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
      externalStyles.push(serializeOriginalNode(source, node).trim());
      return;
    }

    if ('childNodes' in node) {
      node.childNodes.forEach(visitExtractable);
    }
  };

  document.childNodes.forEach(visitExtractable);

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
  canEditJs: boolean,
  selection: FullHtmlImportSelection = createFullHtmlImportSelection()
): string {
  const parts: string[] = [];
  if (selection.externalStyles && result.externalStyles.length) {
    parts.push(
      ['<!-- External stylesheets from pasted HTML -->', ...result.externalStyles].join('\n')
    );
  }
  const html = result.html.trim();
  const bodyAttrs = result.bodyAttrs.trim();
  if (selection.html && (html || bodyAttrs)) {
    parts.push(bodyAttrs ? `<body ${bodyAttrs}>\n${html}\n</body>` : html);
  }
  if (selection.externalScripts && canEditJs && result.externalScripts.length) {
    parts.push(['<!-- External scripts from pasted HTML -->', ...result.externalScripts].join('\n'));
  }
  return parts.join('\n\n');
}
