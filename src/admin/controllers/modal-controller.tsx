import { createElement, Fragment, createRoot, render } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
  resolveDefaultTemplateMode,
  resolveTemplateMode,
  type DefaultTemplateMode,
  type TemplateMode,
} from '../logic/template-mode';
import type { FullHtmlImportResult } from '../logic/full-html-import';
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

export type FullHtmlImportAction = 'split' | 'keep' | 'cancel';

type FullHtmlImportModalProps = {
  result: FullHtmlImportResult;
  canEditJs: boolean;
  onChoose: (action: FullHtmlImportAction) => void;
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

function FullHtmlImportModal({ result, canEditJs, onChoose }: FullHtmlImportModalProps) {
  const title = __('Complete HTML detected', 'kayzart-live-code-editor');
  const cancelLabel = __('Cancel', 'kayzart-live-code-editor');
  const summary = [
    __('HTML: body content will be extracted', 'kayzart-live-code-editor'),
    `${__('CSS: style tags', 'kayzart-live-code-editor')} ${result.summary.styleCount}`,
    `${__('JS: inline script tags', 'kayzart-live-code-editor')} ${
      canEditJs ? result.summary.inlineScriptCount : 0
    }`,
    `${__('External CSS', 'kayzart-live-code-editor')} ${result.summary.externalStyleCount}`,
    `${__('External JS', 'kayzart-live-code-editor')} ${
      canEditJs ? result.summary.externalScriptCount : 0
    }`,
  ];

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
            onClick={() => onChoose('cancel')}
          >
            ×
          </button>
        </div>
        <div className="kayzart-modalBody">
          <div className="kayzart-hintBody">
            <p className="kayzart-hintText">
              {__(
                'The pasted code looks like a complete HTML document. KayzArt can split body HTML, style tags, and inline scripts into the matching editors. External CSS will stay at the top of HTML, and external JS will stay at the bottom.',
                'kayzart-live-code-editor'
              )}
            </p>
            {!canEditJs ? (
              <p className="kayzart-modalWarning">
                {__(
                  'JavaScript will not be imported because your account cannot edit JavaScript.',
                  'kayzart-live-code-editor'
                )}
              </p>
            ) : null}
            <ul className="kayzart-importSummary">
              {summary.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </div>
        </div>
        <div className="kayzart-modalActions">
          <button
            type="button"
            className="kayzart-btn kayzart-btn-secondary"
            onClick={() => onChoose('cancel')}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            className="kayzart-btn kayzart-btn-secondary"
            onClick={() => onChoose('keep')}
          >
            {__('Paste as HTML', 'kayzart-live-code-editor')}
          </button>
          <button
            type="button"
            className="kayzart-btn kayzart-btn-primary"
            onClick={() => onChoose('split')}
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
  let fullHtmlImportResult: FullHtmlImportResult | null = null;
  let fullHtmlImportCanEditJs = true;
  let fullHtmlImportResolver: ((action: FullHtmlImportAction) => void) | null = null;
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
    if (missingMarkersOpen || fullHtmlImportResult) {
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

  function closeFullHtmlImportModal(action: FullHtmlImportAction) {
    const resolver = fullHtmlImportResolver;
    fullHtmlImportResolver = null;
    fullHtmlImportResult = null;
    renderModals();
    unmountIfIdle();
    resolver?.(action);
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
  ): Promise<FullHtmlImportAction> => {
    if (fullHtmlImportResolver) {
      closeFullHtmlImportModal('cancel');
    }
    ensureMounted();
    fullHtmlImportResult = result;
    fullHtmlImportCanEditJs = canEditJs;
    renderModals();
    return new Promise((resolve) => {
      fullHtmlImportResolver = resolve;
    });
  };

  return {
    handleMissingMarkers,
    confirmFullHtmlImport,
  };
}

