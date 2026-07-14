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
import { HistoryPanel } from './history-panel';
import type { EditorSnapshot } from '../extensions/settings-tab-registry';
import type { WorkspaceMode } from '../workspace-mode';
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
  apiFetch: <T>(options: { url: string; method?: string }) => Promise<T>;
  revisionsRestUrl: string;
  revisionsSupported: boolean;
  wpVersion: string;
  canUpdateCore: boolean;
  updateCoreUrl: string;
  hasUnsavedChanges: () => boolean;
  onLoadSnapshot: (snapshot: EditorSnapshot) => boolean;
  onTemplateModeChange?: (mode: 'default' | 'standalone' | 'theme') => void;
  onLiveHighlightToggle?: (enabled: boolean) => void;
  onTabChange?: (tab: SettingsTab) => void;
  onPendingUpdatesChange?: (state: PendingSettingsState) => void;
  onClosePanel?: () => void;
  elementsApi?: ElementPanelApi;
  workspaceMode: WorkspaceMode;
  onApiReady?: (api: SettingsApi) => void;
};

type SettingsTab = string;

export type SettingsApi = {
  applySettings: (next: Partial<SettingsData>) => void;
  openTab: (tab: SettingsTab) => void;
  refreshHistory: () => void;
  setWorkspaceMode: (mode: WorkspaceMode) => void;
};

const CLOSE_ICON = renderLucideIcon(X, {
  class: 'lucide lucide-x-icon lucide-x',
});

function SettingsSidebar({
  data,
  postId,
  header,
  onTemplateModeChange,
  onLiveHighlightToggle,
  onTabChange,
  onPendingUpdatesChange,
  onClosePanel,
  elementsApi,
  workspaceMode: initialWorkspaceMode,
  onApiReady,
  apiFetch,
  revisionsRestUrl,
  revisionsSupported,
  wpVersion,
  canUpdateCore,
  updateCoreUrl,
  hasUnsavedChanges,
  onLoadSnapshot,
}: SettingsConfig) {
  const settingsRef = useRef<SettingsData>({ ...data });
  const [settings, setSettings] = useState<SettingsData>({ ...data });
  const [activeTab, setActiveTab] = useState<SettingsTab>('settings');
  const [workspaceMode, setWorkspaceMode] = useState<WorkspaceMode>(initialWorkspaceMode);
  const [externalTabs, setExternalTabs] = useState<ResolvedExternalSettingsTab[]>(() =>
    getExternalSettingsTabs()
  );
  const [historyRefreshToken, setHistoryRefreshToken] = useState(0);
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
      refreshHistory: () => setHistoryRefreshToken((current) => current + 1),
      setWorkspaceMode,
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
    () => workspaceMode === 'client' ? [
      {
        id: 'elements',
        label: __( 'Elements', 'kayzart-live-code-editor'),
      },
    ] : [
      {
        id: 'settings',
        label: __( 'Settings', 'kayzart-live-code-editor'),
      },
      {
        id: 'history',
        label: __( 'History', 'kayzart-live-code-editor'),
      },
      ...externalTabs.map((tab) => ({
        id: tab.id,
        label: tab.label,
      })),
    ],
    [externalTabs, workspaceMode]
  );

  useEffect(() => {
    if (!tabItems.some((tab) => tab.id === activeTab)) {
      setActiveTab(workspaceMode === 'client' ? 'elements' : 'settings');
    }
  }, [activeTab, tabItems, workspaceMode]);

  const activeExternalTab = useMemo(
    () => workspaceMode === 'creator'
      ? externalTabs.find((tab) => tab.id === activeTab) || null
      : null,
    [activeTab, externalTabs, workspaceMode]
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
        `[Kayzart] Failed to mount external settings tab "${activeExternalTab.id}".`,
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

  const tabs = workspaceMode === 'client' ? (
    <div className="kayzart-settingsTabsRow kayzart-settingsTabsRow-client">
      <div className="kayzart-settingsPanelTitle">
        {__( 'Elements', 'kayzart-live-code-editor')}
      </div>
    </div>
  ) : (
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

      {activeTab === 'elements' ? <ElementPanel api={elementsApi} mode={workspaceMode} /> : null}

      {activeTab === 'history' ? (
        <HistoryPanel
          postId={postId}
          restUrl={revisionsRestUrl}
          apiFetch={apiFetch}
          supported={revisionsSupported}
          currentVersion={wpVersion}
          canUpdateCore={canUpdateCore}
          updateCoreUrl={updateCoreUrl}
          refreshToken={historyRefreshToken}
          hasUnsavedChanges={hasUnsavedChanges}
          onLoadSnapshot={onLoadSnapshot}
        />
      ) : null}

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
  let refreshHistoryImpl: () => void = () => {};
  let setWorkspaceModeImpl: (mode: WorkspaceMode) => void = () => {};
  const api: SettingsApi = {
    applySettings(next: Partial<SettingsData>) {
      applySettingsImpl(next);
    },
    openTab(tab: SettingsTab) {
      openTabImpl(tab);
    },
    refreshHistory() {
      refreshHistoryImpl();
    },
    setWorkspaceMode(mode: WorkspaceMode) {
      setWorkspaceModeImpl(mode);
    },
  };

  const root = typeof createRoot === 'function' ? createRoot(container) : null;
  const node = (
    <SettingsSidebar
      {...config}
      onApiReady={(nextApi) => {
        applySettingsImpl = nextApi.applySettings;
        openTabImpl = nextApi.openTab;
        refreshHistoryImpl = nextApi.refreshHistory;
        setWorkspaceModeImpl = nextApi.setWorkspaceMode;
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

