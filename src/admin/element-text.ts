import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';

type InnerRange = {
  startOffset: number;
  endOffset: number;
};

type ElementTextInfo = InnerRange & {
  text: string;
};

export type EditableTextSegment = InnerRange & {
  id: string;
  text: string;
  labelHint: string;
};

export type ElementAttribute = {
  name: string;
  value: string;
};

export type ElementAttributesInfo = {
  attributes: ElementAttribute[];
  startOffset: number;
  endOffset: number;
  tagName: string;
  isVoid: boolean;
  selfClosing: boolean;
};

export type ElementContextInfo = {
  lcId: string;
  tagName: string;
  attributes: ElementAttribute[];
  text: string | null;
  outerHTML: string;
  sourceRange?: {
    startOffset: number;
    endOffset: number;
  };
};

export type ImageSourceEditInfo = {
  startOffset: number;
  endOffset: number;
  insertPrefix: string;
  insertSuffix: string;
};

export type ElementImageSourceEditInfo = ImageSourceEditInfo & {
  attributeName: 'src' | 'srcset' | 'data-src' | 'data-srcset';
};

export type ElementActionInfo = {
  actionLcId: string;
  kind: 'link' | 'button';
  tagName: string;
  href: string;
  targetBlank: boolean;
  rel: string;
  disabled: boolean;
};

export type ElementImageInfo = {
  imageLcId: string;
  tagName: 'img';
  src: string;
  alt: string;
  title: string;
  hasSrcset: boolean;
  hasDataSrc: boolean;
  hasDataSrcset: boolean;
  hasPictureSources: boolean;
};

const ALLOWED_INLINE_TAGS = new Set(['br', 'span']);
const TEXT_SEGMENT_SKIP_TAGS = new Set(['script', 'style', 'svg', 'noscript', 'template']);
const HEADING_TAGS = new Set(['h1', 'h2', 'h3', 'h4', 'h5', 'h6']);
const INLINE_TEXT_WRAPPER_TAGS = new Set([
  'span',
  'strong',
  'em',
  'b',
  'i',
  'mark',
  'small',
  'u',
  's',
  'sub',
  'sup',
]);
const KAYZART_ATTR_NAME = 'data-kayzart-id';
const VOID_TAGS = new Set([
  'area',
  'base',
  'br',
  'col',
  'embed',
  'hr',
  'img',
  'input',
  'link',
  'meta',
  'param',
  'source',
  'track',
  'wbr',
]);

