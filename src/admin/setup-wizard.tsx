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
import type {
  TemplateCatalogResponse,
  TemplateMarket,
  TemplateSummary,
} from './types/templates';

type SetupWizardConfig = {
  container: HTMLElement;
  postId: number;
  restUrl: string;
  templateCatalogRestUrl?: string;
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
  templateCatalogRestUrl?: string;
  apiFetch: ApiFetch;
  backUrl?: string;
  initialTailwindEnabled?: boolean;
  onComplete: (result: SetupWizardResult) => void;
};

type SetupStartMode = 'normal' | 'tailwind' | 'template';
type SetupView = 'start' | 'templates';
type TemplateMarketFilter = 'all' | TemplateMarket;

function buildCatalogRequestUrl(restUrl: string, postId: number): string {
  const separator = restUrl.includes('?') ? '&' : '?';
  return `${restUrl}${separator}post_id=${encodeURIComponent(String(postId))}`;
}

function isTemplateMarket(value: unknown): value is TemplateMarket {
  return value === 'jp' || value === 'en';
}

function sanitizeTemplateSummaries(value: unknown): TemplateSummary[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.filter((item): item is TemplateSummary => {
    if (!item || typeof item !== 'object') {
      return false;
    }

    const template = item as Record<string, unknown>;
    return (
      typeof template.id === 'string' &&
      typeof template.title === 'string' &&
      typeof template.description === 'string' &&
      typeof template.category === 'string' &&
      isTemplateMarket(template.market) &&
      (template.tier === 'free' || template.tier === 'pro') &&
      typeof template.thumbnailUrl === 'string' &&
      typeof template.requiresTailwind === 'boolean' &&
      typeof template.available === 'boolean' &&
      typeof template.version === 'string'
    );
  });
}

