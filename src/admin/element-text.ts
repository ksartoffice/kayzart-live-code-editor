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

export type ElementActionInfo = {
  actionLcId: string;
  kind: 'link' | 'button';
  tagName: string;
  href: string;
  targetBlank: boolean;
  rel: string;
  disabled: boolean;
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
  if (!selected || VOID_TAGS.has(selected.tagName)) {
    return [];
  }

  const collectSegments = (root: DefaultTreeAdapterTypes.Element): EditableTextSegment[] => {
    const segments: EditableTextSegment[] = [];
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
        if (text.trim().length === 0) {
          return;
        }
        segments.push({
          id: `text-${segments.length + 1}`,
          text,
          startOffset: loc.startOffset + leading,
          endOffset: loc.endOffset - trailing,
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

  const selectedSegments = collectSegments(selected);
  const selectedTagName = getElementTagName(selected);
  const parent = selectedAncestors[selectedAncestors.length - 1];
  const selectedSegmentsWithFallback =
    selectedSegments.length > 0 ? selectedSegments : [createEmptySegment(selected)].filter(Boolean);
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