function isElement(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.Element {
  return (node as DefaultTreeAdapterTypes.Element).tagName !== undefined;
}

function isParentNode(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.ParentNode {
  return Array.isArray((node as DefaultTreeAdapterTypes.ParentNode).childNodes);
}

function isTemplateElement(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.Template {
  return isElement(node) && node.tagName === 'template' && Boolean((node as DefaultTreeAdapterTypes.Template).content);
}

function isTextNode(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.TextNode {
  return node.nodeName === '#text';
}

function isCommentNode(node: DefaultTreeAdapterTypes.Node): boolean {
  return node.nodeName === '#comment';
}

function findElementByTag(
  node: DefaultTreeAdapterTypes.Node,
  tagName: string
): DefaultTreeAdapterTypes.Element | null {
  if (isElement(node) && node.tagName.toLowerCase() === tagName.toLowerCase()) {
    return node;
  }
  if (!isParentNode(node)) {
    return null;
  }
  for (const child of node.childNodes || []) {
    const match = findElementByTag(child, tagName);
    if (match) {
      return match;
    }
  }
  return null;
}

function parseElementLookupRoot(html: string): DefaultTreeAdapterTypes.ParentNode {
  if (html.toLowerCase().includes('<body')) {
    const document = parse5.parse(html, { sourceCodeLocationInfo: true });
    const body = findElementByTag(document, 'body');
    if (body) {
      return body;
    }
  }
  return parse5.parseFragment(html, { sourceCodeLocationInfo: true });
}

function getElementTagName(node: DefaultTreeAdapterTypes.Node | null | undefined): string {
  return node && isElement(node) ? node.tagName.toLowerCase() : '';
}

function getTextSegmentLabelHint(ancestors: DefaultTreeAdapterTypes.Element[]): string {
  const tags = ancestors.map((entry) => entry.tagName.toLowerCase()).reverse();
  const closest = tags[0] || '';
  if (tags.includes('button')) {
    return 'Button text';
  }
  if (tags.includes('a')) {
    const className =
      ancestors
        .flatMap((entry) => entry.attrs)
        .find((attr) => attr.name === 'class')?.value || '';
    return /button|btn|cta/i.test(className) ? 'Button text' : 'Link text';
  }
  if (HEADING_TAGS.has(closest)) {
    return 'Heading';
  }
  if (closest === 'p' || closest === 'li') {
    return 'Text';
  }
  return 'Text';
}

export function escapeTextForHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function collectDescendantText(node: DefaultTreeAdapterTypes.Node): string {
  if (isTextNode(node)) {
    return node.value;
  }
  if (isCommentNode(node)) {
    return '';
  }
  if (isTemplateElement(node)) {
    return collectDescendantText(node.content);
  }
  if (!isParentNode(node)) {
    return '';
  }
  return (node.childNodes || [])
    .map((child) => collectDescendantText(child))
    .filter((text) => text.trim().length > 0)
    .join(' ');
}

function isValidTagName(tagName: string) {
  return /^[a-z][a-z0-9-]*$/i.test(tagName);
}

function isEditableChild(node: DefaultTreeAdapterTypes.Node) {
  if (isTextNode(node)) {
    return true;
  }
  if (isCommentNode(node)) {
    return true;
  }
  if (isElement(node)) {
    if (!isValidTagName(node.tagName)) {
      return true;
    }
    return ALLOWED_INLINE_TAGS.has(node.tagName);
  }
  return false;
}

function isUnsafeFragmentParseError(code: string) {
  return code.startsWith('eof-');
}

function isSafeEditableFragmentNode(node: DefaultTreeAdapterTypes.Node): boolean {
  if (isTextNode(node) || isCommentNode(node)) {
    return true;
  }
  if (!isElement(node)) {
    return false;
  }
  if (!ALLOWED_INLINE_TAGS.has(node.tagName)) {
    return false;
  }
  if (node.tagName === 'br') {
    return true;
  }
  if (node.tagName !== 'span') {
    return false;
  }
  if (!node.sourceCodeLocation?.startTag || !node.sourceCodeLocation.endTag) {
    return false;
  }
  return (node.childNodes || []).every((child) => isSafeEditableFragmentNode(child));
}

export function isSafeEditableElementHtml(html: string): boolean {
  const errors: string[] = [];
  const fragment = parse5.parseFragment(html, {
    sourceCodeLocationInfo: true,
    onParseError: (error) => {
      errors.push(error.code);
    },
  });
  if (errors.some((code) => isUnsafeFragmentParseError(code))) {
    return false;
  }
  if (html.trim() !== '' && (fragment.childNodes || []).length === 0) {
    return false;
  }
  return (fragment.childNodes || []).every((node) => isSafeEditableFragmentNode(node));
}

function getExistingLcId(el: DefaultTreeAdapterTypes.Element): string | null {
  const attr = el.attrs.find((item) => item.name === KAYZART_ATTR_NAME);
  return attr ? attr.value : null;
}

function getElementAttributeValue(el: DefaultTreeAdapterTypes.Element, name: string): string {
  return el.attrs.find((item) => item.name.toLowerCase() === name.toLowerCase())?.value ?? '';
}

function hasElementAttribute(el: DefaultTreeAdapterTypes.Element, name: string): boolean {
  return el.attrs.some((item) => item.name.toLowerCase() === name.toLowerCase());
}

function isButtonLikeClassName(className: string): boolean {
  return /(^|[\s_-])(button|btn|cta)([\s_-]|$)/i.test(className);
}

function isTextualContainerTag(tagName: string): boolean {
  return (
    HEADING_TAGS.has(tagName) ||
    INLINE_TEXT_WRAPPER_TAGS.has(tagName) ||
    tagName === 'a' ||
    tagName === 'button' ||
    tagName === 'p' ||
    tagName === 'li'
  );
}

function shouldExposeEmptyTextSegment(
  rawText: string,
  ancestors: DefaultTreeAdapterTypes.Element[]
): boolean {
  const parentTagName = getElementTagName(ancestors[ancestors.length - 1]);
  return (
    rawText.length > 0 &&
    !/[\r\n]/.test(rawText) &&
    rawText.trim().length === 0 &&
    isTextualContainerTag(parentTagName)
  );
}

function isEmptyTextualElement(node: DefaultTreeAdapterTypes.Element): boolean {
  if (!isTextualContainerTag(getElementTagName(node))) {
    return false;
  }
  const childNodes = node.childNodes || [];
  return childNodes.length === 0 || childNodes.every((child) => isCommentNode(child));
}

type ElementLookupEntry = {
  element: DefaultTreeAdapterTypes.Element;
  lcId: string;
};

function findElementLookupEntry(html: string, lcId: string): (ElementLookupEntry & {
  ancestors: ElementLookupEntry[];
}) | null {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let result: (ElementLookupEntry & { ancestors: ElementLookupEntry[] }) | null = null;

  const walk = (
    node: DefaultTreeAdapterTypes.ParentNode,
    ancestors: ElementLookupEntry[] = []
  ) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;
        const entry = { element: child, lcId: id };

        if (id === lcId) {
          result = { ...entry, ancestors };
          return;
        }
        walk(child, [...ancestors, entry]);
        if (result) return;
        if (isTemplateElement(child)) {
          walk(child.content, [...ancestors, entry]);
          if (result) return;
        }
      } else if (isParentNode(child)) {
        walk(child, ancestors);
        if (result) return;
      }
    }
  };

  walk(root);
  return result;
}

function getActionInfoFromEntry(entry: ElementLookupEntry): ElementActionInfo | null {
  const tagName = entry.element.tagName.toLowerCase();
  const className = getElementAttributeValue(entry.element, 'class');
  const isLink = tagName === 'a';
  const isButton = tagName === 'button' || (isLink && isButtonLikeClassName(className));
  if (!isLink && !isButton) {
    return null;
  }
  return {
    actionLcId: entry.lcId,
    kind: isButton ? 'button' : 'link',
    tagName,
    href: isLink ? getElementAttributeValue(entry.element, 'href') : '',
    targetBlank: getElementAttributeValue(entry.element, 'target').toLowerCase() === '_blank',
    rel: getElementAttributeValue(entry.element, 'rel'),
    disabled: hasElementAttribute(entry.element, 'disabled'),
  };
}

function findAttributeValueRange(
  html: string,
  startTagStartOffset: number,
  startTagEndOffset: number,
  attrName: string
): { startOffset: number; endOffset: number } | null {
  const startTagText = html.slice(startTagStartOffset, startTagEndOffset);
  const escapedAttrName = attrName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const attrPattern = new RegExp(
    '(^|\\s)(' +
      escapedAttrName +
      ')(\\s*=\\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\\s"\'=<>`]+))',
    'i'
  );
  const match = attrPattern.exec(startTagText);
  if (!match || typeof match.index !== 'number') {
    return null;
  }

  const leading = match[1] ?? '';
  const name = match[2] ?? '';
  const assignment = match[3] ?? '';
  const quoteOffset = match[4] !== undefined || match[5] !== undefined ? 1 : 0;
  const value = match[4] ?? match[5] ?? match[6] ?? '';
  const startOffset =
    startTagStartOffset +
    match.index +
    leading.length +
    name.length +
    assignment.length +
    quoteOffset;
  return {
    startOffset,
    endOffset: startOffset + value.length,
  };
}

function getStartTagInsertionOffset(html: string, startOffset: number, endOffset: number): number {
  const startTagText = html.slice(startOffset, endOffset);
  const selfClosingIndex = startTagText.search(/\/\s*>$/);
  if (selfClosingIndex >= 0) {
    return startOffset + selfClosingIndex;
  }
  const closeIndex = startTagText.lastIndexOf('>');
  return closeIndex >= 0 ? startOffset + closeIndex : endOffset;
}

function findClosingTagOffset(html: string, tagName: string, fromOffset: number) {
  const name = tagName.toLowerCase();
  const tagRegex = /<\/?([a-z0-9-]+)(?:\s[^>]*)?>/gi;
  tagRegex.lastIndex = fromOffset;
  let depth = 0;
  let match: RegExpExecArray | null;
  while ((match = tagRegex.exec(html)) !== null) {
    const fullTag = match[0];
    const matchName = match[1].toLowerCase();
    if (matchName !== name) {
      continue;
    }
    const isEndTag = fullTag.startsWith('</');
    if (isEndTag) {
      if (depth === 0) {
        return match.index;
      }
      depth -= 1;
      continue;
    }
    if (VOID_TAGS.has(matchName)) {
      continue;
    }
    if (/\/\s*>$/.test(fullTag)) {
      continue;
    }
    depth += 1;
  }
  return null;
}

function getInnerRange(
  html: string,
  tagName: string,
  loc: DefaultTreeAdapterTypes.Element['sourceCodeLocation']
): InnerRange | null {
  if (!loc) {
    return null;
  }
  const start =
    loc.startTag && typeof loc.startTag.endOffset === 'number'
      ? loc.startTag.endOffset
      : loc.startOffset;
  let end =
    loc.endTag && typeof loc.endTag.startOffset === 'number' ? loc.endTag.startOffset : null;
  let fallback: number | null = null;
  if (typeof start === 'number') {
    fallback = findClosingTagOffset(html, tagName, start);
    if (fallback !== null && (end === null || fallback < end)) {
      end = fallback;
    }
  }
  if (end === null && typeof loc.endOffset === 'number') {
    end = loc.endOffset;
  }
  if (typeof start !== 'number' || typeof end !== 'number') {
    return null;
  }
  if (end < start) {
    return null;
  }
  return { startOffset: start, endOffset: end };
}

export function getEditableElementText(html: string, lcId: string): ElementTextInfo | null {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let result: ElementTextInfo | null = null;

  const walk = (node: DefaultTreeAdapterTypes.ParentNode) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;

        if (id === lcId) {
          if (VOID_TAGS.has(child.tagName)) {
            result = null;
            return;
          }
          const range = getInnerRange(html, child.tagName, child.sourceCodeLocation);
          if (!range) {
            result = null;
            return;
          }
          const childNodes = child.childNodes || [];
          const isEditable = childNodes.every((entry) => isEditableChild(entry));
          if (!isEditable) {
            result = null;
            return;
          }
          result = {
            text: html.slice(range.startOffset, range.endOffset),
            startOffset: range.startOffset,
            endOffset: range.endOffset,
          };
          return;
        }
        walk(child);
        if (result) return;
        if (isTemplateElement(child)) {
          walk(child.content);
          if (result) return;
        }
      } else if (isParentNode(child)) {
        walk(child);
        if (result) return;
      }
    }
  };

  walk(root);
  return result;
}

