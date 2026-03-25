import {
  createElement,
  Fragment,
  createRoot,
  render,
  useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { SettingsData } from './settings';
import type { ImportPayload } from './types';
import type { ApiFetch } from './types/api-fetch';
import type { ImportResponse, SetupResponse } from './types/rest';
import { validateImportPayload } from './setup-wizard/validate-import-payload';

type SetupWizardConfig = {
  container: HTMLElement;
  postId: number;
  restUrl: string;
  importRestUrl?: string;
  apiFetch?: ApiFetch;
  backUrl?: string;
  initialTailwindEnabled?: boolean;
};

export type SetupWizardResult = {
  tailwindEnabled: boolean;
  imported?: {
    payload: ImportPayload;
    settingsData?: SettingsData;
  };
};

type SetupWizardProps = {
  postId: number;
  restUrl: string;
  importRestUrl?: string;
  apiFetch: ApiFetch;
  backUrl?: string;
  initialTailwindEnabled?: boolean;
  onComplete: (result: SetupWizardResult) => void;
};

function SetupWizard({
  postId,
  restUrl,
  importRestUrl,
  apiFetch,
  backUrl,
  initialTailwindEnabled,
  onComplete,
}: SetupWizardProps) {
  const [mode, setMode] = useState<'normal' | 'tailwind' | 'import'>(
    initialTailwindEnabled ? 'tailwind' : 'normal'
  );
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [importPayload, setImportPayload] = useState<ImportPayload | null>(null);
  const [importFileName, setImportFileName] = useState('');

  const handleFileChange = async (event: Event) => {
    const input = event?.target as HTMLInputElement | null;
    const file = input?.files?.[0];
    if (!file) {
      setImportFileName('');
      setImportPayload(null);
      return;
    }

    setImportFileName(file.name);
    setError('');

    try {
      const text = await file.text();
      const raw: unknown = JSON.parse(text);
      const result = validateImportPayload(raw);
      if (result.error) {
        setError(result.error);
        setImportPayload(null);
        return;
      }
      setImportPayload(result.data || null);
    } catch {
      setError(__('Invalid JSON file.', 'kayzart-live-code-editor'));
      setImportPayload(null);
    }
  };

  const handleSubmit = async () => {
    if (saving) return;
    setError('');
    setSaving(true);
    try {
      if (mode === 'import') {
        if (!importRestUrl) {
          throw new Error(__('Import unavailable.', 'kayzart-live-code-editor'));
        }
        if (!importPayload) {
          throw new Error(__('Select a JSON file to import.', 'kayzart-live-code-editor'));
        }

        const response = await apiFetch<ImportResponse>({
          url: importRestUrl,
          method: 'POST',
          data: {
            post_id: postId,
            payload: importPayload,
          },
        });

        if (!response?.ok) {
          throw new Error(response?.error || __('Import failed.', 'kayzart-live-code-editor'));
        }

        if (response.importWarnings?.length) {
          console.warn('[KayzArt] Import warnings', response.importWarnings);
        }

        const normalizedPayload = response.html
          ? { ...importPayload, html: response.html }
          : importPayload;

        onComplete({
          tailwindEnabled: Boolean(response.tailwindEnabled ?? importPayload.tailwindEnabled),
          imported: {
            payload: normalizedPayload,
            settingsData: response.settingsData,
          },
        });
      } else {
        const response = await apiFetch<SetupResponse>({
          url: restUrl,
          method: 'POST',
          data: {
            post_id: postId,
            mode,
          },
        });

        if (!response?.ok) {
          throw new Error(response?.error || __('Setup failed.', 'kayzart-live-code-editor'));
        }

        onComplete({ tailwindEnabled: Boolean(response.tailwindEnabled) });
      }
    } catch (error: unknown) {
      if (error instanceof Error) {
        setError(error.message);
      } else {
        setError(String(error));
      }
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="kayzart-setupOverlay">
      <div className="kayzart-setupCard" role="dialog" aria-modal="true">
        <div className="kayzart-setupTitle">{__('Choose editor mode', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-setupIntro">
          {__(
            'Select TailwindCSS or Normal mode. This choice cannot be changed later.', 'kayzart-live-code-editor')}
        </div>
        <div className="kayzart-setupOptions">
          <label className={`kayzart-setupOption${mode === 'normal' ? ' is-active' : ''}`}>
            <input
              type="radio"
              name="kayzart-setup-mode"
              value="normal"
              checked={mode === 'normal'}
              onChange={() => setMode('normal')}
            />
            <span className="kayzart-setupOptionBody">
              <span className="kayzart-setupOptionTitle">
                {__('Normal (HTML/CSS)', 'kayzart-live-code-editor')}
              </span>
              <span className="kayzart-setupOptionDesc">
                {__('Edit HTML and CSS directly in the code editor.', 'kayzart-live-code-editor')}
              </span>
            </span>
          </label>
          <label className={`kayzart-setupOption${mode === 'tailwind' ? ' is-active' : ''}`}>
            <input
              type="radio"
              name="kayzart-setup-mode"
              value="tailwind"
              checked={mode === 'tailwind'}
              onChange={() => setMode('tailwind')}
            />
            <span className="kayzart-setupOptionBody">
              <span className="kayzart-setupOptionTitle">
                {__('TailwindCSS', 'kayzart-live-code-editor')}
              </span>
              <span className="kayzart-setupOptionDesc">
                {__('Use utility classes. CSS is compiled automatically.', 'kayzart-live-code-editor')}
              </span>
            </span>
          </label>
          <label className={`kayzart-setupOption${mode === 'import' ? ' is-active' : ''}`}>
            <input
              type="radio"
              name="kayzart-setup-mode"
              value="import"
              checked={mode === 'import'}
              onChange={() => setMode('import')}
            />
            <span className="kayzart-setupOptionBody">
              <span className="kayzart-setupOptionTitle">
                {__('Import JSON', 'kayzart-live-code-editor')}
              </span>
              <span className="kayzart-setupOptionDesc">
                {__('Restore from an exported KayzArt JSON file.', 'kayzart-live-code-editor')}
              </span>
            </span>
          </label>
        </div>
        {mode === 'import' ? (
          <div className="kayzart-setupImport">
            <label className="kayzart-btn kayzart-btn-secondary kayzart-setupFileLabel">
              {__('Choose JSON file', 'kayzart-live-code-editor')}
              <input
                className="kayzart-setupFileInput"
                type="file"
                accept="application/json,.json"
                onChange={handleFileChange}
              />
            </label>
            <div className="kayzart-setupFileName">
              {importFileName || __('No file selected.', 'kayzart-live-code-editor')}
            </div>
          </div>
        ) : null}
        <div className="kayzart-setupError">{error || ''}</div>
        <div className="kayzart-setupActions">
          {backUrl ? (
            <a className="kayzart-btn kayzart-btn-secondary" href={backUrl}>
              {__('Back', 'kayzart-live-code-editor')}
            </a>
          ) : null}
          <button
            className="kayzart-btn kayzart-btn-primary"
            type="button"
            onClick={handleSubmit}
            disabled={saving}
          >
            {saving ? __('Saving...', 'kayzart-live-code-editor') : __('Continue', 'kayzart-live-code-editor')}
          </button>
        </div>
      </div>
    </div>
  );
}

export function runSetupWizard(config: SetupWizardConfig): Promise<SetupWizardResult> {
  const { container, apiFetch } = config;

  if (!apiFetch) {
    container.textContent = __('Setup unavailable.', 'kayzart-live-code-editor');
    return Promise.reject(new Error(__('wp.apiFetch is unavailable.', 'kayzart-live-code-editor')));
  }

  return new Promise((resolve) => {
    const root = typeof createRoot === 'function' ? createRoot(container) : null;
    const onComplete = (result: SetupWizardResult) => {
      if (root) {
        root.unmount();
      } else {
        render(<Fragment />, container);
      }
      resolve(result);
    };

    const node = (
      <SetupWizard
        postId={config.postId}
        restUrl={config.restUrl}
        importRestUrl={config.importRestUrl}
        apiFetch={apiFetch}
        backUrl={config.backUrl}
        initialTailwindEnabled={config.initialTailwindEnabled}
        onComplete={onComplete}
      />
    );

    if (root) {
      root.render(node);
    } else {
      render(node, container);
    }
  });
}
