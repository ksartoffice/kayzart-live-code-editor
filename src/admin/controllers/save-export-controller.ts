import { __, sprintf } from '@wordpress/i18n';
import { exportKayzArt, saveKayzArt } from '../persistence';
import type { SettingsData } from '../settings';
import type { ApiFetch } from '../types/api-fetch';
import type { JsMode } from '../types/js-mode';
import type { EditorModel } from '../codemirror';

type SnackbarStatus = 'success' | 'error' | 'info' | 'warning';

type UnsavedFlags = {
  html: boolean;
  css: boolean;
  js: boolean;
  settings: boolean;
  hasAny: boolean;
};

type SaveExportControllerDeps = {
  apiFetch: ApiFetch;
  restUrl: string;
  restCompileUrl: string;
  postId: number;
  canEditJs: boolean;
  getHtmlModel: () => EditorModel | undefined;
  getCssModel: () => EditorModel | undefined;
  getJsModel: () => EditorModel | undefined;
  getJsMode: () => JsMode;
  getTailwindEnabled: () => boolean;
  getTailwindCss: () => string;
  getExternalScripts: () => string[];
  getExternalStyles: () => string[];
  getShadowDomEnabled: () => boolean;
  getShortcodeEnabled: () => boolean;
  getSinglePageEnabled: () => boolean;
  getLiveHighlightEnabled: () => boolean;
  getPendingSettingsState: () => {
    pendingSettingsUpdates: Record<string, unknown>;
    hasUnsavedSettings: boolean;
    hasSettingsValidationErrors: boolean;
  };
  clearPendingSettingsState: () => void;
  applySavedSettings: (settings: SettingsData, refreshPreview: boolean) => void;
  applySettingsToSidebar: (settings: Partial<SettingsData>) => void;
  createSnackbar: (
    status: SnackbarStatus,
    message: string,
    id?: string,
    autoDismissMs?: number
  ) => void;
  noticeIds: {
    save: string;
    export: string;
  };
  noticeSuccessMs: number;
  noticeErrorMs: number;
  uiDirtyTargets: {
    htmlTitle: HTMLElement;
    cssTab: HTMLElement;
    jsTab: HTMLElement;
    compactHtmlTab: HTMLElement;
    compactCssTab: HTMLElement;
    compactJsTab: HTMLElement;
  };
  onUnsavedChange: (hasUnsavedChanges: boolean) => void;
  onSaveSuccess?: () => void;
};