export function getEditableTextSegments(html: string, lcId: string): EditableTextSegment[] {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let selected: DefaultTreeAdapterTypes.Element | null = null;
  let selectedAncestors: DefaultTreeAdapterTypes.Element[] = [];

  const findSelected = (
    node: DefaultTreeAdapterTypes.ParentNode,
    ancestors: DefaultTreeAdapterTypes.Element[] = []
  ) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;
        if (id === lcId) {
          selected = child;
          selectedAncestors = ancestors;
          return;
        }
        findSelected(child, [...ancestors, child]);
        if (selected) return;
        if (isTemplateElement(child)) {
          findSelected(child.content, [...ancestors, child]);
          if (selected) return;
        }
      } else if (isParentNode(child)) {
        findSelected(child, ancestors);
        if (selected) return;
      }
    }
  };

  findSelected(root);
  // selected は findSelected 内（クロージャ）でのみ代入されるため TS の制御フロー解析は
  // null のまま narrowing してしまう。アサーションで型を戻し、以降の判定を正す。
  const selectedElement = selected as DefaultTreeAdapterTypes.Element | null;
  if (!selectedElement || VOID_TAGS.has(selectedElement.tagName)) {
    return [];
  }

  const collectSegments = (root: DefaultTreeAdapterTypes.Element): EditableTextSegment[] => {
    const segments: EditableTextSegment[] = [];
    let textSlotCount = 0;
    const collect = (
      node: DefaultTreeAdapterTypes.Node,
      ancestors: DefaultTreeAdapterTypes.Element[]
    ) => {
      if (isTextNode(node)) {
        const loc = (node as DefaultTreeAdapterTypes.TextNode & {
          sourceCodeLocation?: { startOffset?: number; endOffset?: number };
        }).sourceCodeLocation;
        if (
          !loc ||
          typeof loc.startOffset !== 'number' ||
          typeof loc.endOffset !== 'number'
        ) {
          return;
        }
        const rawText = node.value;
        const leading = rawText.match(/^\s*/)?.[0].length ?? 0;
        const trailing = rawText.match(/\s*$/)?.[0].length ?? 0;
        const text = rawText.slice(leading, rawText.length - trailing);
        const hasVisibleText = text.trim().length > 0;
        const keepEmptyText = shouldExposeEmptyTextSegment(rawText, ancestors);
        if (!hasVisibleText && !keepEmptyText) {
          return;
        }
        // node.value は parse5 によってエンティティがデコードされ（例: &nbsp; ->  ）、
        // CRLF も正規化された文字列。そのためデコード後の文字数で数えた leading/trailing を
        // 生ソースを指す loc.startOffset/endOffset に足し引きすると範囲がエンティティの内側に
        // 食い込み、不正な HTML を生む可能性がある。トリム量はソーススライス側で計測して、
        // 範囲の境界が必ずソースの文字境界（＝エンティティの外側）に着地するようにする。
        const sourceSlice = html.slice(loc.startOffset, loc.endOffset);
        const sourceLeading = sourceSlice.match(/^\s*/)?.[0].length ?? 0;
        const sourceTrailing = sourceSlice.match(/\s*$/)?.[0].length ?? 0;
        textSlotCount += 1;
        segments.push({
          id: `text-${textSlotCount}`,
          text: hasVisibleText ? text : '',
          startOffset: hasVisibleText ? loc.startOffset + sourceLeading : loc.startOffset,
          endOffset: hasVisibleText ? loc.endOffset - sourceTrailing : loc.endOffset,
          labelHint: getTextSegmentLabelHint(ancestors),
        });
        return;
      }

      if (!isParentNode(node) || isCommentNode(node)) {
        return;
      }
      if (isElement(node) && TEXT_SEGMENT_SKIP_TAGS.has(getElementTagName(node))) {
        return;
      }
      const nextAncestors = isElement(node) ? [...ancestors, node] : ancestors;
      if (isElement(node) && isEmptyTextualElement(node)) {
        const range = getInnerRange('', node.tagName, node.sourceCodeLocation);
        if (range) {
          textSlotCount += 1;
          segments.push({
            id: `text-${textSlotCount}`,
            text: '',
            startOffset: range.startOffset,
            endOffset: range.endOffset,
            labelHint: getTextSegmentLabelHint(nextAncestors),
          });
        }
        return;
      }
      for (const child of node.childNodes || []) {
        collect(child, nextAncestors);
      }
    };

    collect(root, [root]);
    return segments;
  };

  const createEmptySegment = (
    root: DefaultTreeAdapterTypes.Element
  ): EditableTextSegment | null => {
    const childNodes = root.childNodes || [];
    const canEditEmptyBody =
      childNodes.length === 0 ||
      childNodes.every((child) => isTextNode(child) || isCommentNode(child));
    if (!canEditEmptyBody) {
      return null;
    }
    const range = getInnerRange('', root.tagName, root.sourceCodeLocation);
    if (!range) {
      return null;
    }
    return {
      id: 'text-1',
      text: '',
      startOffset: range.startOffset,
      endOffset: range.endOffset,
      labelHint: getTextSegmentLabelHint([root]),
    };
  };

  const selectedSegments = collectSegments(selectedElement);
  const selectedTagName = getElementTagName(selectedElement);
  const parent = selectedAncestors[selectedAncestors.length - 1];
  const emptySegment = createEmptySegment(selectedElement);
  const selectedSegmentsWithFallback =
    selectedSegments.length > 0 ? selectedSegments : emptySegment ? [emptySegment] : [];
  if (
    parent &&
    INLINE_TEXT_WRAPPER_TAGS.has(selectedTagName) &&
    !TEXT_SEGMENT_SKIP_TAGS.has(getElementTagName(parent)) &&
    !VOID_TAGS.has(parent.tagName)
  ) {
    const parentSegments = collectSegments(parent);
    if (parentSegments.length > selectedSegmentsWithFallback.length) {
      return parentSegments;
    }
  }

  return selectedSegmentsWithFallback;
}

