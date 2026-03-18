export type ExternalSettingsTabMount = (
  container: HTMLElement
) => void | (() => void);

export type ExternalSettingsTab = {
  id: string;
  label: string;
  order?: number;
  mount: ExternalSettingsTabMount;
};

export type ResolvedExternalSettingsTab = ExternalSettingsTab & {
  order: number;
};

type RegistryEntry = {
  tab: ResolvedExternalSettingsTab;
  sequence: number;
};

type Listener = () => void;
type RegisterSettingsTab = (tab: ExternalSettingsTab) => () => void;

export type ContextKey =
  | 'selected_element'
  | 'document_html'
  | 'document_css'
  | 'document_js';

export type ContextSnapshot = {
  selectedElement?: {
    cssSelector?: string;
    htmlSnippet?: string;
    textSnippet?: string;
  };
  document?: {
    html?: string;
    css?: string;
    js?: string;
  };
};

export type ProposedEditTarget = 'html' | 'css' | 'js';
export type ProposedEditOperation = 'replace_full' | 'replace_range';

export type ProposedEdit = {
  target: ProposedEditTarget;
  operation: ProposedEditOperation;
  content: string;
  range?: {
    startOffset: number;
    endOffset: number;
  };
  summary?: string;
};

export type ApplyProposedEditOptions = {
  expectedBefore?: string;
};

export type EditActionErrorCode =
  | 'INVALID_REQUEST'
  | 'INVALID_RANGE'
  | 'STALE_RANGE'
  | 'STALE_UNDO'
  | 'HANDLE_NOT_FOUND'
  | 'INTERNAL_ERROR';

export type ApplyProposedEditResult =
  | {
      ok: true;
      appliedHandle: string;
    }
  | {
      ok: false;
      code: EditActionErrorCode;
      message: string;
    };

export type UndoProposedEditResult =
  | {
      ok: true;
    }
  | {
      ok: false;
      code: EditActionErrorCode;
      message: string;
    };

type GetContextSnapshot = (includeKeys?: ContextKey[]) => ContextSnapshot;
type ApplyProposedEdit = (
  edit: ProposedEdit,
  options?: ApplyProposedEditOptions
) => ApplyProposedEditResult;
type UndoProposedEdit = (appliedHandle: string) => UndoProposedEditResult;

type KayzArtExtensionApi = {
  registerSettingsTab: RegisterSettingsTab;
  getContextSnapshot: GetContextSnapshot;
  applyProposedEdit: ApplyProposedEdit;
  undoProposedEdit: UndoProposedEdit;
};

const RESERVED_TAB_IDS = new Set(['settings', 'elements']);
const DEFAULT_ORDER = 100;

const registry = new Map<string, RegistryEntry>();
const listeners = new Set<Listener>();
let contextSnapshotProvider: GetContextSnapshot | null = null;
let applyProposedEditProvider: ApplyProposedEdit | null = null;
let undoProposedEditProvider: UndoProposedEdit | null = null;
let sequenceCounter = 0;
let initialized = false;

declare global {
  interface Window {
    KAYZART_EXTENSION_API?: KayzArtExtensionApi;
    KAYZART_SETTINGS_TAB_QUEUE?: unknown[];
  }
}

function warnInvalidTab(message: string, tab: unknown) {
  // eslint-disable-next-line no-console
  console.warn(`[KayzArt] ${message}`, tab);
}

function normalizeTab(tab: ExternalSettingsTab): ResolvedExternalSettingsTab | null {
  if (!tab || typeof tab !== 'object') {
    warnInvalidTab('Rejected external settings tab: expected object.', tab);
    return null;
  }

  const id = typeof tab.id === 'string' ? tab.id.trim() : '';
  if (!id) {
    warnInvalidTab('Rejected external settings tab: id is required.', tab);
    return null;
  }
  if (RESERVED_TAB_IDS.has(id)) {
    warnInvalidTab(`Rejected external settings tab: "${id}" is reserved.`, tab);
    return null;
  }

  const label = typeof tab.label === 'string' ? tab.label.trim() : '';
  if (!label) {
    warnInvalidTab('Rejected external settings tab: label is required.', tab);
    return null;
  }

  if (typeof tab.mount !== 'function') {
    warnInvalidTab('Rejected external settings tab: mount() is required.', tab);
    return null;
  }

  const resolvedOrder =
    typeof tab.order === 'number' && Number.isFinite(tab.order)
      ? tab.order
      : DEFAULT_ORDER;

  return {
    id,
    label,
    order: resolvedOrder,
    mount: tab.mount,
  };
}