export function SetupWizard({
  postId,
  restUrl,
  templateCatalogRestUrl,
  apiFetch,
  backUrl,
  initialTailwindEnabled,
  onComplete,
}: SetupWizardProps) {
  const [mode, setMode] = useState<SetupStartMode>(
    initialTailwindEnabled ? 'tailwind' : 'normal'
  );
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [view, setView] = useState<SetupView>('start');
  const [templates, setTemplates] = useState<TemplateSummary[]>([]);
  const [templateLoading, setTemplateLoading] = useState(false);
  const [templateError, setTemplateError] = useState('');
  const [marketFilter, setMarketFilter] = useState<TemplateMarketFilter>('all');

  const loadTemplates = async () => {
    if (templateLoading) return;

    if (!templateCatalogRestUrl) {
      setTemplateError(__('Template catalog is unavailable.', 'kayzart-live-code-editor'));
      setView('templates');
      return;
    }

    setView('templates');
    setTemplateError('');
    setTemplateLoading(true);
    try {
      const response = await apiFetch<TemplateCatalogResponse>({
        url: buildCatalogRequestUrl(templateCatalogRestUrl, postId),
        method: 'GET',
      });

      if (!response?.ok) {
        throw new Error(response?.error || __('Failed to load templates.', 'kayzart-live-code-editor'));
      }

      setTemplates(sanitizeTemplateSummaries(response.templates));
    } catch (error: unknown) {
      setTemplates([]);
      if (error instanceof Error) {
        setTemplateError(error.message);
      } else {
        setTemplateError(String(error));
      }
    } finally {
      setTemplateLoading(false);
    }
  };

  const handleSubmit = async () => {
    if (saving) return;
    setError('');
    if (mode === 'template') {
      void loadTemplates();
      return;
    }
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

  const filteredTemplates = templates.filter((template) => (
    marketFilter === 'all' || template.market === marketFilter
  ));

  if (view === 'templates') {
    return (
      <div className="kayzart-setupOverlay">
        <div className="kayzart-setupCard kayzart-templateCatalogCard" role="dialog" aria-modal="true">
          <div className="kayzart-setupTitle">{__('Choose a template', 'kayzart-live-code-editor')}</div>
          <div className="kayzart-setupIntro">
            {__('Browse ready-made Tailwind layouts. Applying templates will be available in a later update.', 'kayzart-live-code-editor')}
          </div>
          <div className="kayzart-templateFilters" aria-label={__('Template market filter', 'kayzart-live-code-editor')}>
            {([
              ['all', __('All', 'kayzart-live-code-editor')],
              ['jp', __('JP', 'kayzart-live-code-editor')],
              ['en', __('EN', 'kayzart-live-code-editor')],
            ] as Array<[TemplateMarketFilter, string]>).map(([value, label]) => (
              <button
                className={`kayzart-templateFilter${marketFilter === value ? ' is-active' : ''}`}
                key={value}
                type="button"
                onClick={() => setMarketFilter(value)}
              >
                {label}
              </button>
            ))}
          </div>
          {templateLoading ? (
            <div className="kayzart-templateState">{__('Loading templates...', 'kayzart-live-code-editor')}</div>
          ) : templateError ? (
            <div className="kayzart-templateState is-error">{templateError}</div>
          ) : filteredTemplates.length === 0 ? (
            <div className="kayzart-templateState">{__('No templates found.', 'kayzart-live-code-editor')}</div>
          ) : (
            <div className="kayzart-templateGrid">
              {filteredTemplates.map((template) => {
                const disabled = template.tier === 'pro' || !template.available;
                return (
                  <article
                    className={`kayzart-templateCard${disabled ? ' is-disabled' : ''}`}
                    key={template.id}
                    aria-disabled={disabled ? 'true' : 'false'}
                  >
                    <div className="kayzart-templateThumbWrap">
                      <img
                        alt=""
                        className="kayzart-templateThumb"
                        loading="lazy"
                        src={template.thumbnailUrl}
                      />
                    </div>
                    <div className="kayzart-templateBody">
                      <div className="kayzart-templateMeta">
                        <span>{template.market.toUpperCase()}</span>
                        <span>{template.category}</span>
                        {template.tier === 'pro' || !template.available ? (
                          <span className="kayzart-templateBadge">{__('Pro', 'kayzart-live-code-editor')}</span>
                        ) : null}
                      </div>
                      <h3 className="kayzart-templateTitle">{template.title}</h3>
                      <p className="kayzart-templateDescription">{template.description}</p>
                    </div>
                  </article>
                );
              })}
            </div>
          )}
          <div className="kayzart-setupActions">
            <button
              className="kayzart-btn kayzart-btn-secondary"
              type="button"
              onClick={() => {
                setView('start');
                setTemplateError('');
              }}
            >
              {__('Back', 'kayzart-live-code-editor')}
            </button>
            {templateError ? (
              <button
                className="kayzart-btn kayzart-btn-primary"
                type="button"
                onClick={loadTemplates}
                disabled={templateLoading}
              >
                {__('Retry', 'kayzart-live-code-editor')}
              </button>
            ) : null}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="kayzart-setupOverlay">
      <div className="kayzart-setupCard" role="dialog" aria-modal="true">
        <div className="kayzart-setupTitle">{__('Choose how to start', 'kayzart-live-code-editor')}</div>
        <div className="kayzart-setupIntro">
          {__('Start from a blank editor, TailwindCSS, or a ready-made template. This choice cannot be changed later.', 'kayzart-live-code-editor')}
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
          <label className={`kayzart-setupOption${mode === 'template' ? ' is-active' : ''}`}>
            <input
              type="radio"
              name="kayzart-setup-mode"
              value="template"
              checked={mode === 'template'}
              onChange={() => setMode('template')}
            />
            <span className="kayzart-setupOptionBody">
              <span className="kayzart-setupOptionTitle">
                {__('Templates', 'kayzart-live-code-editor')}
              </span>
              <span className="kayzart-setupOptionDesc">
                {__('Start from a ready-made Tailwind layout.', 'kayzart-live-code-editor')}
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
        templateCatalogRestUrl={config.templateCatalogRestUrl}
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