export function getEditableElementAttributes(html: string, lcId: string): ElementAttributesInfo | null {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let result: ElementAttributesInfo | null = null;

  const walk = (node: DefaultTreeAdapterTypes.ParentNode) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;

        if (id === lcId) {
          const startTag = child.sourceCodeLocation?.startTag;
          if (
            !startTag ||
            typeof startTag.startOffset !== 'number' ||
            typeof startTag.endOffset !== 'number'
          ) {
            result = null;
            return;
          }
          const startOffset = startTag.startOffset;
          const endOffset = startTag.endOffset;
          const startTagText = html.slice(startOffset, endOffset);
          const selfClosing = /\/\s*>$/.test(startTagText);
          const attributes = child.attrs
            .filter((attr) => attr.name !== KAYZART_ATTR_NAME)
            .map((attr) => ({
              name: attr.name,
              value: attr.value ?? '',
            }));

          result = {
            attributes,
            startOffset,
            endOffset,
            tagName: child.tagName,
            isVoid: VOID_TAGS.has(child.tagName),
            selfClosing,
          };
          return;
        }
        walk(child);
        if (result) return;
        if (isTemplateElement(child)) {
          walk(child.content);
          if (result) return;
        }
      } else if (isParentNode(child)) {
        walk(child);
        if (result) return;
      }
    }
  };

  walk(root);
  return result;
}

