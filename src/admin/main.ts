import './style.css';
import {
  initSettings,
  type PendingSettingsState,
  type SettingsApi,
  type SettingsData,
} from './settings';
import { runSetupWizard } from './setup-wizard';
import { mountToolbar, type ToolbarApi } from './toolbar';
import { buildEditorShell } from './editor-shell';
import {
  initCodeMirrorEditors,
  type CodeMirrorType,
  type CodeEditorInstance,
  type EditorModel,
} from './codemirror';
import { createPreviewController, type PreviewController } from './preview';
import {
  getEditableElementAttributes,
  getEditableElementText,
  getElementContext,
} from './element-text';
import {
  createTailwindCompiler,
  type TailwindCompiler,
} from './persistence';
import { resolveDefaultTemplateMode, resolveTemplateMode } from './logic/template-mode';
import { createDocumentTitleSync } from './logic/document-title';
import { buildMediaHtml } from './logic/media-html';
import { buildStatusUpdates } from './logic/status-updates';
import { createSaveExportController } from './controllers/save-export-controller';
import { createModalController } from './controllers/modal-controller';
import { createEditorUiController } from './controllers/editor-ui-controller';
import { createViewportController } from './controllers/viewport-controller';
import {
  createNotices,
  NOTICE_ERROR_DURATION_MS,
  NOTICE_IDS,
  NOTICE_SUCCESS_DURATION_MS,
} from './ui/notices';
import { debounce } from './utils/debounce';
import type { AppConfig } from './types/app-config';
import { resolveInitialState } from './bootstrap/resolve-initial-state';
import { normalizeJsMode, type JsMode } from './types/js-mode';
import type {
  EditorSnapshot,
  KayzArtExtensionApi,
  SelectedElementContext,
} from './extensions/settings-tab-registry';
import { __ } from '@wordpress/i18n';

// wp-api-fetch は admin 側でグローバル wp.apiFetch として使える
declare const wp: any;

declare global {
  interface Window {
    KAYZART: AppConfig;
    KAYZART_EXTENSION_API?: KayzArtExtensionApi;
  }
}

const COMPACT_EDITOR_BREAKPOINT = 900;
const HTML_WORD_WRAP_STORAGE_KEY = 'kayzart.wordWrap.html';
const LEGACY_HTML_WORD_WRAP_STORAGE_KEY = 'kayzart.html.wordWrap';
type HtmlWordWrapMode = 'off' | 'on';

const readHtmlWordWrapMode = (): HtmlWordWrapMode => {
  try {
    const saved = window.localStorage.getItem(HTML_WORD_WRAP_STORAGE_KEY);
    if (saved === 'on' || saved === 'off') {
      return saved;
    }
    return window.localStorage.getItem(LEGACY_HTML_WORD_WRAP_STORAGE_KEY) === 'on' ? 'on' : 'off';
  } catch {
    return 'off';
  }
};

const saveHtmlWordWrapMode = (mode: HtmlWordWrapMode) => {
  try {
    window.localStorage.setItem(HTML_WORD_WRAP_STORAGE_KEY, mode);
    window.localStorage.removeItem(LEGACY_HTML_WORD_WRAP_STORAGE_KEY);
  } catch {
    // Ignore storage errors and keep editing.
  }
};

const computeEditorBaseHash = (html: string, css: string, js: string) => {
  const source = `${html}\n\u0000${css}\n\u0000${js}`;
  let hash = 0x811c9dc5;
  for (let i = 0; i < source.length; i += 1) {
    hash ^= source.charCodeAt(i);
    hash = Math.imul(hash, 0x01000193);
    hash >>>= 0;
  }
  return hash.toString(16).padStart(8, '0');
};

