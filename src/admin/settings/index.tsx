import {
  createElement,
  Fragment,
  createPortal,
  createRoot,
  render,
  useEffect,
  useMemo,
  useRef,
  useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { X } from 'lucide';
import { renderLucideIcon } from '../lucide-icons';
import { SettingsPanel } from './settings-panel';
import { ElementPanel, type ElementPanelApi } from './element-panel';
import { resolveDefaultTemplateMode, resolveTemplateMode } from '../logic/template-mode';
import {
  getExternalSettingsTabs,
  subscribeExternalSettingsTabs,
  type ResolvedExternalSettingsTab,
} from '../extensions/settings-tab-registry';
import {
  areSameExternalResources,
  normalizeExternalResources,
  serializeExternalResources,
  type ExternalResource,
  type ExternalResourceInput,
} from '../types/external-resource';

export type SettingsData = {
  title: string;
  slug: string;
  status: string;
  viewUrl?: string;
  templateMode?: 'default' | 'standalone' | 'theme';
  defaultTemplateMode?: 'standalone' | 'theme';
  liveHighlightEnabled: boolean;
  canEditJs: boolean;
  externalScripts: ExternalResourceInput[];
  externalStyles: ExternalResourceInput[];
  externalScriptsMax: number;
  externalStylesMax: number;
};

export type PendingSettingsState = {
  updates: Record<string, unknown>;
  hasUnsavedSettings: boolean;
  hasValidationErrors: boolean;
};

type SettingsConfig = {
  container: HTMLElement;
  header?: HTMLElement;
  data: SettingsData;
  postId: number;
  onTemplateModeChange?: (mode: 'default' | 'standalone' | 'theme') => void;
  onLiveHighlightToggle?: (enabled: boolean) => void;
  onExternalScriptsChange?: (scripts: ExternalResource[]) => void;
  onExternalStylesChange?: (styles: ExternalResource[]) => void;
  onTabChange?: (tab: SettingsTab) => void;
  onPendingUpdatesChange?: (state: PendingSettingsState) => void;
  onClosePanel?: () => void;
  elementsApi?: ElementPanelApi;
  onApiReady?: (api: SettingsApi) => void;
};

type SettingsTab = string;

export type SettingsApi = {
  applySettings: (next: Partial<SettingsData>) => void;
  openTab: (tab: SettingsTab) => void;
  setExternalScripts: (scripts: ExternalResource[]) => void;
  setExternalStyles: (styles: ExternalResource[]) => void;
};

const CLOSE_ICON = renderLucideIcon(X, {
  class: 'lucide lucide-x-icon lucide-x',
});

function isValidHttpsUrl(value: string) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:';
  } catch {
    return false;
  }
}