export function getElementActionInfo(html: string, lcId: string): ElementActionInfo | null {
  const selected = findElementLookupEntry(html, lcId);
  if (!selected) {
    return null;
  }
  const candidates = [selected, ...selected.ancestors.slice().reverse()];
  for (const entry of candidates) {
    const info = getActionInfoFromEntry(entry);
    if (info) {
      return info;
    }
  }
  return null;
}

function findNearestPictureEntry(entry: ElementLookupEntry): ElementLookupEntry | null {
  return entry.ancestors
    .slice()
    .reverse()
    .find((ancestor) => ancestor.element.tagName.toLowerCase() === 'picture') ?? null;
}

function pictureHasResponsiveSources(picture: DefaultTreeAdapterTypes.Element): boolean {
  let hasSources = false;
  const walk = (node: DefaultTreeAdapterTypes.Node) => {
    if (hasSources) {
      return;
    }
    if (isElement(node)) {
      if (
        node.tagName.toLowerCase() === 'source' &&
        (hasElementAttribute(node, 'srcset') || hasElementAttribute(node, 'data-srcset'))
      ) {
        hasSources = true;
        return;
      }
      if (isTemplateElement(node)) {
        walk(node.content);
        return;
      }
    }
    if (!isParentNode(node)) {
      return;
    }
    for (const child of node.childNodes || []) {
      walk(child);
      if (hasSources) {
        return;
      }
    }
  };
  walk(picture);
  return hasSources;
}