export function createSaveExportController(deps: SaveExportControllerDeps) {
  let saveInFlight: Promise<{ ok: boolean; error?: string }> | null = null;
  let hasUnsavedChanges = false;
  let lastSaved: { html: string; css: string; js: string; jsMode: JsMode } = {
    html: '',
    css: '',
    js: '',
    jsMode: deps.getJsMode(),
  };

  const getUnsavedFlags = (): UnsavedFlags => {
    const htmlModel = deps.getHtmlModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    const { hasUnsavedSettings } = deps.getPendingSettingsState();
    if (!htmlModel || !cssModel || !jsModel) {
      return {
        html: false,
        css: false,
        js: false,
        settings: hasUnsavedSettings,
        hasAny: hasUnsavedSettings,
      };
    }
    const htmlDirty = htmlModel.getValue() !== lastSaved.html;
    const cssDirty = cssModel.getValue() !== lastSaved.css;
    const jsDirty = jsModel.getValue() !== lastSaved.js;
    const jsModeDirty = deps.getJsMode() !== lastSaved.jsMode;
    return {
      html: htmlDirty,
      css: cssDirty,
      js: jsDirty || jsModeDirty,
      settings: hasUnsavedSettings,
      hasAny: htmlDirty || cssDirty || jsDirty || jsModeDirty || hasUnsavedSettings,
    };
  };

  const syncUnsavedUi = () => {
    const { html, css, js, hasAny } = getUnsavedFlags();
    deps.uiDirtyTargets.htmlTitle.classList.toggle('has-unsaved', html);
    deps.uiDirtyTargets.cssTab.classList.toggle('has-unsaved', css);
    deps.uiDirtyTargets.jsTab.classList.toggle('has-unsaved', js);
    deps.uiDirtyTargets.compactHtmlTab.classList.toggle('has-unsaved', html);
    deps.uiDirtyTargets.compactCssTab.classList.toggle('has-unsaved', css);
    deps.uiDirtyTargets.compactJsTab.classList.toggle('has-unsaved', js);
    if (hasAny !== hasUnsavedChanges) {
      hasUnsavedChanges = hasAny;
      deps.onUnsavedChange(hasUnsavedChanges);
    }
  };

  const markSavedState = () => {
    const htmlModel = deps.getHtmlModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    if (!htmlModel || !cssModel || !jsModel) {
      return;
    }
    lastSaved = {
      html: htmlModel.getValue(),
      css: cssModel.getValue(),
      js: jsModel.getValue(),
      jsMode: deps.getJsMode(),
    };
    syncUnsavedUi();
  };

  const handleExport = async () => {
    const htmlModel = deps.getHtmlModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    if (!htmlModel || !cssModel || !jsModel) {
      deps.createSnackbar(
        'error',
        __('Export unavailable.', 'kayzart-live-code-editor'),
        deps.noticeIds.export,
        deps.noticeErrorMs
      );
      return;
    }

    deps.createSnackbar('info', __('Exporting...', 'kayzart-live-code-editor'), deps.noticeIds.export);

    const result = await exportKayzArt({
      apiFetch: deps.apiFetch,
      restCompileUrl: deps.restCompileUrl,
      postId: deps.postId,
      html: htmlModel.getValue(),
      css: cssModel.getValue(),
      tailwindEnabled: deps.getTailwindEnabled(),
      tailwindCss: deps.getTailwindCss(),
      js: jsModel.getValue(),
      jsMode: deps.getJsMode(),
      externalScripts: deps.getExternalScripts(),
      externalStyles: deps.getExternalStyles(),
      shadowDomEnabled: deps.getShadowDomEnabled(),
      shortcodeEnabled: deps.getShortcodeEnabled(),
      singlePageEnabled: deps.getSinglePageEnabled(),
      liveHighlightEnabled: deps.getLiveHighlightEnabled(),
    });

    if (result.ok) {
      deps.createSnackbar(
        'success',
        __('Exported.', 'kayzart-live-code-editor'),
        deps.noticeIds.export,
        deps.noticeSuccessMs
      );
      return;
    }

    if (result.error) {
      /* translators: %s: error message. */
      deps.createSnackbar(
        'error',
        sprintf(__('Export error: %s', 'kayzart-live-code-editor'), result.error),
        deps.noticeIds.export,
        deps.noticeErrorMs
      );
    } else {
      deps.createSnackbar(
        'error',
        __('Export failed.', 'kayzart-live-code-editor'),
        deps.noticeIds.export,
        deps.noticeErrorMs
      );
    }
  };

  const handleSave = async (): Promise<{ ok: boolean; error?: string }> => {
    const htmlModel = deps.getHtmlModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    if (!htmlModel || !cssModel || !jsModel) {
      return { ok: false, error: __('Save failed.', 'kayzart-live-code-editor') };
    }
    if (!getUnsavedFlags().hasAny) {
      return { ok: true };
    }
    const { pendingSettingsUpdates, hasUnsavedSettings, hasSettingsValidationErrors } =
      deps.getPendingSettingsState();
    if (hasSettingsValidationErrors) {
      const validationErrorMessage = __('Fix settings errors before saving.', 'kayzart-live-code-editor');
      deps.createSnackbar(
        'error',
        validationErrorMessage,
        deps.noticeIds.save,
        deps.noticeErrorMs
      );
      return { ok: false, error: validationErrorMessage };
    }
    if (saveInFlight) {
      return await saveInFlight;
    }
    const settingsUpdates = hasUnsavedSettings ? { ...pendingSettingsUpdates } : undefined;
    saveInFlight = (async () => {
      deps.createSnackbar('info', __('Saving...', 'kayzart-live-code-editor'), deps.noticeIds.save);

      const result = await saveKayzArt({
        apiFetch: deps.apiFetch,
        restUrl: deps.restUrl,
        postId: deps.postId,
        html: htmlModel.getValue(),
        css: cssModel.getValue(),
        tailwindEnabled: deps.getTailwindEnabled(),
        canEditJs: deps.canEditJs,
        js: jsModel.getValue(),
        jsMode: deps.getJsMode(),
        settingsUpdates,
      });

      if (result.ok) {
        if (result.settings) {
          deps.applySavedSettings(result.settings, Boolean(settingsUpdates));
          deps.applySettingsToSidebar(result.settings);
        }
        deps.clearPendingSettingsState();
        markSavedState();
        deps.onSaveSuccess?.();
        deps.createSnackbar(
          'success',
          __('Saved.', 'kayzart-live-code-editor'),
          deps.noticeIds.save,
          deps.noticeSuccessMs
        );
        return { ok: true };
      }

      if (result.error) {
        /* translators: %s: error message. */
        deps.createSnackbar(
          'error',
          sprintf(__('Save error: %s', 'kayzart-live-code-editor'), result.error),
          deps.noticeIds.save,
          deps.noticeErrorMs
        );
        return { ok: false, error: result.error };
      }

      deps.createSnackbar(
        'error',
        __('Save failed.', 'kayzart-live-code-editor'),
        deps.noticeIds.save,
        deps.noticeErrorMs
      );
      return { ok: false, error: __('Save failed.', 'kayzart-live-code-editor') };
    })();

    try {
      return await saveInFlight;
    } finally {
      saveInFlight = null;
    }
  };

  return {
    getUnsavedFlags,
    syncUnsavedUi,
    markSavedState,
    handleSave,
    handleExport,
  };
}

