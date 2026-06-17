import { __, sprintf } from '@wordpress/i18n';
import { saveKayzArt } from '../persistence';
import { sanitizeCustomHeadInput } from '../logic/custom-head';
import type { SettingsData } from '../settings';
import type { ApiFetch } from '../types/api-fetch';
import type { JsMode } from '../types/js-mode';
import { EditorRange, type EditorModel } from '../codemirror';
import { presentableDiff } from '@codemirror/merge';

type SnackbarStatus = 'success' | 'error' | 'info' | 'warning';

type UnsavedFlags = {
  html: boolean;
  customHead: boolean;
  css: boolean;
  js: boolean;
  settings: boolean;
  hasAny: boolean;
};

type SaveCopyControllerDeps = {
  apiFetch: ApiFetch;
  restUrl: string;
  postId: number;
  canEditJs: boolean;
  getHtmlModel: () => EditorModel | undefined;
  getCustomHeadModel: () => EditorModel | undefined;
  getCssModel: () => EditorModel | undefined;
  getJsModel: () => EditorModel | undefined;
  getJsMode: () => JsMode;
  getTailwindEnabled: () => boolean;
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
  };
  noticeSuccessMs: number;
  noticeErrorMs: number;
  uiDirtyTargets: {
    htmlTitle: HTMLElement;
    htmlTab: HTMLElement;
    customHeadTab: HTMLElement;
    cssTab: HTMLElement;
    jsTab: HTMLElement;
    compactHtmlTab: HTMLElement;
    compactCustomHeadTab: HTMLElement;
    compactCssTab: HTMLElement;
    compactJsTab: HTMLElement;
  };
  onUnsavedChange: (hasUnsavedChanges: boolean) => void;
  onSaveSuccess?: () => void;
};

const buildLineStarts = (text: string): number[] => {
  const starts = [0];
  for (let i = 0; i < text.length; i += 1) {
    if (text.charCodeAt(i) === 10) {
      starts.push(i + 1);
    }
  }
  return starts;
};

const getLineNumberAtOffset = (
  lineStarts: number[],
  textLength: number,
  rawOffset: number
): number => {
  const offset = Math.max(0, Math.min(rawOffset, textLength));
  let low = 0;
  let high = lineStarts.length - 1;
  while (low < high) {
    const mid = Math.ceil((low + high) / 2);
    if (lineStarts[mid] <= offset) {
      low = mid;
    } else {
      high = mid - 1;
    }
  }
  return low + 1;
};

const NEWLINE_CODE = 10;

const addCoveredLines = (
  target: Set<number>,
  lineStarts: number[],
  textLength: number,
  from: number,
  to: number
) => {
  if (to <= from) {
    return;
  }
  const startLine = getLineNumberAtOffset(lineStarts, textLength, from);
  const endLine = getLineNumberAtOffset(lineStarts, textLength, Math.max(from, to - 1));
  for (let line = startLine; line <= endLine; line += 1) {
    target.add(line);
  }
};

/**
 * 純粋な挿入（削除を伴わない変更）を、挿入テキストが改行で終わる形へ
 * 左方向に回転させて正規化する。
 *
 * Enter をオートインデント付きで押すと、新しい行には次の行と同じインデントが
 * 挿入される。このとき presentableDiff は挿入を「改行 + インデント」（例: "\n  "）
 * と報告するため、変更範囲の末尾が次の行へはみ出し、追加した行とその次の行の
 * 両方が変更行として扱われてしまう。
 *
 * 挿入末尾の文字が挿入直前の文字と一致する限り回転は等価な編集になるため、
 * 改行境界で終わるよう寄せることで余分な行のマークを防ぐ。
 */
const normalizeInsertionRange = (
  text: string,
  from: number,
  to: number
): { from: number; to: number } => {
  let nextFrom = from;
  let nextTo = to;
  while (
    nextTo > nextFrom &&
    text.charCodeAt(nextTo - 1) !== NEWLINE_CODE &&
    nextFrom > 0 &&
    text.charCodeAt(nextFrom - 1) === text.charCodeAt(nextTo - 1)
  ) {
    nextFrom -= 1;
    nextTo -= 1;
  }
  return { from: nextFrom, to: nextTo };
};

export type UnsavedChangeMarkers = {
  changedLines: number[];
  deletedLines: number[];
};