function collectSourceElements(
  node: DefaultTreeAdapterTypes.Node,
  sources: DefaultTreeAdapterTypes.Element[]
) {
  if (isElement(node)) {
    if (node.tagName.toLowerCase() === 'source') {
      sources.push(node);
    }
    if (isTemplateElement(node)) {
      collectSourceElements(node.content, sources);
      return;
    }
  }
  if (!isParentNode(node)) {
    return;
  }
  for (const child of node.childNodes || []) {
    collectSourceElements(child, sources);
  }
}

function getFirstSrcsetUrl(value: string): string {
  const firstCandidate = value
    .split(',')
    .map((candidate) => candidate.trim())
    .find((candidate) => candidate.length > 0);
  return firstCandidate?.split(/\s+/)[0] ?? '';
}

function getFirstPictureSourceUrl(picture: ElementLookupEntry | null): string {
  if (!picture) {
    return '';
  }
  const sources: DefaultTreeAdapterTypes.Element[] = [];
  collectSourceElements(picture.element, sources);
  for (const source of sources) {
    const srcsetUrl = getFirstSrcsetUrl(getElementAttributeValue(source, 'srcset'));
    if (srcsetUrl) {
      return srcsetUrl;
    }
    const dataSrcsetUrl = getFirstSrcsetUrl(getElementAttributeValue(source, 'data-srcset'));
    if (dataSrcsetUrl) {
      return dataSrcsetUrl;
    }
  }
  return '';
}

