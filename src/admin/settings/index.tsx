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
import { __ } from '@wordpress/i18n';
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

export type SettingsData = {
  title: string;
  slug: string;
  status: string;
  viewUrl?: string;
  templateMode?: 'default' | 'standalone' | 'theme';
  defaultTemplateMode?: 'standalone' | 'theme';
  liveHighlightEnabled: boolean;
  canEditJs: boolean;
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
};

const CLOSE_ICON = renderLucideIcon(X, {
  class: 'lucide lucide-x-icon lucide-x',
});

function SettingsSidebar({
  data,
  header,
  onTemplateModeChange,
  onLiveHighlightToggle,
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

  const applySettingsSnapshot = (nextSettings: SettingsData) => {
    settingsRef.current = nextSettings;
    setSettings(nextSettings);
    setTemplateMode(resolveTemplateMode(nextSettings.templateMode));
    setDefaultTemplateMode(resolveDefaultTemplateMode(nextSettings.defaultTemplateMode));
    setLiveHighlightEnabled(resolveLiveHighlightEnabled(nextSettings.liveHighlightEnabled));
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
    });
  }, [onApiReady]);

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

  const pendingSettingsState = useMemo<PendingSettingsState>(() => {
    const updates: Record<string, unknown> = {};
    const savedTemplateMode = resolveTemplateMode(settings.templateMode);
    const savedLiveHighlightEnabled = resolveLiveHighlightEnabled(settings.liveHighlightEnabled);

    const templateModeChanged = templateMode !== savedTemplateMode;
    const liveHighlightChanged = liveHighlightEnabled !== savedLiveHighlightEnabled;

    if (templateModeChanged) {
      updates.templateMode = templateMode;
    }
    if (liveHighlightChanged) {
      updates.liveHighlightEnabled = liveHighlightEnabled;
    }

    return {
      updates,
      hasUnsavedSettings:
        templateModeChanged ||
        liveHighlightChanged,
      hasValidationErrors: false,
    };
  }, [
    templateMode,
    liveHighlightEnabled,
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
          disabled={!canEditJs}
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
  const api: SettingsApi = {
    applySettings(next: Partial<SettingsData>) {
      applySettingsImpl(next);
    },
    openTab(tab: SettingsTab) {
      openTabImpl(tab);
    },
  };

  const root = typeof createRoot === 'function' ? createRoot(container) : null;
  const node = (
    <SettingsSidebar
      {...config}
      onApiReady={(nextApi) => {
        applySettingsImpl = nextApi.applySettings;
        openTabImpl = nextApi.openTab;
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