export const computeUnsavedChangeMarkers = (
  savedText: string,
  currentText: string
): UnsavedChangeMarkers => {
  if (savedText === currentText) {
    return { changedLines: [], deletedLines: [] };
  }

  const lineStarts = buildLineStarts(currentText);
  const changed = new Set<number>();
  const deleted = new Set<number>();
  const diff = presentableDiff(savedText, currentText, {
    scanLimit: 500,
    timeout: 50,
  });

  diff.forEach((change) => {
    if (change.toB <= change.fromB) {
      const anchorLine = getLineNumberAtOffset(lineStarts, currentText.length, change.fromB);
      deleted.add(anchorLine);
      return;
    }
    const isPureInsertion = change.toA === change.fromA;
    const { from, to } = isPureInsertion
      ? normalizeInsertionRange(currentText, change.fromB, change.toB)
      : { from: change.fromB, to: change.toB };
    addCoveredLines(changed, lineStarts, currentText.length, from, to);
  });

  changed.forEach((line) => {
    deleted.delete(line);
  });

  return {
    changedLines: Array.from(changed).sort((a, b) => a - b),
    deletedLines: Array.from(deleted).sort((a, b) => a - b),
  };
};

export const computeUnsavedChangeLines = (savedText: string, currentText: string): number[] => {
  return computeUnsavedChangeMarkers(savedText, currentText).changedLines;
};


function replaceModelContent(model: EditorModel, nextText: string) {
  const current = model.getValue();
  if (current === nextText) {
    return;
  }
  const end = model.getPositionAt(current.length);
  model.pushEditOperations(
    [],
    [
      {
        range: new EditorRange(1, 1, end.lineNumber, end.column),
        text: nextText,
      },
    ],
    () => null
  );
}