function SettingsSidebar({
  data,
  header,
  onTemplateModeChange,
  onLiveHighlightToggle,
  onExternalScriptsChange,
  onExternalStylesChange,
  onTabChange,
  onPendingUpdatesChange,
  onClosePanel,
  elementsApi,
  onApiReady,
}: SettingsConfig) {
  const settingsRef = useRef<SettingsData>({ ...data });
  const [settings, setSettings] = useState<SettingsData>({ ...data });
  const [activeTab, setActiveTab] = useState<SettingsTab>('settings');
  const [externalTabs, setExternalTabs] = useState<ResolvedExternalSettingsTab[]>(() =>
    getExternalSettingsTabs()
  );
  const externalTabHostRef = useRef<HTMLDivElement | null>(null);
  const externalTabCleanupRef = useRef<(() => void) | null>(null);
  const resolveLiveHighlightEnabled = (value?: boolean) =>
    value === undefined ? true : Boolean(value);
  const [templateMode, setTemplateMode] = useState(resolveTemplateMode(data.templateMode));
  const [defaultTemplateMode, setDefaultTemplateMode] = useState(
    resolveDefaultTemplateMode(data.defaultTemplateMode)
  );
  const [liveHighlightEnabled, setLiveHighlightEnabled] = useState(
    resolveLiveHighlightEnabled(data.liveHighlightEnabled)
  );
  const [externalScripts, setExternalScripts] = useState<ExternalResource[]>(
    normalizeExternalResources(data.externalScripts)
  );
  const [externalScriptsError, setExternalScriptsError] = useState('');
  const [externalStyles, setExternalStyles] = useState<ExternalResource[]>(
    normalizeExternalResources(data.externalStyles)
  );
  const [externalStylesError, setExternalStylesError] = useState('');
  const externalScriptsMax = settings.externalScriptsMax;
  const externalStylesMax = settings.externalStylesMax;

  const applySettingsSnapshot = (nextSettings: SettingsData) => {
    settingsRef.current = nextSettings;
    setSettings(nextSettings);
    setTemplateMode(resolveTemplateMode(nextSettings.templateMode));
    setDefaultTemplateMode(resolveDefaultTemplateMode(nextSettings.defaultTemplateMode));
    setLiveHighlightEnabled(resolveLiveHighlightEnabled(nextSettings.liveHighlightEnabled));
    setExternalScripts(normalizeExternalResources(nextSettings.externalScripts));
    setExternalStyles(normalizeExternalResources(nextSettings.externalStyles));
    setExternalScriptsError('');
    setExternalStylesError('');
  };

  useEffect(() => {
    onTabChange?.(activeTab);
  }, [activeTab, onTabChange]);

  useEffect(() => {
    onApiReady?.({
      applySettings: (nextSettings: Partial<SettingsData>) => {
        const merged = { ...settingsRef.current, ...nextSettings } as SettingsData;
        applySettingsSnapshot(merged);
      },
      openTab: (tab: SettingsTab) => {
        setActiveTab(tab);
      },
      setExternalScripts: (scripts: ExternalResource[]) => {
        const normalized = normalizeExternalResources(scripts).slice(0, externalScriptsMax);
        setExternalScripts(normalized);
        setExternalScriptsError(validateExternalScripts(normalized));
      },
      setExternalStyles: (styles: ExternalResource[]) => {
        const normalized = normalizeExternalResources(styles).slice(0, externalStylesMax);
        setExternalStyles(normalized);
        setExternalStylesError(validateExternalStyles(normalized));
      },
    });
  }, [externalScriptsMax, externalStylesMax, onApiReady]);

  useEffect(() => {
    const syncTabs = () => {
      setExternalTabs(getExternalSettingsTabs());
    };
    const unsubscribe = subscribeExternalSettingsTabs(syncTabs);
    syncTabs();
    return unsubscribe;
  }, []);

  const tabItems = useMemo(
    () => [
      {
        id: 'settings',
        label: __( 'Settings', 'kayzart-live-code-editor'),
      },
      {
        id: 'elements',
        label: __( 'Elements', 'kayzart-live-code-editor'),
      },
      ...externalTabs.map((tab) => ({
        id: tab.id,
        label: tab.label,
      })),
    ],
    [externalTabs]
  );

  useEffect(() => {
    if (!tabItems.some((tab) => tab.id === activeTab)) {
      setActiveTab('settings');
    }
  }, [activeTab, tabItems]);

  const activeExternalTab = useMemo(
    () => externalTabs.find((tab) => tab.id === activeTab) || null,
    [activeTab, externalTabs]
  );

  useEffect(() => {
    const host = externalTabHostRef.current;

    externalTabCleanupRef.current?.();
    externalTabCleanupRef.current = null;
    if (host) {
      host.textContent = '';
    }

    if (!activeExternalTab || !host) {
      return;
    }

    try {
      const cleanup = activeExternalTab.mount(host);
      if (typeof cleanup === 'function') {
        externalTabCleanupRef.current = cleanup;
      }
    } catch (error) {
      // eslint-disable-next-line no-console
      console.error(
        `[KayzArt] Failed to mount external settings tab "${activeExternalTab.id}".`,
        error
      );
    }

    return () => {
      externalTabCleanupRef.current?.();
      externalTabCleanupRef.current = null;
      if (host) {
        host.textContent = '';
      }
    };
  }, [activeExternalTab]);

  const canEditJs = Boolean(settings.canEditJs);

  useEffect(() => {
    onTemplateModeChange?.(templateMode);
  }, [templateMode, onTemplateModeChange]);

  useEffect(() => {
    onLiveHighlightToggle?.(liveHighlightEnabled);
  }, [liveHighlightEnabled, onLiveHighlightToggle]);

  useEffect(() => {
    onExternalScriptsChange?.(normalizeExternalResources(externalScripts));
  }, [externalScripts, onExternalScriptsChange]);

  useEffect(() => {
    onExternalStylesChange?.(normalizeExternalResources(externalStyles));
  }, [externalStyles, onExternalStylesChange]);

  const validateExternalScripts = (list: ExternalResource[]) => {
    if (list.length > externalScriptsMax) {
      /* translators: %d: maximum number of items. */
      return sprintf(
        __( 'You can add up to %d external scripts.', 'kayzart-live-code-editor'),
        externalScriptsMax
      );
    }
    if (list.some((entry) => !isValidHttpsUrl(entry.url))) {
      return __( 'External scripts must be valid https:// URLs.', 'kayzart-live-code-editor');
    }
    return '';
  };

  const validateExternalStyles = (list: ExternalResource[]) => {
    if (list.length > externalStylesMax) {
      /* translators: %d: maximum number of items. */
      return sprintf(
        __( 'You can add up to %d external styles.', 'kayzart-live-code-editor'),
        externalStylesMax
      );
    }
    if (list.some((entry) => !isValidHttpsUrl(entry.url))) {
      return __( 'External styles must be valid https:// URLs.', 'kayzart-live-code-editor');
    }
    return '';
  };

  const handleTabChange = (tab: SettingsTab) => {
    setActiveTab(tab);
  };

  const handleTemplateModeChange = (next: 'default' | 'standalone' | 'theme') => {
    if (!canEditJs) {
      return;
    }
    setTemplateMode(next);
  };

  const handleLiveHighlightToggle = (enabled: boolean) => {
    setLiveHighlightEnabled(enabled);
  };

  const handleExternalScriptsChange = (next: ExternalResource[]) => {
    setExternalScripts(next);
    setExternalScriptsError('');
  };

  const handleExternalScriptsCommit = (next: ExternalResource[]) => {
    if (!canEditJs) {
      return;
    }
    const normalizedNext = normalizeExternalResources(next);
    setExternalScripts(next);
    setExternalScriptsError(validateExternalScripts(normalizedNext));
  };

  const handleExternalStylesChange = (next: ExternalResource[]) => {
    setExternalStyles(next);
    setExternalStylesError('');
  };

  const handleExternalStylesCommit = (next: ExternalResource[]) => {
    if (!canEditJs) {
      return;
    }
    const normalizedNext = normalizeExternalResources(next);
    setExternalStyles(next);
    setExternalStylesError(validateExternalStyles(normalizedNext));
  };

  const pendingSettingsState = useMemo<PendingSettingsState>(() => {
    const updates: Record<string, unknown> = {};
    const savedTemplateMode = resolveTemplateMode(settings.templateMode);
    const savedLiveHighlightEnabled = resolveLiveHighlightEnabled(settings.liveHighlightEnabled);
    const normalizedExternalScripts = normalizeExternalResources(externalScripts);
    const normalizedSavedExternalScripts = normalizeExternalResources(settings.externalScripts);
    const normalizedExternalStyles = normalizeExternalResources(externalStyles);
    const normalizedSavedExternalStyles = normalizeExternalResources(settings.externalStyles);

    const templateModeChanged = templateMode !== savedTemplateMode;
    const liveHighlightChanged = liveHighlightEnabled !== savedLiveHighlightEnabled;
    const externalScriptsChanged =
      canEditJs && !areSameExternalResources(normalizedExternalScripts, normalizedSavedExternalScripts);
    const externalStylesChanged =
      canEditJs && !areSameExternalResources(normalizedExternalStyles, normalizedSavedExternalStyles);

    if (templateModeChanged) {
      updates.templateMode = templateMode;
    }
    if (liveHighlightChanged) {
      updates.liveHighlightEnabled = liveHighlightEnabled;
    }
    if (externalScriptsChanged && !externalScriptsError) {
      updates.externalScripts = serializeExternalResources(normalizedExternalScripts);
    }
    if (externalStylesChanged && !externalStylesError) {
      updates.externalStyles = serializeExternalResources(normalizedExternalStyles);
    }

    return {
      updates,
      hasUnsavedSettings:
        templateModeChanged ||
        liveHighlightChanged ||
        externalScriptsChanged ||
        externalStylesChanged,
      hasValidationErrors: Boolean(externalScriptsError || externalStylesError),
    };
  }, [
    canEditJs,
    externalScripts,
    externalScriptsError,
    externalStyles,
    externalStylesError,
    templateMode,
    liveHighlightEnabled,
    settings.externalScripts,
    settings.externalStyles,
    settings.templateMode,
    settings.liveHighlightEnabled,
  ]);

  useEffect(() => {
    onPendingUpdatesChange?.(pendingSettingsState);
  }, [onPendingUpdatesChange, pendingSettingsState]);

  const tabs = (
    <div className="kayzart-settingsTabsRow">
      <div
        className="kayzart-settingsTabs"
        role="tablist"
        aria-label={__( 'Settings tabs', 'kayzart-live-code-editor')}
      >
        {tabItems.map((tab) => (
          <button
            key={tab.id}
            className={`kayzart-settingsTab${activeTab === tab.id ? ' is-active' : ''}`}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            onClick={() => handleTabChange(tab.id)}
          >
            {tab.label}
          </button>
        ))}
      </div>
      <button
        className="kayzart-settingsClose"
        type="button"
        aria-label={__( 'Close settings panel', 'kayzart-live-code-editor')}
        onClick={() => onClosePanel?.()}
      >
        <span
          aria-hidden="true"
          dangerouslySetInnerHTML={{ __html: CLOSE_ICON }}
        />
      </button>
    </div>
  );

  const tabsNode = header ? createPortal(tabs, header) : tabs;

  return (
    <Fragment>
      {tabsNode}

      {activeTab === 'settings' ? (
        <SettingsPanel
          canEditJs={canEditJs}
          templateMode={templateMode}
          defaultTemplateMode={defaultTemplateMode}
          onChangeTemplateMode={handleTemplateModeChange}
          liveHighlightEnabled={liveHighlightEnabled}
          onToggleLiveHighlight={handleLiveHighlightToggle}
          externalScripts={externalScripts}
          onChangeExternalScripts={handleExternalScriptsChange}
          onCommitExternalScripts={handleExternalScriptsCommit}
          externalStyles={externalStyles}
          onChangeExternalStyles={handleExternalStylesChange}
          onCommitExternalStyles={handleExternalStylesCommit}
          externalScriptsMax={externalScriptsMax}
          externalStylesMax={externalStylesMax}
          disabled={!canEditJs}
          externalScriptsError={externalScriptsError}
          externalStylesError={externalStylesError}
        />
      ) : null}

      {activeTab === 'elements' ? <ElementPanel api={elementsApi} /> : null}

      {activeExternalTab ? (
        <div className="kayzart-settingsExternalPanel" ref={externalTabHostRef} />
      ) : null}
    </Fragment>
  );
}