function getImageDisplaySource(
  selected: ElementLookupEntry,
  picture: ElementLookupEntry | null
): string {
  const src = getElementAttributeValue(selected.element, 'src').trim();
  if (src) {
    return src;
  }
  const dataSrc = getElementAttributeValue(selected.element, 'data-src').trim();
  if (dataSrc) {
    return dataSrc;
  }
  const srcsetUrl = getFirstSrcsetUrl(getElementAttributeValue(selected.element, 'srcset'));
  if (srcsetUrl) {
    return srcsetUrl;
  }
  const dataSrcsetUrl = getFirstSrcsetUrl(getElementAttributeValue(selected.element, 'data-srcset'));
  if (dataSrcsetUrl) {
    return dataSrcsetUrl;
  }
  return getFirstPictureSourceUrl(picture);
}

function buildAttributeEditInfo(
  html: string,
  element: DefaultTreeAdapterTypes.Element,
  attributeName: ElementImageSourceEditInfo['attributeName'],
  insertMissing: boolean
): ElementImageSourceEditInfo | null {
  const startTag = element.sourceCodeLocation?.startTag;
  if (
    !startTag ||
    typeof startTag.startOffset !== 'number' ||
    typeof startTag.endOffset !== 'number'
  ) {
    return null;
  }
  const valueRange = findAttributeValueRange(
    html,
    startTag.startOffset,
    startTag.endOffset,
    attributeName
  );
  if (valueRange) {
    return {
      ...valueRange,
      attributeName,
      insertPrefix: '',
      insertSuffix: '',
    };
  }
  if (!insertMissing) {
    return null;
  }
  const insertOffset = getStartTagInsertionOffset(
    html,
    startTag.startOffset,
    startTag.endOffset
  );
  return {
    startOffset: insertOffset,
    endOffset: insertOffset,
    attributeName,
    insertPrefix: ` ${attributeName}="`,
    insertSuffix: '"',
  };
}

export function getElementImageInfo(html: string, lcId: string): ElementImageInfo | null {
  const selected = findElementLookupEntry(html, lcId);
  if (!selected || selected.element.tagName.toLowerCase() !== 'img') {
    return null;
  }
  const picture = findNearestPictureEntry(selected);
  return {
    imageLcId: selected.lcId,
    tagName: 'img',
    src: getImageDisplaySource(selected, picture),
    alt: getElementAttributeValue(selected.element, 'alt'),
    title: getElementAttributeValue(selected.element, 'title'),
    hasSrcset: hasElementAttribute(selected.element, 'srcset'),
    hasDataSrc: hasElementAttribute(selected.element, 'data-src'),
    hasDataSrcset: hasElementAttribute(selected.element, 'data-srcset'),
    hasPictureSources: picture ? pictureHasResponsiveSources(picture.element) : false,
  };
}

