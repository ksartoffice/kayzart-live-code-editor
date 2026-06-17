import {
  createElement,
  Fragment,
  createRoot,
  render,
  useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ApiFetch } from './types/api-fetch';
import type { SetupResponse } from './types/rest';

type SetupWizardConfig = {
  container: HTMLElement;
  postId: number;
  restUrl: string;
  apiFetch?: ApiFetch;
  backUrl?: string;
  initialTailwindEnabled?: boolean;
};

export type SetupWizardResult = {
  tailwindEnabled: boolean;
};

type SetupWizardProps = {
  postId: number;
  restUrl: string;
  apiFetch: ApiFetch;
  backUrl?: string;
  initialTailwindEnabled?: boolean;
  onComplete: (result: SetupWizardResult) => void;
};

function SetupWizard({
  postId,
  restUrl,
  apiFetch,
  backUrl,
  initialTailwindEnabled,
  onComplete,
}: SetupWizardProps) {
  const [mode, setMode] = useState<'normal' | 'tailwind'>(
    initialTailwindEnabled ? 'tailwind' : 'normal'
  );
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const handleSubmit = async () => {
    if (saving) return;
    setError('');
    setSaving(true);
    try {
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
          {__('Select TailwindCSS or Normal mode. This choice cannot be changed later.', 'kayzart-live-code-editor')}
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
                {__('Use Tailwind CSS v4 utility classes. CSS is compiled automatically.', 'kayzart-live-code-editor')}
              </span>
            </span>
          </label>
        </div>
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
    const host = document.createElement('div');
    host.className = 'kayzart-setupHost';
    container.append(host);
    const root = typeof createRoot === 'function' ? createRoot(host) : null;
    const onComplete = (result: SetupWizardResult) => {
      if (root) {
        root.unmount();
      } else {
        render(<Fragment />, host);
      }
      host.remove();
      resolve(result);
    };

    const node = (
      <SetupWizard
        postId={config.postId}
        restUrl={config.restUrl}
        apiFetch={apiFetch}
        backUrl={config.backUrl}
        initialTailwindEnabled={config.initialTailwindEnabled}
        onComplete={onComplete}
      />
    );

    if (root) {
      root.render(node);
    } else {
      render(node, host);
    }
  });
}
