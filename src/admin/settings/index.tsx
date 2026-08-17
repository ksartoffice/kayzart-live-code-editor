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
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { __ } from '@wordpress/i18n';
import { X } from 'lucide';
import { renderLucideIcon } from '../lucide-icons';
import { SettingsPanel } from './settings-panel';
import { ElementPanel, type ElementPanelApi } from './element-panel';
import { HistoryPanel } from './history-panel';
import { AiEditorPanel } from '../../editor-ai/main';
import type { EditorSnapshot } from '../extensions/settings-tab-registry';
import { resolveDefaultTemplateMode, resolveTemplateMode } from '../logic/template-mode';
import {
  getExternalSettingsTabs,
  subscribeExternalSettingsTabs,
  type ResolvedExternalSettingsTab,
} from '../extensions/settings-tab-registry';
import type { EditorCssMode } from '../types/css-mode';

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
  editorMode: EditorCssMode;
  onEditorModeChange?: (mode: EditorCssMode) => void;
  onLiveHighlightToggle?: (enabled: boolean) => void;
  onTabChange?: (tab: SettingsTab) => void;
  onPendingUpdatesChange?: (state: PendingSettingsState) => void;
  onClosePanel?: () => void;
  elementsApi?: ElementPanelApi;
  onApiReady?: (api: SettingsApi) => void;
  aiEnabled: boolean;
	initialTab?: string;
};

type SettingsTab = string;

export type SettingsApi = {
  applySettings: (next: Partial<SettingsData>) => void;
  openTab: (tab: SettingsTab) => void;
  refreshHistory: () => void;
  setEditorModeState: (mode: EditorCssMode, disabled: boolean) => void;
};

const CLOSE_ICON = renderLucideIcon(X, {
  class: 'lucide lucide-x-icon lucide-x',
});

export function getCoreSettingsTabs(aiEnabled: boolean) {
  return [
    ...(aiEnabled
      ? [{ id: 'kayzart-ai', label: __( 'AI Edit', 'kayzart-live-code-editor' ) }]
      : []),
    {
      id: 'elements',
      label: __( 'Elements', 'kayzart-live-code-editor'),
    },
    {
      id: 'history',
      label: __( 'History', 'kayzart-live-code-editor'),
    },
    {
      id: 'settings',
      label: __( 'Settings', 'kayzart-live-code-editor'),
    },
  ];
}

export function getKeyboardTabIndex(key: string, currentIndex: number, tabCount: number) {
  if (tabCount < 1) return null;
  if (key === 'ArrowRight') return (currentIndex + 1) % tabCount;
  if (key === 'ArrowLeft') return (currentIndex - 1 + tabCount) % tabCount;
  if (key === 'Home') return 0;
  if (key === 'End') return tabCount - 1;
  return null;
}

