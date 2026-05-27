import { createElement, Fragment, createRoot, render, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
  resolveDefaultTemplateMode,
  resolveTemplateMode,
  type DefaultTemplateMode,
  type TemplateMode,
} from '../logic/template-mode';
import {
  createFullHtmlImportSelection,
  parseFullHtmlDocument,
  type FullHtmlImportResult,
  type FullHtmlImportSelection,
} from '../logic/full-html-import';
import type { SettingsData } from '../settings';
import type { ApiFetch } from '../types/api-fetch';

type SnackbarStatus = 'success' | 'error' | 'info' | 'warning';

type ModalControllerDeps = {
  apiFetch: ApiFetch;
  settingsRestUrl?: string;
  postId: number;
  isThemeTemplateModeActive: () => boolean;
  getDefaultTemplateMode: () => DefaultTemplateMode;
  setTemplateModes: (templateMode: TemplateMode, defaultTemplateMode: DefaultTemplateMode) => void;
  applySettingsToSidebar: (settings: Partial<SettingsData>) => void;
  refreshPreview: () => void;
  createSnackbar: (
    status: SnackbarStatus,
    message: string,
    id?: string,
    autoDismissMs?: number
  ) => void;
  noticeIds: {
    templateFallback: string;
  };
  noticeErrorMs: number;
};

type MissingMarkersModalProps = {
  title: string;
  body: string;
  actionLabel: string;
  inFlight: boolean;
  onConfirm: () => void;
};

export type FullHtmlImportDecision =
  | { type: 'split'; selection: FullHtmlImportSelection }
  | { type: 'keep' }
  | { type: 'cancel' };

type FullHtmlImportSelectableItem = keyof FullHtmlImportSelection;

type FullHtmlImportModalProps = {
  result: FullHtmlImportResult;
  canEditJs: boolean;
  onChoose: (action: FullHtmlImportDecision) => void;
};

type FullHtmlImportSourceModalProps = {
  onCancel: () => void;
  onSubmit: (source: string) => void;
};

