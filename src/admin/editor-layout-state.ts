export type EditorLayoutKind = 'desktop' | 'compact';
export type InitialEntryMode = 'ai' | 'blank' | '';

export type EditorLayoutState = {
  version: 1;
  settingsOpen: boolean;
  settingsTab: string;
  editorCollapsed: boolean;
  settingsWidth?: number;
};

type LayoutStoreOptions = {
  namespace: string;
  kind: EditorLayoutKind;
  aiEnabled: boolean;
  fallbackEditorCollapsed: boolean;
  storage?: Storage | null;
};

const LEGACY_PANEL_STATE_KEY = 'kayzart.settingsPanelState';
const LEGACY_PANEL_WIDTH_KEY = 'kayzart.settingsPanelWidth';

const resolveStorage = (provided: Storage | null | undefined): Storage | null => {
  if (provided !== undefined) return provided;
  try {
    return window.localStorage;
  } catch {
    return null;
  }
};

const normalizeTab = (value: unknown, aiEnabled: boolean) => {
  if (typeof value !== 'string' || !value) {
    return 'elements';
  }
  return !aiEnabled && value === 'kayzart-ai' ? 'elements' : value;
};

const normalizeWidth = (value: unknown): number | undefined => {
  const width = typeof value === 'number' ? value : Number.parseFloat(String(value || ''));
  return Number.isFinite(width) && width > 0 ? Math.round(width) : undefined;
};

const parseState = (
  value: string | null,
  defaults: EditorLayoutState,
  aiEnabled: boolean
): EditorLayoutState | null => {
  if (!value) return null;
  try {
    const parsed = JSON.parse(value) as Partial<EditorLayoutState> | null;
    if (!parsed || parsed.version !== 1) return null;
    return {
      version: 1,
      settingsOpen:
        typeof parsed.settingsOpen === 'boolean' ? parsed.settingsOpen : defaults.settingsOpen,
      settingsTab: normalizeTab(parsed.settingsTab, aiEnabled),
      editorCollapsed:
        typeof parsed.editorCollapsed === 'boolean'
          ? parsed.editorCollapsed
          : defaults.editorCollapsed,
      settingsWidth: normalizeWidth(parsed.settingsWidth),
    };
  } catch {
    return null;
  }
};

export const getEditorLayoutKind = (width: number): EditorLayoutKind =>
  width < 900 ? 'compact' : 'desktop';

export const resolveInitialEditorLayout = (
  persisted: EditorLayoutState,
  entryMode: InitialEntryMode
): EditorLayoutState => {
  if (entryMode === 'ai') {
    return { ...persisted, editorCollapsed: true, settingsOpen: true, settingsTab: 'kayzart-ai' };
  }
  if (entryMode === 'blank') {
    return { ...persisted, editorCollapsed: false, settingsOpen: false };
  }
  return { ...persisted };
};

export function createEditorLayoutStore(options: LayoutStoreOptions) {
  const storage = resolveStorage(options.storage);
  const key = `${options.namespace}.${options.kind}`;
  const defaults: EditorLayoutState = {
    version: 1,
    settingsOpen: false,
    settingsTab: 'elements',
    editorCollapsed: options.fallbackEditorCollapsed,
  };

  const migrateLegacyDesktopState = (): EditorLayoutState | null => {
    if (!storage || options.kind !== 'desktop') return null;
    const legacyPanel = storage.getItem(LEGACY_PANEL_STATE_KEY);
    const legacyWidth = storage.getItem(LEGACY_PANEL_WIDTH_KEY);
    if (!legacyPanel && !legacyWidth) return null;

    let panel: { open?: unknown; tab?: unknown } = {};
    try {
      panel = JSON.parse(legacyPanel || '{}') || {};
    } catch {
      panel = {};
    }
    const migrated: EditorLayoutState = {
      ...defaults,
      settingsOpen: typeof panel.open === 'boolean' ? panel.open : defaults.settingsOpen,
      settingsTab: normalizeTab(panel.tab, options.aiEnabled),
      settingsWidth: normalizeWidth(legacyWidth),
    };
    storage.setItem(key, JSON.stringify(migrated));
    storage.removeItem(LEGACY_PANEL_STATE_KEY);
    storage.removeItem(LEGACY_PANEL_WIDTH_KEY);
    return migrated;
  };

  const read = (): EditorLayoutState => {
    if (!storage) return { ...defaults };
    try {
      const stored = parseState(storage.getItem(key), defaults, options.aiEnabled);
      const resolved = stored ?? migrateLegacyDesktopState() ?? { ...defaults };
      if (!stored && !storage.getItem(key)) {
        storage.setItem(key, JSON.stringify(resolved));
      }
      return resolved;
    } catch {
      return { ...defaults };
    }
  };

  const write = (state: EditorLayoutState) => {
    const normalized: EditorLayoutState = {
      version: 1,
      settingsOpen: Boolean(state.settingsOpen),
      settingsTab: normalizeTab(state.settingsTab, options.aiEnabled),
      editorCollapsed: Boolean(state.editorCollapsed),
      settingsWidth: normalizeWidth(state.settingsWidth),
    };
    try {
      storage?.setItem(key, JSON.stringify(normalized));
    } catch {
      // Storage can be unavailable in private or restricted browser contexts.
    }
    return normalized;
  };

  return { key, read, write };
}
