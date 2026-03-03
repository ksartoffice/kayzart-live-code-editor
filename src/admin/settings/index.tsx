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

export type SettingsData = {
  title: string;
  slug: string;
  status: string;
  viewUrl?: string;
  templateMode?: 'default' | 'standalone' | 'frame' | 'theme';
  defaultTemplateMode?: 'standalone' | 'frame' | 'theme';
  shadowDomEnabled: boolean;
  shortcodeEnabled: boolean;
  singlePageEnabled: boolean;
  liveHighlightEnabled: boolean;
  canEditJs: boolean;
  externalScripts: string[];
  externalStyles: string[];
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
  onTemplateModeChange?: (mode: 'default' | 'standalone' | 'frame' | 'theme') => void;
  onShadowDomToggle?: (enabled: boolean) => void;
  onShortcodeToggle?: (enabled: boolean) => void;
  onSinglePageToggle?: (enabled: boolean) => void;
  onLiveHighlightToggle?: (enabled: boolean) => void;
  onExternalScriptsChange?: (scripts: string[]) => void;
  onExternalStylesChange?: (styles: string[]) => void;
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

function normalizeList(list: string[]) {
  return list
    .map((entry) => entry.trim())
    .filter(Boolean);
}

function isSameList(left: string[], right: string[]) {
  return left.length === right.length && left.every((value, index) => value === right[index]);
}

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
  postId,
  header,
  onTemplateModeChange,
  onShadowDomToggle,
  onShortcodeToggle,
  onSinglePageToggle,
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
  const resolveSinglePageEnabled = (value?: boolean) =>
    value === undefined ? true : Boolean(value);
  const resolveLiveHighlightEnabled = (value?: boolean) =>
    value === undefined ? true : Boolean(value);
  const [templateMode, setTemplateMode] = useState(resolveTemplateMode(data.templateMode));
  const [defaultTemplateMode, setDefaultTemplateMode] = useState(
    resolveDefaultTemplateMode(data.defaultTemplateMode)
  );
  const [shadowDomEnabled, setShadowDomEnabled] = useState(Boolean(data.shadowDomEnabled));
  const [shortcodeEnabled, setShortcodeEnabled] = useState(Boolean(data.shortcodeEnabled));
  const [singlePageEnabled, setSinglePageEnabled] = useState(
    resolveSinglePageEnabled(data.singlePageEnabled)
  );
  const [liveHighlightEnabled, setLiveHighlightEnabled] = useState(
    resolveLiveHighlightEnabled(data.liveHighlightEnabled)
  );
  const [designError, setDesignError] = useState('');
  const [externalScripts, setExternalScripts] = useState<string[]>(data.externalScripts || []);
  const [externalScriptsError, setExternalScriptsError] = useState('');
  const [externalStyles, setExternalStyles] = useState<string[]>(data.externalStyles || []);
  const [externalStylesError, setExternalStylesError] = useState('');
  const externalScriptsMax = settings.externalScriptsMax;
  const externalStylesMax = settings.externalStylesMax;

  const applySettingsSnapshot = (nextSettings: SettingsData) => {
    settingsRef.current = nextSettings;
    setSettings(nextSettings);
    setShadowDomEnabled(Boolean(nextSettings.shadowDomEnabled));
    setTemplateMode(resolveTemplateMode(nextSettings.templateMode));
    setDefaultTemplateMode(resolveDefaultTemplateMode(nextSettings.defaultTemplateMode));
    setShortcodeEnabled(Boolean(nextSettings.shortcodeEnabled));
    setSinglePageEnabled(resolveSinglePageEnabled(nextSettings.singlePageEnabled));
    setLiveHighlightEnabled(resolveLiveHighlightEnabled(nextSettings.liveHighlightEnabled));
    setExternalScripts(nextSettings.externalScripts || []);
    setExternalStyles(nextSettings.externalStyles || []);
    setDesignError('');
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
        label: __( 'Settings', 'codellia' ),
      },
      {
        id: 'elements',
        label: __( 'Elements', 'codellia' ),
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
        `[Codellia] Failed to mount external settings tab "${activeExternalTab.id}".`,
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
    onShadowDomToggle?.(shadowDomEnabled);
  }, [shadowDomEnabled, onShadowDomToggle]);

  useEffect(() => {
    onShortcodeToggle?.(shortcodeEnabled);
  }, [shortcodeEnabled, onShortcodeToggle]);

  useEffect(() => {
    onSinglePageToggle?.(singlePageEnabled);
  }, [singlePageEnabled, onSinglePageToggle]);

  useEffect(() => {
    onLiveHighlightToggle?.(liveHighlightEnabled);
  }, [liveHighlightEnabled, onLiveHighlightToggle]);

  useEffect(() => {
    onExternalScriptsChange?.(normalizeList(externalScripts));
  }, [externalScripts, onExternalScriptsChange]);

  useEffect(() => {
    onExternalStylesChange?.(normalizeList(externalStyles));
  }, [externalStyles, onExternalStylesChange]);

  const validateExternalScripts = (list: string[]) => {
    if (list.length > externalScriptsMax) {
      /* translators: %d: maximum number of items. */
      return sprintf(
        __( 'You can add up to %d external scripts.', 'codellia' ),
        externalScriptsMax
      );
    }
    if (list.some((entry) => !isValidHttpsUrl(entry))) {
      return __( 'External scripts must be valid https:// URLs.', 'codellia' );
    }
    return '';
  };

  const validateExternalStyles = (list: string[]) => {
    if (list.length > externalStylesMax) {
      /* translators: %d: maximum number of items. */
      return sprintf(
        __( 'You can add up to %d external styles.', 'codellia' ),
        externalStylesMax
      );
    }
    if (list.some((entry) => !isValidHttpsUrl(entry))) {
      return __( 'External styles must be valid https:// URLs.', 'codellia' );
    }
    return '';
  };

  const handleTabChange = (tab: SettingsTab) => {
    setActiveTab(tab);
  };

  const handleShadowDomToggle = (enabled: boolean) => {
    if (!canEditJs) {
      return;
    }
    setDesignError('');
    setShadowDomEnabled(enabled);
  };

  const handleTemplateModeChange = (
    next: 'default' | 'standalone' | 'frame' | 'theme'
  ) => {
    if (!canEditJs) {
      return;
    }
    setDesignError('');
    setTemplateMode(next);
  };

  const handleShortcodeToggle = (enabled: boolean) => {
    if (!canEditJs) {
      return;
    }
    setDesignError('');
    setShortcodeEnabled(enabled);
    const shouldEnableSinglePage = !enabled && !singlePageEnabled;
    if (shouldEnableSinglePage) {
      setSinglePageEnabled(true);
    }
  };

  const handleSinglePageToggle = (enabled: boolean) => {
    if (!canEditJs) {
      return;
    }
    setDesignError('');
    setSinglePageEnabled(enabled);
  };

  const handleLiveHighlightToggle = (enabled: boolean) => {
    setDesignError('');
    setLiveHighlightEnabled(enabled);
  };

  const handleExternalScriptsChange = (next: string[]) => {
    setExternalScripts(next);
    setExternalScriptsError('');
  };

  const handleExternalScriptsCommit = (next: string[]) => {
    if (!canEditJs) {
      return;
    }
    const normalizedNext = normalizeList(next);
    setExternalScripts(next);
    setExternalScriptsError(validateExternalScripts(normalizedNext));
  };

  const handleExternalStylesChange = (next: string[]) => {
    setExternalStyles(next);
    setExternalStylesError('');
  };

  const handleExternalStylesCommit = (next: string[]) => {
    if (!canEditJs) {
      return;
    }
    const normalizedNext = normalizeList(next);
    setExternalStyles(next);
    setExternalStylesError(validateExternalStyles(normalizedNext));
  };

  const pendingSettingsState = useMemo<PendingSettingsState>(() => {
    const updates: Record<string, unknown> = {};
    const savedTemplateMode = resolveTemplateMode(settings.templateMode);
    const savedShadowDomEnabled = Boolean(settings.shadowDomEnabled);
    const savedShortcodeEnabled = Boolean(settings.shortcodeEnabled);
    const savedSinglePageEnabled = resolveSinglePageEnabled(settings.singlePageEnabled);
    const savedLiveHighlightEnabled = resolveLiveHighlightEnabled(settings.liveHighlightEnabled);
    const normalizedExternalScripts = normalizeList(externalScripts);
    const normalizedSavedExternalScripts = normalizeList(settings.externalScripts || []);
    const normalizedExternalStyles = normalizeList(externalStyles);
    const normalizedSavedExternalStyles = normalizeList(settings.externalStyles || []);

    const templateModeChanged = templateMode !== savedTemplateMode;
    const shadowDomChanged = canEditJs && shadowDomEnabled !== savedShadowDomEnabled;
    const shortcodeChanged = canEditJs && shortcodeEnabled !== savedShortcodeEnabled;
    const singlePageChanged = canEditJs && singlePageEnabled !== savedSinglePageEnabled;
    const liveHighlightChanged = liveHighlightEnabled !== savedLiveHighlightEnabled;
    const externalScriptsChanged =
      canEditJs && !isSameList(normalizedExternalScripts, normalizedSavedExternalScripts);
    const externalStylesChanged =
      canEditJs && !isSameList(normalizedExternalStyles, normalizedSavedExternalStyles);

    if (templateModeChanged) {
      updates.templateMode = templateMode;
    }
    if (shadowDomChanged) {
      updates.shadowDomEnabled = shadowDomEnabled;
    }
    if (shortcodeChanged) {
      updates.shortcodeEnabled = shortcodeEnabled;
    }
    if (singlePageChanged) {
      updates.singlePageEnabled = singlePageEnabled;
    }
    if (liveHighlightChanged) {
      updates.liveHighlightEnabled = liveHighlightEnabled;
    }
    if (externalScriptsChanged && !externalScriptsError) {
      updates.externalScripts = normalizedExternalScripts;
    }
    if (externalStylesChanged && !externalStylesError) {
      updates.externalStyles = normalizedExternalStyles;
    }

    return {
      updates,
      hasUnsavedSettings:
        templateModeChanged ||
        shadowDomChanged ||
        shortcodeChanged ||
        singlePageChanged ||
        liveHighlightChanged ||
        externalScriptsChanged ||
        externalStylesChanged,
      hasValidationErrors: Boolean(designError || externalScriptsError || externalStylesError),
    };
  }, [
    canEditJs,
    designError,
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
    settings.shadowDomEnabled,
    settings.shortcodeEnabled,
    settings.singlePageEnabled,
    shadowDomEnabled,
    shortcodeEnabled,
    singlePageEnabled,
  ]);

  useEffect(() => {
    onPendingUpdatesChange?.(pendingSettingsState);
  }, [onPendingUpdatesChange, pendingSettingsState]);

  const tabs = (
    <div className="cd-settingsTabsRow">
      <div
        className="cd-settingsTabs"
        role="tablist"
        aria-label={__( 'Settings tabs', 'codellia' )}
      >
        {tabItems.map((tab) => (
          <button
            key={tab.id}
            className={`cd-settingsTab${activeTab === tab.id ? ' is-active' : ''}`}
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
        className="cd-settingsClose"
        type="button"
        aria-label={__( 'Close settings panel', 'codellia' )}
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
          postId={postId}
          canEditJs={canEditJs}
          templateMode={templateMode}
          defaultTemplateMode={defaultTemplateMode}
          onChangeTemplateMode={handleTemplateModeChange}
          shadowDomEnabled={shadowDomEnabled}
          onToggleShadowDom={handleShadowDomToggle}
          shortcodeEnabled={shortcodeEnabled}
          onToggleShortcode={handleShortcodeToggle}
          singlePageEnabled={singlePageEnabled}
          onToggleSinglePage={handleSinglePageToggle}
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
          error={designError}
          externalScriptsError={externalScriptsError}
          externalStylesError={externalStylesError}
        />
      ) : null}

      {activeTab === 'elements' ? <ElementPanel api={elementsApi} /> : null}

      {activeExternalTab ? (
        <div className="cd-settingsExternalPanel" ref={externalTabHostRef} />
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