function SettingsSidebar({
  data,
  postId,
  header,
  onTemplateModeChange,
  editorMode: initialEditorMode,
  onEditorModeChange,
  onLiveHighlightToggle,
  onTabChange,
  onPendingUpdatesChange,
  onClosePanel,
  elementsApi,
  onApiReady,
  aiEnabled,
	initialTab,
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
  const [activeTab, setActiveTab] = useState<SettingsTab>(
	initialTab || (aiEnabled ? 'kayzart-ai' : 'elements')
  );
  const [externalTabs, setExternalTabs] = useState<ResolvedExternalSettingsTab[]>(() =>
    getExternalSettingsTabs()
  );
  const [historyRefreshToken, setHistoryRefreshToken] = useState(0);
  const [editorMode, setEditorMode] = useState<EditorCssMode>(initialEditorMode);
  const [editorModeDisabled, setEditorModeDisabled] = useState(false);
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
      setEditorModeState: (mode: EditorCssMode, disabled: boolean) => {
        setEditorMode(mode);
        setEditorModeDisabled(disabled);
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
      ...getCoreSettingsTabs(aiEnabled),
      ...externalTabs.map((tab) => ({
        id: tab.id,
        label: tab.label,
      })),
    ],
    [aiEnabled, externalTabs]
  );

  useEffect(() => {
    if (!tabItems.some((tab) => tab.id === activeTab)) {
      setActiveTab(aiEnabled ? 'kayzart-ai' : 'elements');
    }
  }, [activeTab, aiEnabled, tabItems]);

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

  const tabDomId = (tab: SettingsTab) => `kayzart-settings-tab-${tab.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
  const panelDomId = (tab: SettingsTab) => `kayzart-settings-panel-${tab.replace(/[^a-zA-Z0-9_-]/g, '-')}`;

  const handleTabKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>, index: number) => {
    const nextIndex = getKeyboardTabIndex(event.key, index, tabItems.length);
    if (nextIndex === null) return;
    event.preventDefault();
    const nextTab = tabItems[nextIndex];
    setActiveTab(nextTab.id);
    document.getElementById(tabDomId(nextTab.id))?.focus();
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
        {tabItems.map((tab, index) => (
          <button
            key={tab.id}
            id={tabDomId(tab.id)}
            className={`kayzart-settingsTab${activeTab === tab.id ? ' is-active' : ''}`}
            type="button"
            role="tab"
            aria-selected={activeTab === tab.id}
            aria-controls={panelDomId(tab.id)}
            tabIndex={activeTab === tab.id ? 0 : -1}
            onClick={() => handleTabChange(tab.id)}
            onKeyDown={(event) => handleTabKeyDown(event, index)}
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

      {aiEnabled ? (
        <div
          id={panelDomId('kayzart-ai')}
          role="tabpanel"
          aria-labelledby={tabDomId('kayzart-ai')}
          hidden={activeTab !== 'kayzart-ai'}
        >
          <AiEditorPanel active={activeTab === 'kayzart-ai'} />
        </div>
      ) : null}

      {activeTab === 'settings' ? (
        <div id={panelDomId('settings')} role="tabpanel" aria-labelledby={tabDomId('settings')}>
          <SettingsPanel
            canEditJs={canEditJs}
            templateMode={templateMode}
            defaultTemplateMode={defaultTemplateMode}
            onChangeTemplateMode={handleTemplateModeChange}
            liveHighlightEnabled={liveHighlightEnabled}
            onToggleLiveHighlight={handleLiveHighlightToggle}
            editorMode={editorMode}
            onChangeEditorMode={(mode) => onEditorModeChange?.(mode)}
            editorModeDisabled={editorModeDisabled}
            disabled={!canEditJs}
          />
        </div>
      ) : null}

      {activeTab === 'elements' ? (
        <div id={panelDomId('elements')} role="tabpanel" aria-labelledby={tabDomId('elements')}>
          <ElementPanel api={elementsApi} />
        </div>
      ) : null}

      {activeTab === 'history' ? (
        <div id={panelDomId('history')} role="tabpanel" aria-labelledby={tabDomId('history')}>
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
        </div>
      ) : null}

      {activeExternalTab ? (
        <div
          id={panelDomId(activeExternalTab.id)}
          className="kayzart-settingsExternalPanel"
          role="tabpanel"
          aria-labelledby={tabDomId(activeExternalTab.id)}
          ref={externalTabHostRef}
        />
      ) : null}
    </Fragment>
  );
}

export function initSettings(config: SettingsConfig) {
  const { container } = config;
  let applySettingsImpl: (next: Partial<SettingsData>) => void = () => {};
  let openTabImpl: (tab: SettingsTab) => void = () => {};
  let refreshHistoryImpl: () => void = () => {};
  let setEditorModeStateImpl: (mode: EditorCssMode, disabled: boolean) => void = () => {};
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
    setEditorModeState(mode: EditorCssMode, disabled: boolean) {
      setEditorModeStateImpl(mode, disabled);
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
        setEditorModeStateImpl = nextApi.setEditorModeState;
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

