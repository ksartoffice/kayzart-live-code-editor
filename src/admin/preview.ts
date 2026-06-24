import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';
import { EditorRange, type CodeEditorInstance, type EditorModel } from './codemirror';
import type { JsMode } from './types/js-mode';
import {
  mediaQueriesMatch,
  parseCssRules,
  selectorMatches,
  splitSelectors,
} from './css-rules';

type SourceRange = {
  startOffset: number;
  endOffset: number;
};

type CanonicalResult = {
  canonicalHTML: string;
  bodyAttrs: Record<string, string>;
  hasBody: boolean;
  map: Record<string, SourceRange>;
  error?: string;
};

export type PreviewController = {
  sendRender: () => void;
  sendCssUpdate: (cssText: string) => void;
  sendLiveHighlightUpdate: (enabled: boolean) => void;
  sendElementsTabState: (open: boolean) => void;
  requestReloadPreview: () => void;
  requestDisableJs: () => void;
  queueInitialJsRun: () => void;
  flushPendingJsAction: () => void;
  resetCanonicalCache: () => void;
  clearSelectionHighlight: () => void;
  clearCssSelectionHighlight: () => void;
  handleIframeLoad: () => void;
  handleMessage: (event: MessageEvent) => void;
};

type PreviewControllerDeps = {
  iframe: HTMLIFrameElement;
  postId: number;
  targetOrigin: string;
  htmlModel: EditorModel;
  customHeadModel: EditorModel;
  cssModel: EditorModel;
  jsModel: EditorModel;
  htmlEditor: CodeEditorInstance;
  cssEditor: CodeEditorInstance;
  focusHtmlEditor: () => void;
  getPreviewCss: () => string;
  getCustomHead: () => string;
  getLiveHighlightEnabled: () => boolean;
  getJsEnabled: () => boolean;
  getJsMode: () => JsMode;
  isTailwindEnabled: () => boolean;
  getResolvedTemplateMode: () => 'standalone' | 'theme';
  onSelect?: (lcId: string) => void;
  onOpenElementsTab?: () => void;
  onCopyElementHtml?: (lcId: string) => void;
  onDeleteElement?: (lcId: string) => void;
  onOverlayAction?: (actionId: string) => void;
  onMissingMarkers?: () => void;
  onReloadApplied?: () => void;
};

const KAYZART_ATTR_NAME = 'data-kayzart-id';