async function main() {
  const cfg = window.KAYZART;
  const postId = cfg.post_id;
  const mount = document.getElementById('kayzart-app');
  if (!mount) return;
  const notices = createNotices({ wp });
  const { createSnackbar, mountNotices, removeNotice, syncNoticeOffset } = notices;
  mountNotices();

  const ui = buildEditorShell(mount);
  ui.resizer.setAttribute('role', 'separator');
  ui.resizer.setAttribute('aria-orientation', 'vertical');
  ui.editorResizer.setAttribute('role', 'separator');
  ui.editorResizer.setAttribute('aria-orientation', 'horizontal');

  let toolbarApi: ToolbarApi | null = null;
  let editorUiController: ReturnType<typeof createEditorUiController> | null = null;
  const viewportController = createViewportController({
    ui,
    compactDesktopViewportWidth: 1280,
    viewportPresetWidths: {
      mobile: 375,
      tablet: 768,
    },
    previewBadgeHideMs: 2200,
    previewBadgeTransitionMs: 320,
    minLeftWidth: 320,
    minRightWidth: 360,
    desktopMinPreviewWidth: 1024,
    minEditorPaneHeight: 160,
    getCompactEditorMode: () => editorUiController?.isCompactEditorMode() ?? false,
    onViewportModeChange: (mode) => toolbarApi?.update({ viewportMode: mode }),
    onEditorCollapsedChange: (collapsed) => toolbarApi?.update({ editorCollapsed: collapsed }),
  });
  let setupResult: Awaited<ReturnType<typeof runSetupWizard>> | undefined;

  // REST nonce middleware
  if (wp?.apiFetch?.createNonceMiddleware) {
    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(cfg.restNonce));
  }

  if (cfg.setupRequired) {
    if (!cfg.setupRestUrl || !wp?.apiFetch) {
      ui.app.textContent = __( 'Setup wizard unavailable.', 'kayzart-live-code-editor');
      return;
    }

    const setupHost = document.createElement('div');
    setupHost.className = 'kayzart-setupHost';
    document.body.append(setupHost);

    try {
      setupResult = await runSetupWizard({
        container: setupHost,
        postId,
        restUrl: cfg.setupRestUrl,
        importRestUrl: cfg.importRestUrl,
        apiFetch: wp?.apiFetch,
        backUrl: cfg.listUrl || cfg.backUrl,
        initialTailwindEnabled: Boolean(cfg.tailwindEnabled),
      });
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error('[KayzArt] Setup failed', error);
      ui.app.textContent = __( 'Setup failed.', 'kayzart-live-code-editor');
      return;
    } finally {
      setupHost.remove();
    }
  }

  const initialState = resolveInitialState(cfg, setupResult);
  let tailwindEnabled = initialState.tailwindEnabled;
  let htmlWordWrapMode: HtmlWordWrapMode = readHtmlWordWrapMode();

  let codemirror: CodeMirrorType;
  let htmlModel: EditorModel;
  let cssModel: EditorModel;
  let jsModel: EditorModel;
  let htmlEditor: CodeEditorInstance;
  let cssEditor: CodeEditorInstance;
  let jsEditor: CodeEditorInstance;
  let tailwindCss = initialState.importedGeneratedCss;
  let settingsOpen = false;
  let activeSettingsTab = 'settings';
  const canEditJs = Boolean(cfg.canEditJs);
  let jsEnabled = true;
  let jsMode: JsMode = normalizeJsMode(initialState.initialJsMode);
  let shadowDomEnabled = Boolean(initialState.settingsData?.shadowDomEnabled);
  let shortcodeEnabled = Boolean(initialState.settingsData?.shortcodeEnabled);
  let singlePageEnabled = initialState.settingsData?.singlePageEnabled ?? true;
  let liveHighlightEnabled = initialState.settingsData?.liveHighlightEnabled ?? true;
  let externalScripts = Array.isArray(initialState.settingsData?.externalScripts)
    ? [...initialState.settingsData.externalScripts]
    : [];
  let externalStyles = Array.isArray(initialState.settingsData?.externalStyles)
    ? [...initialState.settingsData.externalStyles]
    : [];
  let hasUnsavedChanges = false;
  let pendingSettingsUpdates: Record<string, unknown> = {};
  let hasUnsavedSettings = false;
  let hasSettingsValidationErrors = false;
  let viewPostUrl = initialState.settingsData?.viewUrl || '';
  let postStatus = initialState.settingsData?.status || 'draft';
  let postTitle = initialState.settingsData?.title || '';
  let postSlug = initialState.settingsData?.slug || '';
  let templateMode = resolveTemplateMode(initialState.settingsData?.templateMode);
  let defaultTemplateMode = resolveDefaultTemplateMode(initialState.settingsData?.defaultTemplateMode);
  const syncDocumentTitle = createDocumentTitleSync(document.title, cfg.adminTitleSeparators);
  syncDocumentTitle(postTitle);

  let preview: PreviewController | null = null;
  let settingsApi: SettingsApi | null = null;
  let modalController: ReturnType<typeof createModalController> | null = null;
  let tailwindCompiler: TailwindCompiler | null = null;
  let sendRenderDebounced: (() => void) | null = null;
  let compileTailwindDebounced: (() => void) | null = null;
  let selectedLcId: string | null = null;
  let extensionEditorLock = false;
  let suppressSelectionClear = 0;
  const selectionListeners = new Set<(lcId: string | null) => void>();
  const contentListeners = new Set<() => void>();

  const notifySelection = () => {
    selectionListeners.forEach((listener) => listener(selectedLcId));
  };

  const subscribeSelection = (listener: (lcId: string | null) => void) => {
    selectionListeners.add(listener);
    listener(selectedLcId);
    return () => selectionListeners.delete(listener);
  };

  const notifyContentChange = () => {
    contentListeners.forEach((listener) => listener());
  };

  const subscribeContentChange = (listener: () => void) => {
    contentListeners.add(listener);
    return () => contentListeners.delete(listener);
  };

  let saveExportController: ReturnType<typeof createSaveExportController> | null = null;

  const getUnsavedFlags = () => {
    if (!saveExportController) {
      return {
        html: false,
        css: false,
        js: false,
        settings: hasUnsavedSettings,
        hasAny: hasUnsavedSettings,
      };
    }
    return saveExportController.getUnsavedFlags();
  };

  const syncUnsavedUi = () => {
    saveExportController?.syncUnsavedUi();
  };

  const markSavedState = () => {
    saveExportController?.markSavedState();
  };

  const syncElementsTabState = () => {
    preview?.sendElementsTabState(settingsOpen && activeSettingsTab === 'elements');
  };

  const getResolvedTemplateMode = () => (templateMode === 'default' ? defaultTemplateMode : templateMode);
  const isThemeTemplateModeActive = () => getResolvedTemplateMode() === 'theme';

  const setSettingsOpen = (open: boolean) => {
    settingsOpen = open;
    ui.app.classList.toggle('is-settings-open', open);
    toolbarApi?.update({ settingsOpen: open });
    syncElementsTabState();
    viewportController.applyViewportLayout();
  };

  const applySavedSettings = (nextSettings: SettingsData, refreshPreview: boolean) => {
    const currentResolved = getResolvedTemplateMode();
    if (typeof nextSettings.viewUrl === 'string') {
      viewPostUrl = nextSettings.viewUrl;
    }
    postStatus = nextSettings.status || postStatus;
    postTitle = nextSettings.title || postTitle;
    postSlug = nextSettings.slug || postSlug;
    shadowDomEnabled = Boolean(nextSettings.shadowDomEnabled);
    shortcodeEnabled = Boolean(nextSettings.shortcodeEnabled);
    singlePageEnabled = nextSettings.singlePageEnabled ?? singlePageEnabled;
    liveHighlightEnabled = nextSettings.liveHighlightEnabled ?? liveHighlightEnabled;
    externalScripts = Array.isArray(nextSettings.externalScripts)
      ? [...nextSettings.externalScripts]
      : [];
    externalStyles = Array.isArray(nextSettings.externalStyles)
      ? [...nextSettings.externalStyles]
      : [];
    const nextTemplateMode = resolveTemplateMode(nextSettings.templateMode);
    const nextDefaultTemplateMode =
      typeof nextSettings.defaultTemplateMode === 'string'
        ? resolveDefaultTemplateMode(nextSettings.defaultTemplateMode)
        : defaultTemplateMode;
    if (typeof nextSettings.defaultTemplateMode === 'string') {
      defaultTemplateMode = nextDefaultTemplateMode;
    }
    templateMode = nextTemplateMode;
    setShadowDomEnabled(shadowDomEnabled);
    setLiveHighlightEnabled(liveHighlightEnabled);
    toolbarApi?.update({ viewPostUrl, postStatus, postTitle, postSlug });
    syncDocumentTitle(postTitle);

    const nextResolved =
      nextTemplateMode === 'default' ? nextDefaultTemplateMode : nextTemplateMode;
    if ((refreshPreview || nextResolved !== currentResolved) && basePreviewUrl) {
      ui.iframe.src = buildPreviewRefreshUrl(getPreviewUrl());
    }
  };

  saveExportController = createSaveExportController({
    apiFetch: wp.apiFetch,
    restUrl: cfg.restUrl,
    restCompileUrl: cfg.restCompileUrl,
    postId,
    canEditJs,
    getHtmlModel: () => htmlModel,
    getCssModel: () => cssModel,
    getJsModel: () => jsModel,
    getJsMode: () => jsMode,
    getTailwindEnabled: () => tailwindEnabled,
    getTailwindCss: () => tailwindCss,
    getExternalScripts: () => externalScripts,
    getExternalStyles: () => externalStyles,
    getShadowDomEnabled: () => shadowDomEnabled,
    getShortcodeEnabled: () => shortcodeEnabled,
    getSinglePageEnabled: () => singlePageEnabled,
    getLiveHighlightEnabled: () => liveHighlightEnabled,
    getPendingSettingsState: () => ({
      pendingSettingsUpdates,
      hasUnsavedSettings,
      hasSettingsValidationErrors,
    }),
    clearPendingSettingsState: () => {
      pendingSettingsUpdates = {};
      hasUnsavedSettings = false;
      hasSettingsValidationErrors = false;
    },
    applySavedSettings,
    applySettingsToSidebar: (settings) => settingsApi?.applySettings(settings),
    createSnackbar,
    noticeIds: {
      save: NOTICE_IDS.save,
      export: NOTICE_IDS.export,
    },
    noticeSuccessMs: NOTICE_SUCCESS_DURATION_MS,
    noticeErrorMs: NOTICE_ERROR_DURATION_MS,
    uiDirtyTargets: {
      htmlTitle: ui.htmlTitle,
      cssTab: ui.cssTab,
      jsTab: ui.jsTab,
      compactHtmlTab: ui.compactHtmlTab,
      compactCssTab: ui.compactCssTab,
      compactJsTab: ui.compactJsTab,
    },
    onUnsavedChange: (nextHasUnsavedChanges) => {
      hasUnsavedChanges = nextHasUnsavedChanges;
      toolbarApi?.update({ hasUnsavedChanges });
    },
    onSaveSuccess: () => {
      preview?.resetCanonicalCache();
      if (!preview?.isRunJsPending()) {
        preview?.sendRender();
      }
    },
  });

  async function handleExport() {
    await saveExportController?.handleExport();
  }

  async function handleSave(): Promise<{ ok: boolean; error?: string }> {
    if (!saveExportController) {
      return { ok: false, error: __('Save failed.', 'kayzart-live-code-editor') };
    }
    return await saveExportController.handleSave();
  }

  const runSaveShortcut = async () => {
    await handleSave();
  };

  const registerSaveShortcut = (
    editorInstance: CodeEditorInstance
  ) => {
    editorInstance.addAction({
      id: 'kayzart.save',
      label: __( 'Save', 'kayzart-live-code-editor'),
      keybindings: [codemirror.KeyMod.CtrlCmd | codemirror.KeyCode.KeyS],
      run: runSaveShortcut,
    });
  };

  const registerHtmlWordWrapAction = (
    editorInstance: CodeEditorInstance
  ) => {
    editorInstance.addAction({
      id: 'kayzart.toggleHtmlWordWrap',
      label: __( 'Toggle HTML word wrap', 'kayzart-live-code-editor'),
      keybindings: [codemirror.KeyMod.Alt | codemirror.KeyCode.KeyZ],
      run: () => {
        toggleHtmlWordWrapMode(editorInstance);
      },
    });
  };

  const getHtmlWordWrapToggleLabel = (mode: HtmlWordWrapMode) =>
    mode === 'on'
      ? __( 'Wrap: On', 'kayzart-live-code-editor')
      : __( 'Wrap: Off', 'kayzart-live-code-editor');

  const syncHtmlWordWrapToggleButton = () => {
    const label = getHtmlWordWrapToggleLabel(htmlWordWrapMode);
    ui.htmlWordWrapButton.textContent = label;
    ui.htmlWordWrapButton.setAttribute('title', label);
    ui.htmlWordWrapButton.setAttribute('aria-label', label);
    ui.htmlWordWrapButton.setAttribute('aria-pressed', htmlWordWrapMode === 'on' ? 'true' : 'false');
    ui.htmlWordWrapButton.classList.toggle('is-active', htmlWordWrapMode === 'on');
  };

  const toggleHtmlWordWrapMode = (editorInstance?: CodeEditorInstance) => {
    htmlWordWrapMode = htmlWordWrapMode === 'on' ? 'off' : 'on';
    const targetEditor = editorInstance || htmlEditor;
    if (targetEditor) {
      targetEditor.updateOptions({ wordWrap: htmlWordWrapMode });
    }
    saveHtmlWordWrapMode(htmlWordWrapMode);
    syncHtmlWordWrapToggleButton();
  };

  const basePreviewUrl = cfg.iframePreviewUrl || cfg.previewUrl;
  const buildPreviewTemplateModeUrl = (url: string, templateModeValue: string) => {
    if (!url) {
      return url;
    }
    try {
      const previewUrl = new URL(url, window.location.origin);
      if (templateModeValue && templateModeValue !== 'default') {
        previewUrl.searchParams.set('kayzart_template_mode', templateModeValue);
      } else {
        previewUrl.searchParams.delete('kayzart_template_mode');
      }
      return previewUrl.toString();
    } catch {
      return url;
    }
  };
  const getPreviewUrl = () => buildPreviewTemplateModeUrl(basePreviewUrl, templateMode);
  const buildPreviewRefreshUrl = (url: string) => {
    if (!url) {
      return url;
    }
    try {
      const refreshUrl = new URL(url, window.location.origin);
      refreshUrl.searchParams.set('kayzart_refresh', Date.now().toString());
      return refreshUrl.toString();
    } catch {
      const hasQuery = url.includes('?');
      const hashIndex = url.indexOf('#');
      const suffix = `${hasQuery ? '&' : '?'}kayzart_refresh=${Date.now()}`;
      if (hashIndex === -1) {
        return url + suffix;
      }
      return url.slice(0, hashIndex) + suffix + url.slice(hashIndex);
    }
  };
  const targetOrigin = new URL(getPreviewUrl()).origin;
  let pendingIframeLoad = false;

  const handleIframeLoadEvent = () => {
    if (!preview) {
      pendingIframeLoad = true;
      return;
    }
    preview.handleIframeLoad();
  };

  const handlePreviewMessage = (event: MessageEvent) => {
    preview?.handleMessage(event);
  };

  // Register listeners before src assignment to avoid missing early handshake messages.
  ui.iframe.addEventListener('load', handleIframeLoadEvent);
  window.addEventListener('message', handlePreviewMessage);

  modalController = createModalController({
    apiFetch: wp.apiFetch,
    settingsRestUrl: cfg.settingsRestUrl,
    postId,
    getShadowDomEnabled: () => shadowDomEnabled,
    getJsMode: () => jsMode,
    getTailwindEnabled: () => tailwindEnabled,
    isThemeTemplateModeActive,
    getDefaultTemplateMode: () => defaultTemplateMode,
    setTemplateModes: (nextTemplateMode, nextDefaultTemplateMode) => {
      templateMode = nextTemplateMode;
      defaultTemplateMode = nextDefaultTemplateMode;
    },
    applySettingsToSidebar: (settings) => settingsApi?.applySettings(settings),
    refreshPreview: () => {
      if (basePreviewUrl) {
        ui.iframe.src = buildPreviewRefreshUrl(getPreviewUrl());
      }
    },
    createSnackbar,
    noticeIds: {
      templateFallback: NOTICE_IDS.templateFallback,
    },
    noticeErrorMs: NOTICE_ERROR_DURATION_MS,
  });

  toolbarApi = mountToolbar(
    ui.toolbar,
    {
      backUrl: cfg.backUrl || '/wp-admin/',
      listUrl: cfg.listUrl || '',
      canUndo: false,
      canRedo: false,
      editorCollapsed: viewportController.isEditorCollapsed(),
      compactEditorMode: false,
      settingsOpen,
      tailwindEnabled,
      viewportMode: viewportController.getViewportMode(),
      hasUnsavedChanges: false,
      viewPostUrl,
      postStatus,
      postTitle,
      postSlug,
    },
    {
      onUndo: () => editorUiController?.getActiveEditor()?.trigger('toolbar', 'undo', null),
      onRedo: () => editorUiController?.getActiveEditor()?.trigger('toolbar', 'redo', null),
      onToggleEditor: () =>
        viewportController.setEditorCollapsed(!viewportController.isEditorCollapsed()),
      onSave: handleSave,
      onExport: handleExport,
      onToggleSettings: () => setSettingsOpen(!settingsOpen),
      onViewportChange: (mode) => viewportController.setViewportMode(mode),
      onUpdatePostIdentity: async ({ title, slug }) => {
        if (!cfg.settingsRestUrl || !wp?.apiFetch) {
          return { ok: false, error: __( 'Settings unavailable.', 'kayzart-live-code-editor') };
        }
        try {
          const response = await wp.apiFetch({
            url: cfg.settingsRestUrl,
            method: 'POST',
            data: {
              post_id: postId,
              updates: {
                title,
                slug,
              },
            },
          });
          if (!response?.ok) {
            return { ok: false, error: response?.error || __( 'Update failed.', 'kayzart-live-code-editor') };
          }
          const nextSettings = response.settings as SettingsData | undefined;
          const nextTitle =
            nextSettings && typeof nextSettings.title === 'string'
              ? nextSettings.title
              : title;
          const nextSlug =
            nextSettings && typeof nextSettings.slug === 'string'
              ? nextSettings.slug
              : slug;
          postTitle = nextTitle;
          postSlug = nextSlug;
          toolbarApi?.update({ postTitle, postSlug });
          settingsApi?.applySettings({ title: postTitle, slug: postSlug });
          syncDocumentTitle(postTitle);
          window.dispatchEvent(
            new CustomEvent('kayzart-title-updated', { detail: { title: postTitle, slug: postSlug } })
          );
          if (basePreviewUrl) {
            ui.iframe.src = buildPreviewRefreshUrl(getPreviewUrl());
          }
          return { ok: true };
        } catch (error: any) {
          return {
            ok: false,
            error: error?.message || __( 'Update failed.', 'kayzart-live-code-editor'),
          };
        }
      },
      onUpdateStatus: async (nextStatus) => {
        if (!cfg.settingsRestUrl || !wp?.apiFetch) {
          return { ok: false, error: __( 'Settings unavailable.', 'kayzart-live-code-editor') };
        }
        const updates = buildStatusUpdates(nextStatus);
        try {
          const response = await wp.apiFetch({
            url: cfg.settingsRestUrl,
            method: 'POST',
            data: {
              post_id: postId,
              updates,
            },
          });
          if (!response?.ok) {
            return { ok: false, error: response?.error || __( 'Update failed.', 'kayzart-live-code-editor') };
          }
          const nextSettings = response.settings as SettingsData | undefined;
          postStatus =
            nextSettings && typeof nextSettings.status === 'string'
              ? nextSettings.status
              : nextStatus;
          toolbarApi?.update({ postStatus });
          return { ok: true };
        } catch (error: any) {
          return {
            ok: false,
            error: error?.message || __( 'Update failed.', 'kayzart-live-code-editor'),
          };
        }
      },
    }
  );
  syncNoticeOffset();
  window.setTimeout(syncNoticeOffset, 0);
  syncHtmlWordWrapToggleButton();
  ui.htmlWordWrapButton.addEventListener('click', () => {
    toggleHtmlWordWrapMode();
  });
  createSnackbar('info', __( 'Loading editor...', 'kayzart-live-code-editor'), NOTICE_IDS.editor);

  // iframe
  ui.iframe.src = getPreviewUrl();

  // CodeMirror
  const codeMirrorSetup = await initCodeMirrorEditors({
        initialHtml: initialState.initialHtml,
    initialCss: initialState.initialCss,
    initialJs: initialState.initialJs,
    htmlWordWrap: htmlWordWrapMode,
    tailwindEnabled,
    useTailwindDefault: !setupResult?.imported,
    canEditJs,
    htmlContainer: ui.htmlEditorDiv,
    cssContainer: ui.cssEditorDiv,
    jsContainer: ui.jsEditorDiv,
  });

  ({ codemirror, htmlModel, cssModel, jsModel, htmlEditor, cssEditor, jsEditor } = codeMirrorSetup);

  registerSaveShortcut(htmlEditor);
  registerSaveShortcut(cssEditor);
  registerSaveShortcut(jsEditor);
  registerHtmlWordWrapAction(htmlEditor);

  removeNotice(NOTICE_IDS.editor);
  markSavedState();

  const handleBeforeUnload = (event: BeforeUnloadEvent) => {
    if (!hasUnsavedChanges) {
      return;
    }
    event.preventDefault();
    event.returnValue = __( 'You may have unsaved changes.', 'kayzart-live-code-editor');
  };

  window.addEventListener('beforeunload', handleBeforeUnload);

  const applyHtmlEdit = (startOffset: number, endOffset: number, nextText: string) => {
    suppressSelectionClear += 1;
    const start = htmlModel.getPositionAt(startOffset);
    const end = htmlModel.getPositionAt(endOffset);
    htmlModel.pushEditOperations(
      [],
      [
        {
          range: new codemirror.Range(start.lineNumber, start.column, end.lineNumber, end.column),
          text: nextText,
        },
      ],
      () => null
    );
    suppressSelectionClear = Math.max(0, suppressSelectionClear - 1);
  };

  const insertHtmlAtSelection = (text: string) => {
    const selection = htmlEditor.getSelection();
    const cursor = htmlEditor.getPosition();
    const range =
      selection ||
      new codemirror.Range(
        cursor?.lineNumber || 1,
        cursor?.column || 1,
        cursor?.lineNumber || 1,
        cursor?.column || 1
      );
    htmlEditor.pushUndoStop();
    htmlModel.pushEditOperations(
      [],
      [{ range, text }],
      (inverseOperations) => {
        const inverseRange = inverseOperations[0]?.range;
        if (!inverseRange) {
          return null;
        }
        const end = inverseRange.getEndPosition();
        return [new codemirror.Selection(end.lineNumber, end.column, end.lineNumber, end.column)];
      }
    );
    htmlEditor.pushUndoStop();
  };

  const openMediaModal = () => {
    if (typeof wp?.media !== 'function') {
      createSnackbar(
        'error',
        __( 'Media library is unavailable.', 'kayzart-live-code-editor'),
        NOTICE_IDS.media,
        NOTICE_ERROR_DURATION_MS
      );
      return;
    }

    const frame = wp.media({
      frame: 'post',
      state: 'insert',
      title: __( 'Select media to insert into HTML.', 'kayzart-live-code-editor'),
      button: {
        text: __( 'Insert into HTML', 'kayzart-live-code-editor'),
      },
      multiple: false,
    });

    frame.on('insert', (selectionArg: any) => {
      const state = frame.state?.();
      const selection = selectionArg || state?.get?.('selection');
      const selectedModel = selection?.first?.();
      const attachment = selectedModel?.toJSON?.();
      if (!attachment || typeof attachment !== 'object') {
        return;
      }
      const display =
        typeof state?.display === 'function'
          ? state.display(selectedModel)?.toJSON?.()
          : undefined;
      const html = buildMediaHtml(
        attachment as Record<string, unknown>,
        display && typeof display === 'object' ? (display as Record<string, unknown>) : undefined,
        wp?.media?.string?.props
      );
      if (!html) {
        createSnackbar(
          'warning',
          __( 'The selected media has no URL and was not inserted.', 'kayzart-live-code-editor'),
          NOTICE_IDS.media,
          NOTICE_ERROR_DURATION_MS
        );
        return;
      }
      if (editorUiController) {
        editorUiController.focusHtmlEditor();
      } else {
        htmlEditor.focus();
      }
      insertHtmlAtSelection(html);
    });

    frame.open();
  };

  const isValidAttributeName = (name: string) => /^[A-Za-z0-9:_.-]+$/.test(name);

  const escapeAttributeValue = (value: string) =>
    value
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');

  const normalizeAttributes = (attrs: { name: string; value: string }[]) => {
    const seen = new Set<string>();
    const normalized: { name: string; value: string }[] = [];
    for (let i = attrs.length - 1; i >= 0; i -= 1) {
      const name = attrs[i].name.trim();
      if (!name || name === 'data-kayzart-id' || !isValidAttributeName(name) || seen.has(name)) {
        continue;
      }
      seen.add(name);
      normalized.push({ name, value: attrs[i].value });
    }
    return normalized.reverse();
  };

  const elementsApi = {
    subscribeSelection,
    subscribeContentChange,
    getElementText: (lcId: string) => {
      const info = getEditableElementText(htmlModel.getValue(), lcId);
      return info ? info.text : null;
    },
    updateElementText: (lcId: string, text: string) => {
      const html = htmlModel.getValue();
      const info = getEditableElementText(html, lcId);
      if (!info) {
        return false;
      }
      if (info.text === text) {
        return true;
      }
      applyHtmlEdit(info.startOffset, info.endOffset, text);
      return true;
    },
    getElementAttributes: (lcId: string) => {
      const info = getEditableElementAttributes(htmlModel.getValue(), lcId);
      return info ? info.attributes : null;
    },
    updateElementAttributes: (lcId: string, attributes: { name: string; value: string }[]) => {
      const html = htmlModel.getValue();
      const info = getEditableElementAttributes(html, lcId);
      if (!info) {
        return false;
      }
      const normalized = normalizeAttributes(attributes);
      const attrText = normalized.length
        ? ` ${normalized
            .map((attr) => `${attr.name}="${escapeAttributeValue(attr.value)}"`)
            .join(' ')}`
        : '';
      const closing = info.selfClosing ? ' />' : '>';
      const nextStartTag = `<${info.tagName}${attrText}${closing}`;
      const currentStartTag = html.slice(info.startOffset, info.endOffset);
      if (currentStartTag === nextStartTag) {
        return true;
      }
      applyHtmlEdit(info.startOffset, info.endOffset, nextStartTag);
      return true;
    },
  };

  const updateUndoRedoState = () => {
    const model = editorUiController?.getActiveEditor()?.getModel();
    const canUndo = Boolean(model && model.canUndo());
    const canRedo = Boolean(model && model.canRedo());
    toolbarApi?.update({ canUndo, canRedo });
  };

  const openShadowHintModal = () => modalController?.openShadowHintModal();
  const closeShadowHintModal = () => modalController?.closeShadowHintModal();
  const openTailwindHintModal = () => modalController?.openTailwindHintModal();
  const closeTailwindHintModal = () => modalController?.closeTailwindHintModal();
  const handleMissingMarkers = () => modalController?.handleMissingMarkers();

  editorUiController = createEditorUiController({
    ui,
    canEditJs,
    htmlEditor,
    cssEditor,
    jsEditor,
    compactEditorBreakpoint: COMPACT_EDITOR_BREAKPOINT,
    getViewportWidth: () => Math.round(window.visualViewport?.width ?? window.innerWidth),
    getJsEnabled: () => jsEnabled,
    getShadowDomEnabled: () => shadowDomEnabled,
    getTailwindEnabled: () => tailwindEnabled,
    onActiveEditorChange: () => {
      updateUndoRedoState();
    },
    onCompactEditorModeChange: (isCompact) => {
      toolbarApi?.update({ compactEditorMode: isCompact });
      viewportController.applyViewportLayout();
    },
    onOpenMedia: openMediaModal,
    onRunJs: () => preview?.requestRunJs(),
    onOpenShadowHint: openShadowHintModal,
    onOpenTailwindHint: openTailwindHintModal,
  });
  editorUiController.initialize();

  const focusHtmlEditor = () => {
    editorUiController?.focusHtmlEditor();
  };

  const getPreviewCss = () => (tailwindEnabled ? tailwindCss : cssModel.getValue());

  preview = createPreviewController({
    iframe: ui.iframe,
    postId,
    targetOrigin,
    htmlModel,
    cssModel,
    jsModel,
    htmlEditor,
    cssEditor,
    focusHtmlEditor,
    getPreviewCss,
    getShadowDomEnabled: () => shadowDomEnabled,
    getLiveHighlightEnabled: () => liveHighlightEnabled,
    getJsEnabled: () => jsEnabled,
    getJsMode: () => jsMode,
    getExternalScripts: () => externalScripts,
    getExternalStyles: () => externalStyles,
    isTailwindEnabled: () => tailwindEnabled,
    onSelect: (lcId) => {
      selectedLcId = lcId;
      notifySelection();
    },
    onOpenElementsTab: () => {
      if (!settingsOpen) {
        setSettingsOpen(true);
      }
      if (activeSettingsTab !== 'elements') {
        settingsApi?.openTab('elements');
      }
    },
    onMissingMarkers: () => {
      handleMissingMarkers();
    },
  });
  if (pendingIframeLoad) {
    pendingIframeLoad = false;
    preview.handleIframeLoad();
  }
  syncElementsTabState();

  tailwindCompiler = createTailwindCompiler({
    apiFetch: wp.apiFetch,
    restCompileUrl: cfg.restCompileUrl,
    postId,
    getHtml: () => htmlModel.getValue(),
    getCss: () => cssModel.getValue(),
    isTailwindEnabled: () => tailwindEnabled,
    onCssCompiled: (css) => {
      tailwindCss = css;
      preview?.sendCssUpdate(css);
    },
    onStatus: (text) => createSnackbar('error', text, NOTICE_IDS.tailwind, NOTICE_ERROR_DURATION_MS),
    onStatusClear: () => removeNotice(NOTICE_IDS.tailwind),
  });

  sendRenderDebounced = debounce(() => preview?.sendRender(), 120);
  compileTailwindDebounced = debounce(() => tailwindCompiler?.compile(), 300);

  const setJsEnabled = (enabled: boolean) => {
    jsEnabled = enabled;
    editorUiController?.syncJsState();
    if (!preview) {
      return;
    }
    if (!enabled) {
      preview.sendExternalScripts([]);
      preview.requestDisableJs();
      return;
    }
    preview.sendExternalScripts(externalScripts);
    preview.queueInitialJsRun();
  };

  const syncJsModeSelectors = () => {
    ui.jsModeSelect.value = jsMode;
    ui.compactJsModeSelect.value = jsMode;
  };

  const setJsMode = (nextMode: JsMode) => {
    const normalized = normalizeJsMode(nextMode);
    if (normalized === jsMode) {
      syncJsModeSelectors();
      return;
    }
    jsMode = normalized;
    syncJsModeSelectors();
    syncUnsavedUi();
    if (!jsEnabled) {
      return;
    }
    preview?.requestRunJs();
  };

  syncJsModeSelectors();

  ui.jsModeSelect.addEventListener('change', (event) => {
    const target = event.target as HTMLSelectElement;
    setJsMode(normalizeJsMode(target.value));
  });
  ui.compactJsModeSelect.addEventListener('change', (event) => {
    const target = event.target as HTMLSelectElement;
    setJsMode(normalizeJsMode(target.value));
  });

  const setShadowDomEnabled = (enabled: boolean) => {
    shadowDomEnabled = enabled;
    editorUiController?.syncShadowDomState();
    if (!shadowDomEnabled) {
      closeShadowHintModal();
    }
    preview?.sendExternalScripts(jsEnabled ? externalScripts : []);
    preview?.sendExternalStyles(externalStyles);
    if (!jsEnabled) {
      preview?.sendRender();
      preview?.requestDisableJs();
      return;
    }
    preview?.requestRunJs();
  };

  const setLiveHighlightEnabled = (enabled: boolean) => {
    liveHighlightEnabled = enabled;
    preview?.sendLiveHighlightUpdate(enabled);
  };

  const setTailwindEnabled = (enabled: boolean) => {
    tailwindEnabled = enabled;
    ui.app.classList.toggle('is-tailwind', enabled);
    editorUiController?.syncTailwindState();
    toolbarApi?.update({ tailwindEnabled: enabled });
    if (!enabled) {
      closeTailwindHintModal();
    }
    if (enabled) {
      preview?.sendRender();
      tailwindCompiler?.compile();
    } else {
      const editorSplitState = viewportController.getEditorSplitState();
      if (editorSplitState.active && editorSplitState.lastHtmlHeight > 0) {
        viewportController.setEditorSplitHeight(editorSplitState.lastHtmlHeight);
      }
      preview?.sendRender();
    }
  };

  const setEditorLock = (locked: boolean) => {
    extensionEditorLock = locked;
    htmlEditor.setLocked(locked);
    cssEditor.setLocked(locked);
    jsEditor.setLocked(locked);
  };

  const getEditorSnapshot = (): EditorSnapshot => {
    const html = htmlModel.getValue();
    const css = cssModel.getValue();
    const js = jsModel.getValue();
    return {
      html,
      css,
      js,
      jsMode,
      baseHash: computeEditorBaseHash(html, css, js),
    };
  };

  const replaceModelContent = (model: EditorModel, nextText: string) => {
    const current = model.getValue();
    if (current === nextText) {
      return;
    }
    const end = model.getPositionAt(current.length);
    model.pushEditOperations(
      [],
      [
        {
          range: new codemirror.Range(1, 1, end.lineNumber, end.column),
          text: nextText,
        },
      ],
      () => null
    );
  };

  const replaceEditorSnapshot = (snapshot: EditorSnapshot) => {
    if (!snapshot || typeof snapshot !== 'object') {
      return false;
    }
    replaceModelContent(htmlModel, snapshot.html ?? '');
    replaceModelContent(cssModel, snapshot.css ?? '');
    replaceModelContent(jsModel, snapshot.js ?? '');
    setJsMode(snapshot.jsMode ?? 'classic');
    preview?.resetCanonicalCache();
    preview?.sendRender();
    if (jsEnabled) {
      preview?.requestRunJs();
    } else {
      preview?.requestDisableJs();
    }
    return true;
  };

  const getSelectedContext = (): SelectedElementContext | null => {
    if (!selectedLcId) {
      return null;
    }
    const context = getElementContext(htmlModel.getValue(), selectedLcId);
    if (!context) {
      return null;
    }
    return {
      lcId: context.lcId,
      tagName: context.tagName,
      attributes: context.attributes.map((attr) => ({
        name: attr.name,
        value: attr.value,
      })),
      text: context.text,
      outerHTML: context.outerHTML,
      sourceRange: context.sourceRange
        ? {
            startOffset: context.sourceRange.startOffset,
            endOffset: context.sourceRange.endOffset,
          }
        : undefined,
    };
  };

  const openSettingsTab = (tabId: string) => {
    if (!settingsOpen) {
      setSettingsOpen(true);
    }
    settingsApi?.openTab(tabId);
  };

  const publishExtensionApi = () => {
    const registerSettingsTab = window.KAYZART_EXTENSION_API?.registerSettingsTab;
    if (typeof registerSettingsTab !== 'function') {
      return;
    }
    window.KAYZART_EXTENSION_API = {
      ...window.KAYZART_EXTENSION_API,
      registerSettingsTab,
      openSettingsTab,
      getEditorSnapshot,
      replaceEditorSnapshot,
      getSelectedContext,
      setEditorLock,
      isEditorLocked: () => extensionEditorLock,
    };
  };

  settingsApi = initSettings({
    container: ui.settingsBody,
    header: ui.settingsHeader,
    data: initialState.settingsData,
    postId,
    onTemplateModeChange: (nextTemplateMode) => {
      const currentResolved = getResolvedTemplateMode();
      templateMode = resolveTemplateMode(nextTemplateMode);
      const nextResolved = getResolvedTemplateMode();
      if (nextResolved !== currentResolved && basePreviewUrl) {
        ui.iframe.src = buildPreviewRefreshUrl(getPreviewUrl());
      }
    },
    onShadowDomToggle: setShadowDomEnabled,
    onShortcodeToggle: (enabled) => {
      shortcodeEnabled = enabled;
    },
    onSinglePageToggle: (enabled) => {
      singlePageEnabled = enabled;
    },
    onLiveHighlightToggle: setLiveHighlightEnabled,
    onExternalScriptsChange: (scripts) => {
      externalScripts = scripts;
      preview?.sendExternalScripts(jsEnabled ? externalScripts : []);
    },
    onExternalStylesChange: (styles) => {
      externalStyles = styles;
      preview?.sendExternalStyles(externalStyles);
    },
    onTabChange: (tab) => {
      activeSettingsTab = tab;
      syncElementsTabState();
    },
    onPendingUpdatesChange: (nextState: PendingSettingsState) => {
      pendingSettingsUpdates = { ...nextState.updates };
      hasUnsavedSettings = nextState.hasUnsavedSettings;
      hasSettingsValidationErrors = nextState.hasValidationErrors;
      syncUnsavedUi();
    },
    onClosePanel: () => setSettingsOpen(false),
    elementsApi,
  });
  publishExtensionApi();

  const handleViewportResize = debounce(() => {
    editorUiController?.updateCompactEditorMode();
    viewportController.applyViewportLayout();
    syncNoticeOffset();
  }, 100);
  window.addEventListener('resize', handleViewportResize);
  window.visualViewport?.addEventListener('resize', handleViewportResize);

  setTailwindEnabled(tailwindEnabled);
  setJsEnabled(jsEnabled);
  preview?.flushPendingJsAction();
  viewportController.applyViewportLayout(true);

  htmlModel.onDidChangeContent(() => {
    preview?.resetCanonicalCache();
    preview?.clearSelectionHighlight();
    sendRenderDebounced?.();
    if (tailwindEnabled) {
      compileTailwindDebounced?.();
    }
    updateUndoRedoState();
    if (suppressSelectionClear === 0) {
      selectedLcId = null;
      notifySelection();
    }
    notifyContentChange();
    syncUnsavedUi();
  });
  cssModel.onDidChangeContent(() => {
    if (!tailwindEnabled) {
      sendRenderDebounced?.();
    }
    if (tailwindEnabled) {
      compileTailwindDebounced?.();
    }
    preview?.clearSelectionHighlight();
    preview?.clearCssSelectionHighlight();
    updateUndoRedoState();
    syncUnsavedUi();
  });

  jsModel.onDidChangeContent(() => {
    updateUndoRedoState();
    syncUnsavedUi();
  });

}

main().catch((e) => {
  // eslint-disable-next-line no-console
  console.error(e);
});