export function initSettings(config: SettingsConfig) {
  const { container } = config;
  let applySettingsImpl: (next: Partial<SettingsData>) => void = () => {};
  let openTabImpl: (tab: SettingsTab) => void = () => {};
  let setExternalScriptsImpl: (scripts: ExternalResource[]) => void = () => {};
  let setExternalStylesImpl: (styles: ExternalResource[]) => void = () => {};
  const api: SettingsApi = {
    applySettings(next: Partial<SettingsData>) {
      applySettingsImpl(next);
    },
    openTab(tab: SettingsTab) {
      openTabImpl(tab);
    },
    setExternalScripts(scripts: ExternalResource[]) {
      setExternalScriptsImpl(scripts);
    },
    setExternalStyles(styles: ExternalResource[]) {
      setExternalStylesImpl(styles);
    },
  };

  const root = typeof createRoot === 'function' ? createRoot(container) : null;
  const node = (
    <SettingsSidebar
      {...config}
      onApiReady={(nextApi) => {
        applySettingsImpl = nextApi.applySettings;
        openTabImpl = nextApi.openTab;
        setExternalScriptsImpl = nextApi.setExternalScripts;
        setExternalStylesImpl = nextApi.setExternalStyles;
      }}
    />
  );
  if (root) {
    root.render(node);
  } else {
    render(node, container);
  }
  return api;
}