export function getElementImageSourceEditInfos(
  html: string,
  lcId: string
): ElementImageSourceEditInfo[] {
  const selected = findElementLookupEntry(html, lcId);
  if (!selected || selected.element.tagName.toLowerCase() !== 'img') {
    return [];
  }

  const edits: ElementImageSourceEditInfo[] = [];
  const imgSourceAttributes: Array<ElementImageSourceEditInfo['attributeName']> = [
    'src',
    'srcset',
    'data-src',
    'data-srcset',
  ];

  imgSourceAttributes.forEach((attributeName) => {
    const edit = buildAttributeEditInfo(html, selected.element, attributeName, attributeName === 'src');
    if (edit) {
      edits.push(edit);
    }
  });

  const picture = findNearestPictureEntry(selected);
  if (picture) {
    const sources: DefaultTreeAdapterTypes.Element[] = [];
    collectSourceElements(picture.element, sources);
    sources.forEach((source) => {
      (['srcset', 'data-srcset'] as const).forEach((attributeName) => {
        const edit = buildAttributeEditInfo(html, source, attributeName, false);
        if (edit) {
          edits.push(edit);
        }
      });
    });
  }

  return edits;
}

export function getImageSourceEditInfo(html: string, lcId: string): ImageSourceEditInfo | null {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let result: ImageSourceEditInfo | null = null;

  const walk = (node: DefaultTreeAdapterTypes.ParentNode) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;

        if (id === lcId) {
          if (child.tagName.toLowerCase() !== 'img') {
            result = null;
            return;
          }
          const startTag = child.sourceCodeLocation?.startTag;
          if (
            !startTag ||
            typeof startTag.startOffset !== 'number' ||
            typeof startTag.endOffset !== 'number'
          ) {
            result = null;
            return;
          }
          const srcRange = findAttributeValueRange(
            html,
            startTag.startOffset,
            startTag.endOffset,
            'src'
          );
          if (srcRange) {
            result = { ...srcRange, insertPrefix: '', insertSuffix: '' };
            return;
          }
          const insertOffset = getStartTagInsertionOffset(
            html,
            startTag.startOffset,
            startTag.endOffset
          );
          result = {
            startOffset: insertOffset,
            endOffset: insertOffset,
            insertPrefix: ' src="',
            insertSuffix: '"',
          };
          return;
        }
        walk(child);
        if (result) return;
        if (isTemplateElement(child)) {
          walk(child.content);
          if (result) return;
        }
      } else if (isParentNode(child)) {
        walk(child);
        if (result) return;
      }
    }
  };

  walk(root);
  return result;
}

export function getElementContext(html: string, lcId: string): ElementContextInfo | null {
  const root = parseElementLookupRoot(html);
  let seq = 0;
  let result: ElementContextInfo | null = null;

  const walk = (node: DefaultTreeAdapterTypes.ParentNode) => {
    for (const child of node.childNodes || []) {
      if (isElement(child)) {
        const existingId = getExistingLcId(child);
        const id = existingId ?? `kayzart-${++seq}`;

        if (id === lcId) {
          const outerStart = child.sourceCodeLocation?.startOffset;
          const outerEnd = child.sourceCodeLocation?.endOffset;
          const hasOuterRange =
            typeof outerStart === 'number' &&
            typeof outerEnd === 'number' &&
            outerStart <= outerEnd;
          const sourceRange = hasOuterRange
            ? { startOffset: outerStart, endOffset: outerEnd }
            : undefined;
          const outerHTML = hasOuterRange ? html.slice(outerStart, outerEnd) : '';
          const attributes = child.attrs
            .filter((attr) => attr.name !== KAYZART_ATTR_NAME)
            .map((attr) => ({
              name: attr.name,
              value: attr.value ?? '',
            }));
          let text: string | null = null;
          if (!VOID_TAGS.has(child.tagName)) {
            const descendantText = collectDescendantText(child).replace(/\s+/g, ' ').trim();
            text = descendantText || null;
          }
          result = {
            lcId: id,
            tagName: child.tagName,
            attributes,
            text,
            outerHTML,
            sourceRange,
          };
          return;
        }
        walk(child);
        if (result) return;
        if (isTemplateElement(child)) {
          walk(child.content);
          if (result) return;
        }
      } else if (isParentNode(child)) {
        walk(child);
        if (result) return;
      }
    }
  };

  walk(root);
  return result;
}