function MissingMarkersModal({
  title,
  body,
  actionLabel,
  inFlight,
  onConfirm,
}: MissingMarkersModalProps) {
  return (
    <div className="kayzart-modal">
      <div className="kayzart-modalBackdrop" />
      <div className="kayzart-modalDialog" role="dialog" aria-modal="true" aria-label={title}>
        <div className="kayzart-modalHeader">
          <div className="kayzart-modalTitle">{title}</div>
        </div>
        <div className="kayzart-modalBody">
          <p className="kayzart-hintText">{body}</p>
        </div>
        <div className="kayzart-modalActions">
          <button
            type="button"
            className="kayzart-btn kayzart-btn-primary"
            disabled={inFlight}
            onClick={onConfirm}
          >
            {actionLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

function FullHtmlImportSourceModal({
  onCancel,
  onSubmit,
}: FullHtmlImportSourceModalProps) {
  const title = __('フルHTMLを取り込み', 'kayzart-live-code-editor');
  const cancelLabel = __('Cancel', 'kayzart-live-code-editor');
  const [source, setSource] = useState('');
  const [error, setError] = useState('');

  const handleSubmit = () => {
    const nextSource = source.trim();
    if (!nextSource) {
      setError(__('HTML全体を貼り付けてください。', 'kayzart-live-code-editor'));
      return;
    }
    if (!parseFullHtmlDocument(nextSource)) {
      setError(__('フルHTMLとして解析できませんでした。', 'kayzart-live-code-editor'));
      return;
    }
    onSubmit(nextSource);
  };

  return (
    <div className="kayzart-modal">
      <div className="kayzart-modalBackdrop" onClick={onCancel} />
      <div
        className="kayzart-modalDialog kayzart-modalDialog-wide"
        role="dialog"
        aria-modal="true"
        aria-label={title}
      >
        <div className="kayzart-modalHeader">
          <div className="kayzart-modalTitle">{title}</div>
          <button
            type="button"
            className="kayzart-modalClose"
            aria-label={cancelLabel}
            onClick={onCancel}
          >
            ﾃ・
          </button>
        </div>
        <div className="kayzart-modalBody">
          <div className="kayzart-hintBody">
            <p className="kayzart-hintText">
              {__('HTML全体を貼り付けてください。', 'kayzart-live-code-editor')}
              <br />
              {__(
                'head / body / style / script を自動で分けて取り込みます。',
                'kayzart-live-code-editor'
              )}
            </p>
            <textarea
              className="kayzart-fullHtmlImportTextarea"
              value={source}
              onChange={(event) => {
                setSource(event.currentTarget.value);
                if (error) {
                  setError('');
                }
              }}
              aria-label={title}
            />
            {error ? <div className="kayzart-modalError">{error}</div> : null}
          </div>
        </div>
        <div className="kayzart-modalActions">
          <button type="button" className="kayzart-btn kayzart-btn-secondary" onClick={onCancel}>
            {cancelLabel}
          </button>
          <button type="button" className="kayzart-btn kayzart-btn-primary" onClick={handleSubmit}>
            {__('解析する', 'kayzart-live-code-editor')}
          </button>
        </div>
      </div>
    </div>
  );
}

function FullHtmlImportModal({
  result,
  canEditJs,
  onChoose,
}: FullHtmlImportModalProps) {
  const title = __('Complete HTML detected', 'kayzart-live-code-editor');
  const cancelLabel = __('Cancel', 'kayzart-live-code-editor');
  const [selection, setSelection] = useState(createFullHtmlImportSelection());
  const toggleSelection = (key: FullHtmlImportSelectableItem, replace: boolean) => {
    setSelection((current) => ({ ...current, [key]: replace }));
  };
  const items = [
    {
      key: 'html' as const,
      label: __('HTML', 'kayzart-live-code-editor'),
      detail: __('body content and body attributes', 'kayzart-live-code-editor'),
      enabled: true,
    },
    {
      key: 'customHead' as const,
      label: __('head', 'kayzart-live-code-editor'),
      detail: __('head additions', 'kayzart-live-code-editor'),
      enabled: true,
    },
    {
      key: 'css' as const,
      label: __('CSS', 'kayzart-live-code-editor'),
      detail: `${__('style tags', 'kayzart-live-code-editor')} ${result.summary.styleCount}`,
      enabled: true,
    },
    {
      key: 'js' as const,
      label: __('JS', 'kayzart-live-code-editor'),
      detail: `${__('inline script tags', 'kayzart-live-code-editor')} ${
        canEditJs ? result.summary.inlineScriptCount : 0
      }`,
      enabled: canEditJs,
    },
  ].filter((item) => item.enabled);

  return (
    <div className="kayzart-modal">
      <div className="kayzart-modalBackdrop" />
      <div className="kayzart-modalDialog" role="dialog" aria-modal="true" aria-label={title}>
        <div className="kayzart-modalHeader">
          <div className="kayzart-modalTitle">{title}</div>
          <button
            type="button"
            className="kayzart-modalClose"
            aria-label={cancelLabel}
            onClick={() => onChoose({ type: 'cancel' })}
          >
            ×
          </button>
        </div>
        <div className="kayzart-modalBody">
          <div className="kayzart-hintBody">
            <p className="kayzart-hintText">
              {__(
                'The pasted code looks like a complete HTML document. Choose which extracted parts should replace the matching editor content.',
                'kayzart-live-code-editor'
              )}
            </p>
            {!canEditJs ? (
              <p className="kayzart-modalWarning">
                {__(
                  'JavaScript will not be imported because your account cannot edit it.',
                  'kayzart-live-code-editor'
                )}
              </p>
            ) : null}
            <div className="kayzart-importOptions">
              {items.map((item) => {
                const replace = selection[item.key];
                return (
                  <div className="kayzart-importOption" key={item.key}>
                    <div className="kayzart-importOptionText">
                      <span className="kayzart-importOptionLabel">{item.label}</span>
                      <span className="kayzart-importOptionDetail">{item.detail}</span>
                    </div>
                    <div
                      className="kayzart-importOptionChoices"
                      role="radiogroup"
                      aria-label={item.label}
                    >
                      <label>
                        <input
                          type="radio"
                          name={`kayzart-import-${item.key}`}
                          checked={replace}
                          onChange={() => toggleSelection(item.key, true)}
                        />
                        {__('Replace', 'kayzart-live-code-editor')}
                      </label>
                      <label>
                        <input
                          type="radio"
                          name={`kayzart-import-${item.key}`}
                          checked={!replace}
                          onChange={() => toggleSelection(item.key, false)}
                        />
                        {__('Skip', 'kayzart-live-code-editor')}
                      </label>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
        <div className="kayzart-modalActions">
          <button
            type="button"
            className="kayzart-btn kayzart-btn-secondary"
            onClick={() => onChoose({ type: 'cancel' })}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            className="kayzart-btn kayzart-btn-secondary"
            onClick={() => onChoose({ type: 'keep' })}
          >
            {__('Paste as HTML', 'kayzart-live-code-editor')}
          </button>
          <button
            type="button"
            className="kayzart-btn kayzart-btn-primary"
            onClick={() => onChoose({ type: 'split', selection })}
          >
            {__('Split and import', 'kayzart-live-code-editor')}
          </button>
        </div>
      </div>
    </div>
  );
}

export function createModalController(deps: ModalControllerDeps) {
  const missingMarkersTitle = __('Theme template unavailable', 'kayzart-live-code-editor');
  const missingMarkersBody = __(
    'This theme does not output "the_content", so the preview cannot be rendered. KayzArt will switch the template mode to Standalone.', 'kayzart-live-code-editor');
  const missingMarkersActionLabel = __('OK', 'kayzart-live-code-editor');
  const missingMarkersRetryNotice = __(
    'Preview markers are still missing after switching template mode. The current template does not output "the_content".', 'kayzart-live-code-editor');
  const missingMarkersFallbackTemplateMode: 'standalone' = 'standalone';

  let modalHost: HTMLDivElement | null = null;
  let modalRoot: ReturnType<typeof createRoot> | null = null;
  let missingMarkersOpen = false;
  let missingMarkersInFlight = false;
  let fullHtmlImportSourceOpen = false;
  let fullHtmlImportSourceResolver: ((source: string | null) => void) | null = null;
  let fullHtmlImportResult: FullHtmlImportResult | null = null;
  let fullHtmlImportCanEditJs = true;
  let fullHtmlImportResolver: ((action: FullHtmlImportDecision) => void) | null = null;
  let lastMissingMarkersNoticeAt = 0;
  const missingMarkersNoticeCooldownMs = 1500;

  const ensureMounted = () => {
    if (modalHost) {
      return;
    }
    modalHost = document.createElement('div');
    modalHost.className = 'kayzart-modalHost';
    document.body.appendChild(modalHost);
    if (typeof createRoot === 'function') {
      modalRoot = createRoot(modalHost);
    }
  };

  const unmountIfIdle = () => {
    if (missingMarkersOpen || fullHtmlImportSourceOpen || fullHtmlImportResult) {
      return;
    }
    if (modalRoot?.unmount) {
      modalRoot.unmount();
    } else if (modalHost) {
      render(<Fragment />, modalHost);
    }
    modalRoot = null;
    modalHost?.remove();
    modalHost = null;
  };

  const renderModals = () => {
    if (!modalHost) {
      return;
    }
    const node = (
      <Fragment>
        {missingMarkersOpen ? (
          <MissingMarkersModal
            title={missingMarkersTitle}
            body={missingMarkersBody}
            actionLabel={missingMarkersActionLabel}
            inFlight={missingMarkersInFlight}
            onConfirm={() => {
              void confirmMissingMarkersFallback();
            }}
          />
        ) : null}
        {fullHtmlImportResult ? (
          <FullHtmlImportModal
            result={fullHtmlImportResult}
            canEditJs={fullHtmlImportCanEditJs}
            onChoose={closeFullHtmlImportModal}
          />
        ) : null}
        {fullHtmlImportSourceOpen ? (
          <FullHtmlImportSourceModal
            onCancel={() => closeFullHtmlImportSourceModal(null)}
            onSubmit={(source) => closeFullHtmlImportSourceModal(source)}
          />
        ) : null}
      </Fragment>
    );
    if (modalRoot) {
      modalRoot.render(node);
      return;
    }
    render(node, modalHost);
  };

  const closeMissingMarkersModal = () => {
    if (!missingMarkersOpen) return;
    missingMarkersOpen = false;
    renderModals();
    unmountIfIdle();
  };

  function closeFullHtmlImportModal(action: FullHtmlImportDecision) {
    const resolver = fullHtmlImportResolver;
    fullHtmlImportResolver = null;
    fullHtmlImportResult = null;
    renderModals();
    unmountIfIdle();
    resolver?.(action);
  }

  function closeFullHtmlImportSourceModal(source: string | null) {
    const resolver = fullHtmlImportSourceResolver;
    fullHtmlImportSourceResolver = null;
    fullHtmlImportSourceOpen = false;
    renderModals();
    unmountIfIdle();
    resolver?.(source);
  }

  const applyMissingMarkersTemplateMode = async () => {
    if (missingMarkersInFlight) {
      return false;
    }
    if (!deps.settingsRestUrl || !deps.apiFetch) {
      deps.createSnackbar(
        'error',
        __('Settings unavailable.', 'kayzart-live-code-editor'),
        deps.noticeIds.templateFallback,
        deps.noticeErrorMs
      );
      return false;
    }
    missingMarkersInFlight = true;
    renderModals();
    try {
      const response = await deps.apiFetch({
        url: deps.settingsRestUrl,
        method: 'POST',
        data: {
          post_id: deps.postId,
          updates: { templateMode: missingMarkersFallbackTemplateMode },
        },
      });
      if (!response?.ok) {
        deps.createSnackbar(
          'error',
          response?.error || __('Update failed.', 'kayzart-live-code-editor'),
          deps.noticeIds.templateFallback,
          deps.noticeErrorMs
        );
        return false;
      }
      const nextSettings = response.settings as SettingsData | undefined;
      const nextTemplateMode = resolveTemplateMode(
        nextSettings?.templateMode ?? missingMarkersFallbackTemplateMode
      );
      const nextDefaultTemplateMode =
        nextSettings && typeof nextSettings.defaultTemplateMode === 'string'
          ? resolveDefaultTemplateMode(nextSettings.defaultTemplateMode)
          : deps.getDefaultTemplateMode();
      deps.setTemplateModes(nextTemplateMode, nextDefaultTemplateMode);
      deps.applySettingsToSidebar(nextSettings ?? { templateMode: nextTemplateMode });
      deps.refreshPreview();
      return true;
    } catch (error: any) {
      deps.createSnackbar(
        'error',
        error?.message || __('Update failed.', 'kayzart-live-code-editor'),
        deps.noticeIds.templateFallback,
        deps.noticeErrorMs
      );
      return false;
    } finally {
      missingMarkersInFlight = false;
      renderModals();
    }
  };

  const confirmMissingMarkersFallback = async () => {
    if (missingMarkersInFlight) {
      return;
    }
    const ok = await applyMissingMarkersTemplateMode();
    if (ok) {
      closeMissingMarkersModal();
    }
  };

  const openMissingMarkersModal = () => {
    if (missingMarkersOpen) return;
    ensureMounted();
    missingMarkersOpen = true;
    renderModals();
  };

  const handleMissingMarkers = () => {
    if (deps.isThemeTemplateModeActive()) {
      openMissingMarkersModal();
      return;
    }

    const now = Date.now();
    if (now - lastMissingMarkersNoticeAt < missingMarkersNoticeCooldownMs) {
      return;
    }
    lastMissingMarkersNoticeAt = now;
    deps.createSnackbar(
      'error',
      missingMarkersRetryNotice,
      deps.noticeIds.templateFallback,
      deps.noticeErrorMs
    );
  };

  const confirmFullHtmlImport = (
    result: FullHtmlImportResult,
    canEditJs: boolean
  ): Promise<FullHtmlImportDecision> => {
    if (fullHtmlImportResolver) {
      closeFullHtmlImportModal({ type: 'cancel' });
    }
    ensureMounted();
    fullHtmlImportResult = result;
    fullHtmlImportCanEditJs = canEditJs;
    renderModals();
    return new Promise((resolve) => {
      fullHtmlImportResolver = resolve;
    });
  };

  const requestFullHtmlImportSource = (): Promise<string | null> => {
    if (fullHtmlImportSourceResolver) {
      closeFullHtmlImportSourceModal(null);
    }
    ensureMounted();
    fullHtmlImportSourceOpen = true;
    renderModals();
    return new Promise((resolve) => {
      fullHtmlImportSourceResolver = resolve;
    });
  };

  return {
    handleMissingMarkers,
    requestFullHtmlImportSource,
    confirmFullHtmlImport,
  };
}