function isElement(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.Element {
  return (node as DefaultTreeAdapterTypes.Element).tagName !== undefined;
}

function isParentNode(node: DefaultTreeAdapterTypes.Node): node is DefaultTreeAdapterTypes.ParentNode {
  return Array.isArray((node as DefaultTreeAdapterTypes.ParentNode).childNodes);
}

function isTemplateElement(node: DefaultTreeAdapterTypes.Element): node is DefaultTreeAdapterTypes.Template {
  return node.tagName === 'template' && Boolean((node as DefaultTreeAdapterTypes.Template).content);
}

function findElement(
  node: DefaultTreeAdapterTypes.Node,
  tagName: string
): DefaultTreeAdapterTypes.Element | null {
  if (isElement(node) && node.tagName.toLowerCase() === tagName.toLowerCase()) {
    return node;
  }
  if (!isParentNode(node)) {
    return null;
  }
  for (const child of node.childNodes) {
    const match = findElement(child, tagName);
    if (match) {
      return match;
    }
  }
  return null;
}

function serializeAttrs(el: DefaultTreeAdapterTypes.Element): Record<string, string> {
  return el.attrs.reduce<Record<string, string>>((attrs, attr) => {
    attrs[attr.name] = attr.value;
    return attrs;
  }, {});
}

function serializeChildren(node: DefaultTreeAdapterTypes.ParentNode): string {
  return (node.childNodes || []).map((child) => parse5.serializeOuter(child)).join('');
}

function upsertLcAttr(el: DefaultTreeAdapterTypes.Element, lcId: string) {
  const existing = el.attrs.find((attr) => attr.name === KAYZART_ATTR_NAME);
  if (existing) {
    existing.value = lcId;
  } else {
    el.attrs.push({ name: KAYZART_ATTR_NAME, value: lcId });
  }
}

function getExistingLcId(el: DefaultTreeAdapterTypes.Element): string | null {
  const attr = el.attrs.find((item) => item.name === KAYZART_ATTR_NAME);
  return attr ? attr.value : null;
}

function resolveRange(
  loc: DefaultTreeAdapterTypes.Element['sourceCodeLocation'],
  parentRange: SourceRange | null,
  mapOffsetToOriginal?: (offset: number) => number
): SourceRange | null {
  if (loc && typeof loc.startOffset === 'number' && typeof loc.endOffset === 'number') {
    const start = mapOffsetToOriginal ? mapOffsetToOriginal(loc.startOffset) : loc.startOffset;
    const end = mapOffsetToOriginal ? mapOffsetToOriginal(loc.endOffset) : loc.endOffset;
    return { startOffset: start, endOffset: end };
  }
  return parentRange ? { ...parentRange } : null;
}

function walkCanonicalTree(
  node: DefaultTreeAdapterTypes.ParentNode,
  parentRange: SourceRange | null,
  map: Record<string, SourceRange>,
  nextId: () => string,
  mapOffsetToOriginal?: (offset: number) => number,
  rangeOverride?: Record<string, SourceRange>
) {
  const children = node.childNodes || [];

  for (const child of children) {
    if (isElement(child)) {
      const existingId = getExistingLcId(child);
      const lcId = existingId ?? nextId();
      upsertLcAttr(child, lcId);
      const range =
        (rangeOverride && rangeOverride[lcId]) ||
        resolveRange(child.sourceCodeLocation, parentRange, mapOffsetToOriginal);
      if (range) {
        map[lcId] = range;
      }
      walkCanonicalTree(child, range ?? parentRange, map, nextId, mapOffsetToOriginal, rangeOverride);

      if (isTemplateElement(child)) {
        walkCanonicalTree(
          child.content,
          range ?? parentRange,
          map,
          nextId,
          mapOffsetToOriginal,
          rangeOverride
        );
      }
    } else if (isParentNode(child)) {
      walkCanonicalTree(child, parentRange, map, nextId, mapOffsetToOriginal, rangeOverride);
    }
  }
}

// Build canonical HTML and keep data-kayzart-id plus source-location mapping.
function canonicalizeHtml(html: string): CanonicalResult {
  try {
    if (html.toLowerCase().includes('<body')) {
      const document = parse5.parse(html, { sourceCodeLocationInfo: true });
      const body = findElement(document, 'body');
      if (body) {
        const map: Record<string, SourceRange> = {};
        let seq = 0;
        const nextId = () => `kayzart-${++seq}`;

        walkCanonicalTree(body, null, map, nextId);

        return {
          canonicalHTML: serializeChildren(body),
          bodyAttrs: serializeAttrs(body),
          hasBody: true,
          map,
        };
      }
    }

    const fragment = parse5.parseFragment(html, { sourceCodeLocationInfo: true });
    const map: Record<string, SourceRange> = {};
    let seq = 0;
    const nextId = () => `kayzart-${++seq}`;

    walkCanonicalTree(fragment, null, map, nextId);

    return { canonicalHTML: parse5.serialize(fragment), bodyAttrs: {}, hasBody: false, map };
  } catch (error: any) {
    console.error('[Kayzart] canonicalizeHtml failed', error);
    return {
      canonicalHTML: html,
      bodyAttrs: {},
      hasBody: false,
      map: {},
      error: error?.message ?? String(error),
    };
  }
}

export function createPreviewController(deps: PreviewControllerDeps): PreviewController {
  let previewWindow: WindowProxy | null = deps.iframe.contentWindow;
  let previewReady = false;
  let pendingRender = false;
  let pendingJsAction: 'run' | 'disable' | null = null;
  let pendingReloadAppliedNotice = false;
  let initialJsPending = true;
  let pendingElementsTabOpen: boolean | null = null;
  let canonicalCache: CanonicalResult | null = null;
  let canonicalCacheHtml = '';
  let canonicalDomCacheHtml = '';
  let canonicalDomRoot: HTMLElement | null = null;
  let lcSourceMap: Record<string, SourceRange> = {};
  let lastCanonicalError: string | null = null;
  let selectionDecorations: string[] = [];
  let cssSelectionDecorations: string[] = [];
  let lastSelectedLcId: string | null = null;
  let elementsTabOpen = false;
  let basePreviewHtml: string | null = null;
  let basePreviewFetch: Promise<string> | null = null;
  let embeddedCustomHead: string | null = null;
  let renderedCustomHead = typeof deps.getCustomHead === 'function' ? deps.getCustomHead() : '';
  let expectingSrcdocLoad = false;
  let currentBlobUrl: string | null = null;

  const getCanonical = () => {
    const html = deps.htmlModel.getValue();
    if (canonicalCache && html === canonicalCacheHtml) {
      return canonicalCache;
    }
    canonicalCacheHtml = html;
    canonicalCache = canonicalizeHtml(html);
    return canonicalCache;
  };

  const resetCanonicalCache = () => {
    canonicalCache = null;
    canonicalCacheHtml = '';
    canonicalDomCacheHtml = '';
    canonicalDomRoot = null;
  };

  const getCanonicalDomRoot = () => {
    const canonical = getCanonical();
    if (canonicalDomRoot && canonical.canonicalHTML === canonicalDomCacheHtml) {
      return canonicalDomRoot;
    }
    const doc = document.implementation.createHTMLDocument('');
    const wrapper = doc.createElement('div');
    wrapper.innerHTML = canonical.canonicalHTML || '';
    doc.body.appendChild(wrapper);
    canonicalDomCacheHtml = canonical.canonicalHTML || '';
    canonicalDomRoot = wrapper;
    return wrapper;
  };

  const refreshPreviewWindow = () => {
    previewWindow = deps.iframe.contentWindow;
    return previewWindow;
  };

  const injectCustomHead = (html: string, customHead: string): string => {
    const base = `<base href="${deps.targetOrigin}/">`;
    let result = html.replace(/(<head(?:\s[^>]*)?>\s*)/i, `$1${base}\n`);
    result = result.replace(/<\/head>/i, `${customHead}\n</head>`);
    return result;
  };

  const reloadWithCustomHead = (customHead: string): void => {
    embeddedCustomHead = customHead;
    const doReload = (html: string) => {
      if (!html) return;
      if (currentBlobUrl) {
        URL.revokeObjectURL(currentBlobUrl);
      }
      const fullHtml = injectCustomHead(html, customHead);
      const blob = new Blob([fullHtml], { type: 'text/html' });
      currentBlobUrl = URL.createObjectURL(blob);
      expectingSrcdocLoad = true;
      deps.iframe.src = currentBlobUrl;
    };
    if (basePreviewHtml) {
      doReload(basePreviewHtml);
    } else if (basePreviewFetch) {
      basePreviewFetch.then(doReload);
    }
  };

  const postToPreview = (payload: Record<string, unknown>) => {
    const targetWindow = refreshPreviewWindow();
    if (!targetWindow) {
      return;
    }
    targetWindow.postMessage(payload, deps.targetOrigin);
  };

  const sendInit = () => {
    postToPreview({
      type: 'KAYZART_INIT',
      post_id: deps.postId,
    });
  };

  const sendRender = () => {
    const canonical = getCanonical();
    lcSourceMap = canonical.map;

    if (canonical.error && canonical.error !== lastCanonicalError) {
      console.error('[Kayzart] Falling back to raw HTML for preview:', canonical.error);
      lastCanonicalError = canonical.error;
    } else if (!canonical.error) {
      lastCanonicalError = null;
    }

    if (!previewReady) {
      pendingRender = true;
      return;
    }

    const payload: Record<string, unknown> = {
      type: 'KAYZART_RENDER',
      cssText: deps.getPreviewCss(),
      liveHighlightEnabled: deps.getLiveHighlightEnabled(),
      bodyAttrs: canonical.bodyAttrs,
      hasBody: canonical.hasBody,
      templateMode: deps.getResolvedTemplateMode(),
      canonicalHTML: canonical.canonicalHTML,
    };
    if (embeddedCustomHead === null) {
      payload.customHead = renderedCustomHead;
    }
    postToPreview(payload);
  };

  const sendCssUpdate = (cssText: string) => {
    if (!previewReady) {
      return;
    }
    postToPreview({
      type: 'KAYZART_SET_CSS',
      cssText: cssText,
    });
  };

  const sendRunJs = () => {
    if (!deps.getJsEnabled()) return;
    if (!deps.jsModel) {
      pendingJsAction = 'run';
      return;
    }
    if (!previewReady) {
      pendingJsAction = 'run';
      return;
    }
    postToPreview({
      type: 'KAYZART_RUN_JS',
      jsText: deps.jsModel.getValue(),
      jsMode: deps.getJsMode(),
    });
  };

  const requestReloadPreview = () => {
    const currentCustomHead =
      typeof deps.getCustomHead === 'function' ? deps.getCustomHead() : '';
    renderedCustomHead = currentCustomHead;
    pendingRender = true;
    pendingReloadAppliedNotice = true;
    if (deps.getJsEnabled() && deps.jsModel?.getValue().trim()) {
      pendingJsAction = 'run';
    } else if (!deps.getJsEnabled()) {
      pendingJsAction = 'disable';
    } else {
      pendingJsAction = null;
    }
    reloadWithCustomHead(currentCustomHead);
  };

  const requestDisableJs = () => {
    if (!previewReady) {
      pendingJsAction = 'disable';
      return;
    }
    postToPreview({ type: 'KAYZART_DISABLE_JS' });
  };

  const sendLiveHighlightUpdate = (enabled: boolean) => {
    if (!previewReady) {
      return;
    }
    postToPreview({
      type: 'KAYZART_SET_HIGHLIGHT',
      liveHighlightEnabled: enabled,
    });
  };

  const sendElementsTabState = (open: boolean) => {
    elementsTabOpen = open;
    if (!previewReady) {
      pendingElementsTabOpen = open;
      return;
    }
    postToPreview({
      type: 'KAYZART_SET_ELEMENTS_TAB_OPEN',
      open,
    });
  };

  const queueInitialJsRun = () => {
    if (!initialJsPending || !deps.getJsEnabled() || !deps.jsModel) {
      return;
    }
    if (!deps.jsModel.getValue().trim()) {
      initialJsPending = false;
      return;
    }
    initialJsPending = false;
    pendingJsAction = 'run';
  };

  const flushPendingJsAction = () => {
    if (!pendingJsAction) return;
    const action = pendingJsAction;
    if (action === 'disable') {
      pendingJsAction = null;
      requestDisableJs();
    }
  };

  const clearSelectionHighlight = () => {
    selectionDecorations = deps.htmlModel.deltaDecorations(selectionDecorations, []);
    cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
    deps.htmlEditor.clearScrollRulerMarkers();
    deps.cssEditor.clearScrollRulerMarkers();
  };

  const clearCssSelectionHighlight = () => {
    cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
    deps.cssEditor.clearScrollRulerMarkers();
  };

  const highlightCssByLcId = (lcId: string) => {
    lastSelectedLcId = lcId;
    if (deps.isTailwindEnabled()) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      deps.cssEditor.clearScrollRulerMarkers();
      return;
    }
    const cssText = deps.cssModel.getValue();
    if (!cssText.trim()) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      deps.cssEditor.clearScrollRulerMarkers();
      return;
    }
    const root = getCanonicalDomRoot();
    const target = root?.querySelector(`[${KAYZART_ATTR_NAME}="${lcId}"]`);
    if (!target) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      deps.cssEditor.clearScrollRulerMarkers();
      return;
    }
    const rules = parseCssRules(cssText);
    const matched = rules.filter((rule) => {
      if (!mediaQueriesMatch(rule.mediaQueries)) return false;
      const cleanedSelectorText = rule.selectorText.replace(/\/\*[\s\S]*?\*\//g, ' ');
      const selectors = splitSelectors(cleanedSelectorText);
      return selectors.some((selector) => selectorMatches(target, selector));
    });
    if (!matched.length) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      deps.cssEditor.clearScrollRulerMarkers();
      return;
    }
    const matchedRanges = matched.map((rule) => {
      const startPos = deps.cssModel.getPositionAt(rule.startOffset);
      const endPos = deps.cssModel.getPositionAt(rule.endOffset);
      return new EditorRange(
        startPos.lineNumber,
        startPos.column,
        endPos.lineNumber,
        endPos.column
      );
    });
    cssSelectionDecorations = deps.cssModel.deltaDecorations(
      cssSelectionDecorations,
      matchedRanges.map((range) => {
        return {
          range,
          options: {
            className: 'kayzart-highlight-line',
            inlineClassName: 'kayzart-highlight-inline',
          },
        };
      })
    );
    deps.cssEditor.setScrollRulerMarkers(
      matchedRanges.map((range, index) => ({
        range,
        className: 'kayzart-scrollRulerMarker-css',
        title: `CSS match ${index + 1}`,
      }))
    );
    const first = matched[0];
    if (first) {
      const startPos = deps.cssModel.getPositionAt(first.startOffset);
      const endPos = deps.cssModel.getPositionAt(first.endOffset);
      const range = new EditorRange(
        startPos.lineNumber,
        startPos.column,
        endPos.lineNumber,
        endPos.column
      );
      deps.cssEditor.revealRangeInCenter(range);
    }
  };

  const highlightByLcId = (lcId: string) => {
    const rangeInfo = lcSourceMap[lcId];
    if (!rangeInfo) {
      console.warn('[Kayzart] No source map for kayzart-id:', lcId);
      return;
    }
    deps.focusHtmlEditor();
    const startPos = deps.htmlModel.getPositionAt(rangeInfo.startOffset);
    const endPos = deps.htmlModel.getPositionAt(rangeInfo.endOffset);
    const selectedRange = new EditorRange(
      startPos.lineNumber,
      startPos.column,
      endPos.lineNumber,
      endPos.column
    );
    selectionDecorations = deps.htmlModel.deltaDecorations(selectionDecorations, [
      {
        range: selectedRange,
        options: {
          className: 'kayzart-highlight-line',
          inlineClassName: 'kayzart-highlight-inline',
        },
      },
    ]);
    deps.htmlEditor.setScrollRulerMarkers([
      {
        range: selectedRange,
        className: 'kayzart-scrollRulerMarker-html',
        title: 'HTML match',
      },
    ]);
    deps.htmlEditor.revealRangeInCenter(selectedRange);
    deps.htmlEditor.focus();
    highlightCssByLcId(lcId);
  };

  const handleIframeLoad = () => {
    refreshPreviewWindow();
    previewReady = false;
    pendingRender = true;
    initialJsPending = true;
    if (expectingSrcdocLoad) {
      expectingSrcdocLoad = false;
    } else {
      embeddedCustomHead = null;
      basePreviewHtml = null;
      if (currentBlobUrl) {
        URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = null;
      }
      const fetchUrl = deps.iframe.src;
      basePreviewFetch = fetch(fetchUrl, { credentials: 'same-origin' })
        .then((r) => r.text())
        .then((html) => { basePreviewHtml = html; return html; })
        .catch(() => { basePreviewFetch = null; return ''; });
    }
    sendInit();
  };

  const handleMessage = (event: MessageEvent) => {
    if (event.origin !== deps.targetOrigin) return;
    const targetWindow = refreshPreviewWindow();
    if (!targetWindow || event.source !== targetWindow) return;
    const data = event.data;

    if (data?.type === 'KAYZART_READY') {
      previewReady = true;
      if (pendingRender) {
        pendingRender = false;
      }
      sendRender();
      queueInitialJsRun();
      flushPendingJsAction();
      if (pendingElementsTabOpen !== null) {
        const nextOpen = pendingElementsTabOpen;
        pendingElementsTabOpen = null;
        sendElementsTabState(nextOpen);
      } else {
        sendElementsTabState(elementsTabOpen);
      }
    }

    if (data?.type === 'KAYZART_RENDERED') {
      if (pendingJsAction === 'run') {
        pendingJsAction = null;
        sendRunJs();
      }
      if (pendingReloadAppliedNotice) {
        pendingReloadAppliedNotice = false;
        deps.onReloadApplied?.();
      }
    }

    if (data?.type === 'KAYZART_SELECT' && typeof data.lcId === 'string') {
      deps.onSelect?.(data.lcId);
      highlightByLcId(data.lcId);
    }

    if (data?.type === 'KAYZART_OPEN_ELEMENTS_TAB') {
      deps.onOpenElementsTab?.();
    }

    if (data?.type === 'KAYZART_COPY_ELEMENT_HTML' && typeof data.lcId === 'string') {
      deps.onCopyElementHtml?.(data.lcId);
    }

    if (data?.type === 'KAYZART_DELETE_ELEMENT' && typeof data.lcId === 'string') {
      deps.onDeleteElement?.(data.lcId);
    }

    if (data?.type === 'KAYZART_OVERLAY_ACTION' && typeof data.actionId === 'string') {
      deps.onOverlayAction?.(data.actionId);
    }

    if (data?.type === 'KAYZART_MISSING_MARKERS') {
      console.warn('[Kayzart] Preview markers are missing in the iframe document.');
      deps.onMissingMarkers?.();
    }
  };

  return {
    sendRender,
    sendCssUpdate,
    sendLiveHighlightUpdate,
    sendElementsTabState,
    requestReloadPreview,
    requestDisableJs,
    queueInitialJsRun,
    flushPendingJsAction,
    resetCanonicalCache,
    clearSelectionHighlight,
    clearCssSelectionHighlight,
    handleIframeLoad,
    handleMessage,
  };
}


