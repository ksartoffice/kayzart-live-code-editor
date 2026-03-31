import type {
  KayzArtExtensionApi,
  RegisterToolbarAction,
  ToolbarAction,
  ToolbarActionPlacement,
} from './settings-tab-registry';

export type ResolvedToolbarAction = ToolbarAction & {
  order: number;
  placement: ToolbarActionPlacement;
};

type RegistryEntry = {
  action: ResolvedToolbarAction;
  sequence: number;
};

type Listener = () => void;

const DEFAULT_ORDER = 100;
const DEFAULT_PLACEMENT: ToolbarActionPlacement = 'before-settings';
const VALID_PLACEMENTS = new Set<ToolbarActionPlacement>([
  'before-settings',
  'after-settings',
]);
const PLACEMENT_RANK: Record<ToolbarActionPlacement, number> = {
  'before-settings': 0,
  'after-settings': 1,
};

const registry = new Map<string, RegistryEntry>();
const listeners = new Set<Listener>();
let sequenceCounter = 0;
let initialized = false;

declare global {
  interface Window {
    KAYZART_EXTENSION_API?: KayzArtExtensionApi;
    KAYZART_TOOLBAR_ACTION_QUEUE?: unknown[];
  }
}

function warnInvalidAction(message: string, action: unknown) {
  // eslint-disable-next-line no-console
  console.warn(`[KayzArt] ${message}`, action);
}

function normalizeAction(action: ToolbarAction): ResolvedToolbarAction | null {
  if (!action || typeof action !== 'object') {
    warnInvalidAction('Rejected toolbar action: expected object.', action);
    return null;
  }

  const id = typeof action.id === 'string' ? action.id.trim() : '';
  if (!id) {
    warnInvalidAction('Rejected toolbar action: id is required.', action);
    return null;
  }

  const label = typeof action.label === 'string' ? action.label.trim() : '';
  if (!label) {
    warnInvalidAction('Rejected toolbar action: label is required.', action);
    return null;
  }

  if (typeof action.onClick !== 'function') {
    warnInvalidAction('Rejected toolbar action: onClick() is required.', action);
    return null;
  }

  const placement =
    typeof action.placement === 'string' &&
    VALID_PLACEMENTS.has(action.placement as ToolbarActionPlacement)
      ? (action.placement as ToolbarActionPlacement)
      : DEFAULT_PLACEMENT;

  const order =
    typeof action.order === 'number' && Number.isFinite(action.order)
      ? action.order
      : DEFAULT_ORDER;

  return {
    ...action,
    id,
    label,
    tooltip: typeof action.tooltip === 'string' ? action.tooltip : '',
    className: typeof action.className === 'string' ? action.className : '',
    icon: typeof action.icon === 'string' ? action.icon : '',
    placement,
    order,
  };
}

function notifyListeners() {
  listeners.forEach((listener) => listener());
}

function registerToolbarActionInternal(action: ToolbarAction): () => void {
  const normalized = normalizeAction(action);
  if (!normalized) {
    return () => {};
  }

  if (registry.has(normalized.id)) {
    warnInvalidAction(
      `Ignored toolbar action: duplicate id "${normalized.id}".`,
      normalized
    );
    return () => {};
  }

  const entry: RegistryEntry = {
    action: normalized,
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

function flushQueuedActions(registerToolbarAction: RegisterToolbarAction) {
  const queue = Array.isArray(window.KAYZART_TOOLBAR_ACTION_QUEUE)
    ? [...window.KAYZART_TOOLBAR_ACTION_QUEUE]
    : [];
  window.KAYZART_TOOLBAR_ACTION_QUEUE = [];
  queue.forEach((entry) => {
    registerToolbarAction(entry as ToolbarAction);
  });
}

export function ensureToolbarActionRegistryApi() {
  if (initialized) {
    return;
  }
  initialized = true;

  const registerToolbarAction: RegisterToolbarAction = (action) =>
    registerToolbarActionInternal(action);

  window.KAYZART_EXTENSION_API = {
    ...(window.KAYZART_EXTENSION_API || {}),
    registerToolbarAction,
  };

  flushQueuedActions(registerToolbarAction);
}

export function getExternalToolbarActions(): ResolvedToolbarAction[] {
  ensureToolbarActionRegistryApi();
  return Array.from(registry.values())
    .sort((left, right) => {
      if (left.action.placement !== right.action.placement) {
        return PLACEMENT_RANK[left.action.placement] - PLACEMENT_RANK[right.action.placement];
      }
      if (left.action.order !== right.action.order) {
        return left.action.order - right.action.order;
      }
      return left.sequence - right.sequence;
    })
    .map((entry) => ({ ...entry.action }));
}

export function subscribeExternalToolbarActions(listener: Listener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

ensureToolbarActionRegistryApi();