export function createSaveCopyController(deps: SaveCopyControllerDeps) {
  let saveInFlight: Promise<{ ok: boolean; error?: string }> | null = null;
  let hasUnsavedChanges = false;
  let lastSaved: { html: string; customHead: string; css: string; js: string; jsMode: JsMode } = {
    html: '',
    customHead: '',
    css: '',
    js: '',
    jsMode: deps.getJsMode(),
  };

  const getUnsavedFlags = (): UnsavedFlags => {
    const htmlModel = deps.getHtmlModel();
    const customHeadModel = deps.getCustomHeadModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    const { hasUnsavedSettings } = deps.getPendingSettingsState();
    if (!htmlModel || !customHeadModel || !cssModel || !jsModel) {
      return {
        html: false,
        customHead: false,
        css: false,
        js: false,
        settings: hasUnsavedSettings,
        hasAny: hasUnsavedSettings,
      };
    }
    const htmlDirty = htmlModel.getValue() !== lastSaved.html;
    const customHeadDirty = deps.canEditJs && customHeadModel.getValue() !== lastSaved.customHead;
    const cssDirty = cssModel.getValue() !== lastSaved.css;
    const jsDirty = deps.canEditJs && jsModel.getValue() !== lastSaved.js;
    const jsModeDirty = deps.canEditJs && deps.getJsMode() !== lastSaved.jsMode;
    return {
      html: htmlDirty,
      customHead: customHeadDirty,
      css: cssDirty,
      js: jsDirty || jsModeDirty,
      settings: hasUnsavedSettings,
      hasAny: htmlDirty || customHeadDirty || cssDirty || jsDirty || jsModeDirty || hasUnsavedSettings,
    };
  };

  const syncUnsavedUi = () => {
    const { html, customHead, css, js, hasAny } = getUnsavedFlags();
    const htmlModel = deps.getHtmlModel();
    const customHeadModel = deps.getCustomHeadModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();

    const htmlMarkers = htmlModel
      ? computeUnsavedChangeMarkers(lastSaved.html, htmlModel.getValue())
      : { changedLines: [], deletedLines: [] };
    const customHeadMarkers =
      deps.canEditJs && customHeadModel
        ? computeUnsavedChangeMarkers(lastSaved.customHead, customHeadModel.getValue())
        : { changedLines: [], deletedLines: [] };
    const cssMarkers = cssModel
      ? computeUnsavedChangeMarkers(lastSaved.css, cssModel.getValue())
      : { changedLines: [], deletedLines: [] };
    const jsMarkers =
      deps.canEditJs && jsModel
        ? computeUnsavedChangeMarkers(lastSaved.js, jsModel.getValue())
        : { changedLines: [], deletedLines: [] };

    htmlModel?.setUnsavedChangeLines?.(htmlMarkers.changedLines);
    htmlModel?.setUnsavedDeletionLines?.(htmlMarkers.deletedLines);
    customHeadModel?.setUnsavedChangeLines?.(customHeadMarkers.changedLines);
    customHeadModel?.setUnsavedDeletionLines?.(customHeadMarkers.deletedLines);
    cssModel?.setUnsavedChangeLines?.(cssMarkers.changedLines);
    cssModel?.setUnsavedDeletionLines?.(cssMarkers.deletedLines);
    jsModel?.setUnsavedChangeLines?.(jsMarkers.changedLines);
    jsModel?.setUnsavedDeletionLines?.(jsMarkers.deletedLines);

    deps.uiDirtyTargets.htmlTitle.classList.toggle('has-unsaved', html);
    deps.uiDirtyTargets.htmlTab.classList.toggle('has-unsaved', html);
    deps.uiDirtyTargets.customHeadTab.classList.toggle('has-unsaved', customHead);
    deps.uiDirtyTargets.cssTab.classList.toggle('has-unsaved', css);
    deps.uiDirtyTargets.jsTab.classList.toggle('has-unsaved', js);
    deps.uiDirtyTargets.compactHtmlTab.classList.toggle('has-unsaved', html);
    deps.uiDirtyTargets.compactCustomHeadTab.classList.toggle('has-unsaved', customHead);
    deps.uiDirtyTargets.compactCssTab.classList.toggle('has-unsaved', css);
    deps.uiDirtyTargets.compactJsTab.classList.toggle('has-unsaved', js);
    if (hasAny !== hasUnsavedChanges) {
      hasUnsavedChanges = hasAny;
      deps.onUnsavedChange(hasUnsavedChanges);
    }
  };

  const markSavedState = () => {
    const htmlModel = deps.getHtmlModel();
    const customHeadModel = deps.getCustomHeadModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    if (!htmlModel || !customHeadModel || !cssModel || !jsModel) {
      return;
    }
    lastSaved = {
      html: htmlModel.getValue(),
      customHead: customHeadModel.getValue(),
      css: cssModel.getValue(),
      js: jsModel.getValue(),
      jsMode: deps.getJsMode(),
    };
    syncUnsavedUi();
  };


  const handleSave = async (): Promise<{ ok: boolean; error?: string }> => {
    const htmlModel = deps.getHtmlModel();
    const customHeadModel = deps.getCustomHeadModel();
    const cssModel = deps.getCssModel();
    const jsModel = deps.getJsModel();
    if (!htmlModel || !customHeadModel || !cssModel || !jsModel) {
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
      if (deps.canEditJs) {
        const customHeadSanitized = sanitizeCustomHeadInput(customHeadModel.getValue());
        if (customHeadSanitized.html !== customHeadModel.getValue()) {
          replaceModelContent(customHeadModel, customHeadSanitized.html);
        }
        if (customHeadSanitized.removedTags.length > 0) {
          deps.createSnackbar(
            'warning',
            sprintf(
              __('Removed unsupported head tags: %s', 'kayzart-live-code-editor'),
              customHeadSanitized.removedTags.join(', ')
            ),
            deps.noticeIds.save,
            deps.noticeErrorMs
          );
        }
      }

      const result = await saveKayzArt({
        apiFetch: deps.apiFetch,
        restUrl: deps.restUrl,
        postId: deps.postId,
        html: htmlModel.getValue(),
        customHead: customHeadModel.getValue(),
        css: cssModel.getValue(),
        tailwindEnabled: deps.getTailwindEnabled(),
        canEditJs: deps.canEditJs,
        js: jsModel.getValue(),
        jsMode: deps.getJsMode(),
        settingsUpdates,
      });

      if (result.ok) {
        if (deps.canEditJs && typeof result.customHead === 'string' && result.customHead !== customHeadModel.getValue()) {
          replaceModelContent(customHeadModel, result.customHead);
        }
        if (deps.canEditJs && Array.isArray(result.customHeadRemovedTags) && result.customHeadRemovedTags.length > 0) {
          deps.createSnackbar(
            'warning',
            sprintf(
              __('Removed unsupported head tags: %s', 'kayzart-live-code-editor'),
              result.customHeadRemovedTags.join(', ')
            ),
            deps.noticeIds.save,
            deps.noticeErrorMs
          );
        }
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
  };
}