function notifyListeners() {
  listeners.forEach((listener) => listener());
}

function registerSettingsTabInternal(tab: ExternalSettingsTab): () => void {
  const normalized = normalizeTab(tab);
  if (!normalized) {
    return () => {};
  }

  if (registry.has(normalized.id)) {
    warnInvalidTab(
      `Ignored external settings tab: duplicate id "${normalized.id}".`,
      normalized
    );
    return () => {};
  }

  const entry: RegistryEntry = {
    tab: normalized,
    sequence: sequenceCounter++,
  };
  registry.set(normalized.id, entry);
  notifyListeners();

  let active = true;
  return () => {
    if (!active) {
      return;
    }
    active = false;
    const current = registry.get(normalized.id);
    if (current !== entry) {
      return;
    }
    registry.delete(normalized.id);
    notifyListeners();
  };
}

function flushQueuedTabs(registerSettingsTab: RegisterSettingsTab) {
  const queue = Array.isArray(window.KAYZART_SETTINGS_TAB_QUEUE)
    ? [...window.KAYZART_SETTINGS_TAB_QUEUE]
    : [];
  window.KAYZART_SETTINGS_TAB_QUEUE = [];
  queue.forEach((entry) => {
    registerSettingsTab(entry as ExternalSettingsTab);
  });
}

function getContextSnapshotInternal(includeKeys?: ContextKey[]): ContextSnapshot {
  if (!contextSnapshotProvider) {
    return {};
  }
  return contextSnapshotProvider(includeKeys);
}

function applyProposedEditInternal(
  edit: ProposedEdit,
  options?: ApplyProposedEditOptions
): ApplyProposedEditResult {
  if (!applyProposedEditProvider) {
    return {
      ok: false,
      code: 'INTERNAL_ERROR',
      message: 'edit handlers are not available.',
    };
  }
  return applyProposedEditProvider(edit, options);
}

function undoProposedEditInternal(appliedHandle: string): UndoProposedEditResult {
  if (!undoProposedEditProvider) {
    return {
      ok: false,
      code: 'INTERNAL_ERROR',
      message: 'edit handlers are not available.',
    };
  }
  return undoProposedEditProvider(appliedHandle);
}

export function ensureSettingsTabRegistryApi() {
  if (initialized) {
    return;
  }
  initialized = true;

  const registerSettingsTab: RegisterSettingsTab = (tab) =>
    registerSettingsTabInternal(tab);
  window.KAYZART_EXTENSION_API = {
    ...(window.KAYZART_EXTENSION_API || {}),
    registerSettingsTab,
    getContextSnapshot: getContextSnapshotInternal,
    applyProposedEdit: applyProposedEditInternal,
    undoProposedEdit: undoProposedEditInternal,
  };
  flushQueuedTabs(registerSettingsTab);
}

export function setContextSnapshotProvider(provider: GetContextSnapshot | null) {
  contextSnapshotProvider = provider;
  if (!window.KAYZART_EXTENSION_API) {
    return;
  }
  window.KAYZART_EXTENSION_API = {
    ...window.KAYZART_EXTENSION_API,
    getContextSnapshot: getContextSnapshotInternal,
  };
}

export function setProposedEditHandlers(
  handlers:
    | {
        applyProposedEdit: ApplyProposedEdit;
        undoProposedEdit: UndoProposedEdit;
      }
    | null
) {
  applyProposedEditProvider = handlers?.applyProposedEdit || null;
  undoProposedEditProvider = handlers?.undoProposedEdit || null;

  if (!window.KAYZART_EXTENSION_API) {
    return;
  }

  window.KAYZART_EXTENSION_API = {
    ...window.KAYZART_EXTENSION_API,
    applyProposedEdit: applyProposedEditInternal,
    undoProposedEdit: undoProposedEditInternal,
  };
}

export function getExternalSettingsTabs(): ResolvedExternalSettingsTab[] {
  ensureSettingsTabRegistryApi();
  return Array.from(registry.values())
    .sort((left, right) => {
      if (left.tab.order !== right.tab.order) {
        return left.tab.order - right.tab.order;
      }
      return left.sequence - right.sequence;
    })
    .map((entry) => ({ ...entry.tab }));
}

export function subscribeExternalSettingsTabs(listener: Listener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

ensureSettingsTabRegistryApi();
