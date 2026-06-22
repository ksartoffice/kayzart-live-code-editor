import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';

export type FullHtmlImportResult = {
  html: string;
  bodyAttrs: string;
  customHead: string;
  removedHeadTags: string[];
  css: string;
  js: string;
  tailwindCdn: TailwindCdnDetection;
  summary: {
    styleCount: number;
    inlineScriptCount: number;
  };
};

export type TailwindCdnVersion = 'v3' | 'v4' | 'unknown';

export type TailwindCdnDetection = {
  detected: boolean;
  version: TailwindCdnVersion;
  scriptCount: number;
  configScriptCount: number;
};

export type FullHtmlParseOptions = {
  removeTailwindCdn?: boolean;
};

export type FullHtmlImportSelection = {
  html: boolean;
  customHead: boolean;
  css: boolean;
  js: boolean;
};

export const createFullHtmlImportSelection = (
  overrides: Partial<FullHtmlImportSelection> = {}
): FullHtmlImportSelection => ({
  html: true,
  customHead: true,
  css: true,
  js: true,
  ...overrides,
});

export const buildTailwindImportCss = (css: string): string => {
  const trimmed = css.trim();
  if (/@import\s+(?:url\()?["']tailwindcss["']\)?\s*;/.test(trimmed)) {
    return trimmed;
  }
  return trimmed ? `@import "tailwindcss";\n\n${trimmed}` : '@import "tailwindcss";';
};

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

const unique = (items: string[]): string[] => Array.from(new Set(items));

const isViewportMeta = (node: ElementNode): boolean =>
  (getAttr(node, 'name') || '').trim().toLowerCase() === 'viewport';

const isJsonLdScript = (node: ElementNode): boolean =>
  node.tagName.toLowerCase() === 'script' &&
  (getAttr(node, 'type') || '').trim().toLowerCase() === 'application/ld+json';

const classifyTailwindCdnScript = (node: ElementNode): TailwindCdnVersion | null => {
  if (node.tagName.toLowerCase() !== 'script') {
    return null;
  }
  const src = (getAttr(node, 'src') || '').trim().toLowerCase();
  if (!src) {
    return null;
  }
  if (src.includes('cdn.tailwindcss.com')) {
    return 'v3';
  }
  if (src.includes('@tailwindcss/browser@4')) {
    return 'v4';
  }
  if (src.includes('@tailwindcss/browser') || src.includes('tailwindcss/browser')) {
    return 'unknown';
  }
  if (src.includes('tailwindcss') && (src.includes('unpkg.com') || src.includes('jsdelivr.net'))) {
    return 'unknown';
  }
  return null;
};

const isTailwindConfigScript = (node: ElementNode): boolean =>
  node.tagName.toLowerCase() === 'script' &&
  !getAttr(node, 'src') &&
  /(?:window\.)?tailwind\s*\.\s*config\s*=/.test(getTextContent(node));

const isBodyExtractableNode = (node: ElementNode): boolean => {
  const tagName = node.tagName.toLowerCase();
  return (
    tagName === 'style' ||
    (tagName === 'script' && !getAttr(node, 'src') && !isJsonLdScript(node))
  );
};

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

const serializeOriginalNode = (
  source: string,
  node: ElementNode,
  useOriginalSource = true
): string => {
  const location = node.sourceCodeLocation;
  if (
    useOriginalSource &&
    location &&
    typeof location.startOffset === 'number' &&
    typeof location.endOffset === 'number'
  ) {
    return source.slice(location.startOffset, location.endOffset);
  }
  return parse5.serializeOuter(node);
};

const serializeBodyChild = (
  source: string,
  node: Node,
  changedNodes: WeakSet<ElementNode>
): string => {
  if (isElement(node)) {
    return serializeOriginalNode(source, node, !changedNodes.has(node));
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

const removeExtractedBodyNodes = (
  node: ElementNode,
  extractedNodes: WeakSet<ElementNode>,
  changedNodes: WeakSet<ElementNode>
): boolean => {
  if (!('childNodes' in node)) {
    return false;
  }

  let changed = false;
  node.childNodes = node.childNodes.filter((child) => {
    if (isElement(child) && extractedNodes.has(child)) {
      changed = true;
      return false;
    }
    return true;
  });

  node.childNodes.forEach((child) => {
    if (isElement(child) && removeExtractedBodyNodes(child, extractedNodes, changedNodes)) {
      changed = true;
    }
  });

  if (changed) {
    changedNodes.add(node);
  }
  return changed;
};

export function isFullHtmlDocumentPaste(text: string): boolean {
  const lowered = text.toLowerCase();
  return (
    (lowered.includes('<!doctype') || lowered.includes('<html')) &&
    lowered.includes('<body')
  );
}

const mergeTailwindVersion = (
  current: TailwindCdnVersion,
  next: TailwindCdnVersion
): TailwindCdnVersion => {
  if (current === next) {
    return current;
  }
  if (current === 'unknown') {
    return next;
  }
  if (next === 'unknown') {
    return current;
  }
  return 'unknown';
};

export function parseFullHtmlDocument(
  source: string,
  options: FullHtmlParseOptions = {}
): FullHtmlImportResult | null {
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
  const extractedBodyNodes = new WeakSet<ElementNode>();
  const changedBodyNodes = new WeakSet<ElementNode>();
  let styleCount = 0;
  let inlineScriptCount = 0;
  let tailwindCdnVersion: TailwindCdnVersion = 'unknown';
  let tailwindScriptCount = 0;
  let tailwindConfigScriptCount = 0;

  const visitExtractable = (node: Node) => {
    if (!isElement(node)) {
      return;
    }

    const tagName = node.tagName.toLowerCase();
    const tailwindCdnVersionForNode = classifyTailwindCdnScript(node);
    const tailwindConfigScript = isTailwindConfigScript(node);
    if (tailwindCdnVersionForNode) {
      tailwindCdnVersion = mergeTailwindVersion(tailwindCdnVersion, tailwindCdnVersionForNode);
      tailwindScriptCount += 1;
    }
    if (tailwindConfigScript) {
      tailwindConfigScriptCount += 1;
    }
    if (options.removeTailwindCdn && (tailwindCdnVersionForNode || tailwindConfigScript)) {
      extractedBodyNodes.add(node);
      return;
    }
    if (isBodyExtractableNode(node)) {
      extractedBodyNodes.add(node);
    }

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
      if (getAttr(node, 'src')) {
        return;
      }
      const js = getTextContent(node).trim();
      if (js) {
        jsParts.push(js);
      }
      inlineScriptCount += 1;
      return;
    }

    if ('childNodes' in node) {
      node.childNodes.forEach(visitExtractable);
    }
  };

  body.childNodes.forEach(visitExtractable);

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
    const tailwindCdnVersionForNode = classifyTailwindCdnScript(child);
    const tailwindConfigScript = isTailwindConfigScript(child);
    if (tailwindCdnVersionForNode) {
      tailwindCdnVersion = mergeTailwindVersion(tailwindCdnVersion, tailwindCdnVersionForNode);
      tailwindScriptCount += 1;
    }
    if (tailwindConfigScript) {
      tailwindConfigScriptCount += 1;
    }
    if (options.removeTailwindCdn && (tailwindCdnVersionForNode || tailwindConfigScript)) {
      return;
    }
    if (tagName === 'style') {
      const css = getTextContent(child).trim();
      if (css) {
        cssParts.push(css);
      }
      styleCount += 1;
      return;
    }

    const serialized = serializeHeadChild(source, child);
    if (serialized) {
      customHeadParts.push(serialized);
    }
  });

  removeExtractedBodyNodes(body, extractedBodyNodes, changedBodyNodes);

  body.childNodes.forEach((child) => {
    bodyParts.push(serializeBodyChild(source, child, changedBodyNodes));
  });

  return {
    html: bodyParts.join('').trim(),
    bodyAttrs: serializeAttrs(body),
    customHead: customHeadParts.join('\n').trim(),
    removedHeadTags: unique(removedHeadTags),
    css: cssParts.join('\n\n'),
    js: jsParts.join('\n\n'),
    tailwindCdn: {
      detected: tailwindScriptCount > 0,
      version: tailwindScriptCount > 0 ? tailwindCdnVersion : 'unknown',
      scriptCount: tailwindScriptCount,
      configScriptCount: tailwindConfigScriptCount,
    },
    summary: {
      styleCount,
      inlineScriptCount,
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
