import * as parse5 from 'parse5';
import type { DefaultTreeAdapterTypes } from 'parse5';
import type { MonacoType } from './monaco';
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
  map: Record<string, SourceRange>;
  error?: string;
};

export type PreviewController = {
  sendRender: () => void;
  sendCssUpdate: (cssText: string) => void;
  sendExternalScripts: (scripts: string[]) => void;
  sendExternalStyles: (styles: string[]) => void;
  sendLiveHighlightUpdate: (enabled: boolean) => void;
  sendElementsTabState: (open: boolean) => void;
  requestRunJs: () => void;
  requestDisableJs: () => void;
  queueInitialJsRun: () => void;
  flushPendingJsAction: () => void;
  isRunJsPending: () => boolean;
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
  monaco: MonacoType;
  htmlModel: import('monaco-editor').editor.ITextModel;
  cssModel: import('monaco-editor').editor.ITextModel;
  jsModel: import('monaco-editor').editor.ITextModel;
  htmlEditor: import('monaco-editor').editor.IStandaloneCodeEditor;
  cssEditor: import('monaco-editor').editor.IStandaloneCodeEditor;
  focusHtmlEditor: () => void;
  getPreviewCss: () => string;
  getShadowDomEnabled: () => boolean;
  getLiveHighlightEnabled: () => boolean;
  getJsEnabled: () => boolean;
  getJsMode: () => JsMode;
  getExternalScripts: () => string[];
  getExternalStyles: () => string[];
  isTailwindEnabled: () => boolean;
  onSelect?: (lcId: string) => void;
  onOpenElementsTab?: () => void;
  onMissingMarkers?: () => void;
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
    const fragment = parse5.parseFragment(html, { sourceCodeLocationInfo: true });
    const map: Record<string, SourceRange> = {};
    let seq = 0;
    const nextId = () => `cd-${++seq}`;

    walkCanonicalTree(fragment, null, map, nextId);

    return { canonicalHTML: parse5.serialize(fragment), map };
  } catch (error: any) {
    console.error('[KayzArt] canonicalizeHtml failed', error);
    return {
      canonicalHTML: html,
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
  const overviewHighlightColor = 'rgba(96, 165, 250, 0.35)';
  let elementsTabOpen = false;

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
      console.error('[KayzArt] Falling back to raw HTML for preview:', canonical.error);
      lastCanonicalError = canonical.error;
    } else if (!canonical.error) {
      lastCanonicalError = null;
    }

    const payload = {
      type: 'KAYZART_RENDER',
      cssText: deps.getPreviewCss(),
      shadowDomEnabled: deps.getShadowDomEnabled(),
      liveHighlightEnabled: deps.getLiveHighlightEnabled(),
    };
    if (!previewReady) {
      pendingRender = true;
      return;
    }
    postToPreview({ ...payload, canonicalHTML: canonical.canonicalHTML });
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

  const reloadPreviewIframe = (): boolean => {
    const iframe = deps.iframe;
    if (!iframe) return false;
    const targetWindow = refreshPreviewWindow();
    if (targetWindow) {
      try {
        targetWindow.location.reload();
        return true;
      } catch (error) {
        // fallback to src refresh below
      }
    }
    const src = iframe.getAttribute('src');
    if (!src) return false;
    try {
      const url = new URL(src, window.location.href);
      url.searchParams.set('cd_js_reload', String(Date.now()));
      iframe.src = url.toString();
      return true;
    } catch (error) {
      iframe.src = src;
      return true;
    }
  };

  const requestRunJs = () => {
    if (!deps.getJsEnabled()) return;
    if (!deps.jsModel) {
      pendingJsAction = 'run';
      return;
    }
    if (!previewReady) {
      pendingJsAction = 'run';
      return;
    }
    pendingJsAction = 'run';
    if (reloadPreviewIframe()) {
      return;
    }
    sendRender();
  };

  const requestDisableJs = () => {
    if (!previewReady) {
      pendingJsAction = 'disable';
      return;
    }
    postToPreview({ type: 'KAYZART_DISABLE_JS' });
  };

  const sendExternalScripts = (scripts: string[]) => {
    if (!previewReady) {
      return;
    }
    postToPreview({
      type: 'KAYZART_EXTERNAL_SCRIPTS',
      urls: scripts,
    });
  };

  const sendExternalStyles = (styles: string[]) => {
    if (!previewReady) {
      return;
    }
    postToPreview({
      type: 'KAYZART_EXTERNAL_STYLES',
      urls: styles,
    });
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

  const isRunJsPending = () => pendingJsAction === 'run';

  const clearSelectionHighlight = () => {
    selectionDecorations = deps.htmlModel.deltaDecorations(selectionDecorations, []);
    cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
  };

  const clearCssSelectionHighlight = () => {
    cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
  };

  const highlightCssByLcId = (lcId: string) => {
    lastSelectedLcId = lcId;
    if (deps.isTailwindEnabled()) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      return;
    }
    const cssText = deps.cssModel.getValue();
    if (!cssText.trim()) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
      return;
    }
    const root = getCanonicalDomRoot();
    const target = root?.querySelector(`[${KAYZART_ATTR_NAME}="${lcId}"]`);
    if (!target) {
      cssSelectionDecorations = deps.cssModel.deltaDecorations(cssSelectionDecorations, []);
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
      return;
    }
    cssSelectionDecorations = deps.cssModel.deltaDecorations(
      cssSelectionDecorations,
      matched.map((rule) => {
        const startPos = deps.cssModel.getPositionAt(rule.startOffset);
        const endPos = deps.cssModel.getPositionAt(rule.endOffset);
        return {
          range: new deps.monaco.Range(
            startPos.lineNumber,
            startPos.column,
            endPos.lineNumber,
            endPos.column
          ),
          options: {
            className: 'cd-highlight-line',
            inlineClassName: 'cd-highlight-inline',
            overviewRuler: {
              color: overviewHighlightColor,
              position: deps.monaco.editor.OverviewRulerLane.Full,
            },
          },
        };
      })
    );
    const first = matched[0];
    if (first) {
      const startPos = deps.cssModel.getPositionAt(first.startOffset);
      const endPos = deps.cssModel.getPositionAt(first.endOffset);
      const range = new deps.monaco.Range(
        startPos.lineNumber,
        startPos.column,
        endPos.lineNumber,
        endPos.column
      );
      deps.cssEditor.revealRangeInCenter(range, deps.monaco.editor.ScrollType.Smooth);
    }
  };

  const highlightByLcId = (lcId: string) => {
    const rangeInfo = lcSourceMap[lcId];
    if (!rangeInfo) {
    console.warn('[KayzArt] No source map for cd-id:', lcId);
      return;
    }
    deps.focusHtmlEditor();
    const startPos = deps.htmlModel.getPositionAt(rangeInfo.startOffset);
    const endPos = deps.htmlModel.getPositionAt(rangeInfo.endOffset);
    const monacoRange = new deps.monaco.Range(
      startPos.lineNumber,
      startPos.column,
      endPos.lineNumber,
      endPos.column
    );
    selectionDecorations = deps.htmlModel.deltaDecorations(selectionDecorations, [
      {
        range: monacoRange,
        options: {
          className: 'cd-highlight-line',
          inlineClassName: 'cd-highlight-inline',
          overviewRuler: {
            color: overviewHighlightColor,
            position: deps.monaco.editor.OverviewRulerLane.Full,
          },
        },
      },
    ]);
    deps.htmlEditor.revealRangeInCenter(monacoRange, deps.monaco.editor.ScrollType.Smooth);
    deps.htmlEditor.focus();
    highlightCssByLcId(lcId);
  };

  const handleIframeLoad = () => {
    refreshPreviewWindow();
    previewReady = false;
    pendingRender = true;
    initialJsPending = true;
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
      sendExternalScripts(deps.getJsEnabled() ? deps.getExternalScripts() : []);
      sendExternalStyles(deps.getExternalStyles());
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
    }

    if (data?.type === 'KAYZART_SELECT' && typeof data.lcId === 'string') {
      deps.onSelect?.(data.lcId);
      highlightByLcId(data.lcId);
    }

    if (data?.type === 'KAYZART_OPEN_ELEMENTS_TAB') {
      deps.onOpenElementsTab?.();
    }

    if (data?.type === 'KAYZART_MISSING_MARKERS') {
      console.warn('[KayzArt] Preview markers are missing in the iframe document.');
      deps.onMissingMarkers?.();
    }
  };

  return {
    sendRender,
    sendCssUpdate,
    sendExternalScripts,
    sendExternalStyles,
    sendLiveHighlightUpdate,
    sendElementsTabState,
    requestRunJs,
    requestDisableJs,
    queueInitialJsRun,
    flushPendingJsAction,
    isRunJsPending,
    resetCanonicalCache,
    clearSelectionHighlight,
    clearCssSelectionHighlight,
    handleIframeLoad,
    handleMessage,
  };
}

