import {
  Fragment,
  createElement,
  createRoot,
  useEffect,
  useMemo,
  useRef,
  useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type {
  ActiveJobRecord,
  AiAvailability,
  AiJobEvent,
  AiJobStatusResponse,
  ChangedTarget,
  EditorSnapshot,
  SelectedElementContext,
} from './contract';
import { normalizeSnapshot } from './contract';
import { AiApiError, cancelJob, createJob, getJob } from './api';
import {
  DEFAULT_POLL_INTERVAL_MS,
  DEFAULT_TIMEOUT_MS,
  computeChangedTargets,
  isTerminalStatus,
  positiveInteger,
  sleep,
} from './polling';
import { clearActiveJob, loadActiveJob, saveActiveJob } from './session';
import './style.css';

const AI_TAB_ID = 'kayzart-ai';
const TOOLBAR_ACTION_ID = 'kayzart-toolbar-ai-edit';
const PREVIEW_ACTION_EVENT = 'kayzart-preview-overlay-action';
const PREVIEW_ACTION_ID = 'kayzart-ai-edit-context';
const CONTEXT_SYNC_EVENT = 'kayzart-ai-context-sync';
const MAX_PROMPT_BYTES = 8192;
const MAX_CONTEXTS = 20;
const MAX_MESSAGES = 100;
const MAX_RESULTS = 20;
const ELEMENTS_PANEL_SELECTOR = '[data-kayzart-panel="elements"]';
const ELEMENTS_BUTTON_CLASS = 'kayzart-ai-elements-button';

type UserMessage = { id: string; role: 'user'; text: string; contexts: SelectedElementContext[] };
type ResultMessage = {
  id: string;
  role: 'result';
  summary: string;
  changedTargets: ChangedTarget[];
  before: EditorSnapshot;
  after: EditorSnapshot;
};
type ChatMessage = UserMessage | ResultMessage;

declare global {
  interface Window {
    __KAYZART_AI_TAB_UNREGISTER__?: () => void;
    __KAYZART_AI_TOOLBAR_UNREGISTER__?: () => void;
  }
}

const conversation = {
  messages: [] as ChatMessage[],
  results: [] as ResultMessage[],
  contexts: [] as SelectedElementContext[],
};
const pendingContexts = new Map<string, SelectedElementContext>();
let promptFocusRequested = false;

function id(prefix: string) {
  const uuid = typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `${prefix}-${uuid}`;
}

function config(): AiAvailability | undefined {
  return window.KAYZART.ai;
}

function host() {
  return window.KAYZART_EXTENSION_API;
}

function mergeContexts(current: SelectedElementContext[], incoming: SelectedElementContext[]) {
  const next = [...current];
  incoming.forEach((context) => {
    const index = next.findIndex((item) => item.lcId === context.lcId);
    if (index >= 0) next[index] = context;
    else if (next.length < MAX_CONTEXTS) next.push(context);
  });
  return next.slice(-MAX_CONTEXTS);
}

function queueSelectedContext() {
  const context = host()?.getSelectedContext?.();
  if (!context?.lcId) return false;
  pendingContexts.set(context.lcId, context);
  window.dispatchEvent(new CustomEvent(CONTEXT_SYNC_EVENT));
  return true;
}

function openAi(includeContext = false) {
  if (includeContext) queueSelectedContext();
  promptFocusRequested = true;
  host()?.openSettingsTab?.(AI_TAB_ID);
}

function normalizeUrl(url: string) {
  try {
    return new URL(url, window.location.origin).toString();
  } catch {
    return url;
  }
}

function eventLabel(event: AiJobEvent) {
  if (event.event === 'progress') return event.message || __('Working…', 'kayzart-live-code-editor');
  if (event.event === 'tool_start') {
    return sprintf(__('Running %s', 'kayzart-live-code-editor'), event.toolName || __('tool', 'kayzart-live-code-editor'));
  }
  if (event.event === 'tool_end') {
    return sprintf(__('Finished %s', 'kayzart-live-code-editor'), event.toolName || __('tool', 'kayzart-live-code-editor'));
  }
  if (event.event === 'final') return event.summary || __('AI edit completed.', 'kayzart-live-code-editor');
  return event.message || event.event;
}

function contextLabel(context: SelectedElementContext) {
  return context.tagName ? `<${context.tagName.toLowerCase()}>` : context.lcId;
}

function AvailabilityNotice({ ai }: { ai: AiAvailability }) {
  if (ai.available) return null;
  let title: string = __('AI editing is unavailable', 'kayzart-live-code-editor');
  let message: string = __('Ask the site administrator to check the AI configuration.', 'kayzart-live-code-editor');
  if (!ai.featureEnabled) {
    message = __('AI editing has been disabled by site policy.', 'kayzart-live-code-editor');
  } else if (!ai.sdkPresent) {
    message = __('The WordPress AI Client could not be loaded.', 'kayzart-live-code-editor');
  } else if (!ai.schedulerPresent) {
    message = __('The background job scheduler could not be loaded.', 'kayzart-live-code-editor');
  } else if (!ai.providerConfigured) {
    title = __('Connect an AI provider', 'kayzart-live-code-editor');
    message = ai.canManageConnectors
      ? __('Connect an AI provider before sending an edit.', 'kayzart-live-code-editor')
      : __('Ask an administrator to configure an AI provider.', 'kayzart-live-code-editor');
  }
  return (
    <div className="kayzart-ai-notice" role="status">
      <strong>{title}</strong>
      <p>{message}</p>
      {!ai.providerConfigured && ai.canManageConnectors && ai.connectorsUrl ? (
        <a href={ai.connectorsUrl}>{__('Open Connectors', 'kayzart-live-code-editor')}</a>
      ) : null}
    </div>
  );
}

export function AiEditorPanel() {
  const ai = config();
  const postId = Number(window.KAYZART.post_id || 0);
  const nonce = window.KAYZART.restNonce || '';
  const promptRef = useRef<HTMLTextAreaElement | null>(null);
  const pollAbortRef = useRef<AbortController | null>(null);
  const mountedRef = useRef(true);
  const [prompt, setPrompt] = useState('');
  const [contexts, setContexts] = useState<SelectedElementContext[]>(conversation.contexts);
  const [messages, setMessages] = useState<ChatMessage[]>(conversation.messages);
  const [events, setEvents] = useState<AiJobEvent[]>([]);
  const [running, setRunning] = useState(false);
  const [canceling, setCanceling] = useState(false);
  const [error, setError] = useState('');

  const updateMessages = (updater: (current: ChatMessage[]) => ChatMessage[]) => {
    setMessages((current) => {
      const next = updater(current).slice(-MAX_MESSAGES);
      conversation.messages = next;
      return next;
    });
  };

  const updateContexts = (next: SelectedElementContext[]) => {
    const limited = next.slice(-MAX_CONTEXTS);
    conversation.contexts = limited;
    setContexts(limited);
  };

  const finish = () => {
    clearActiveJob(postId);
    setRunning(false);
    setCanceling(false);
    host()?.setEditorLock?.(false);
  };

  const complete = (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (!status.snapshot) {
      setError(__('AI response is missing its snapshot.', 'kayzart-live-code-editor'));
      finish();
      return;
    }
    const output = normalizeSnapshot(status.snapshot);
    const finalEvent = [...status.events].reverse().find((item) => item.event === 'final');
    const result: ResultMessage = {
      id: id('result'),
      role: 'result',
      summary: finalEvent?.summary || __('AI edit completed.', 'kayzart-live-code-editor'),
      changedTargets: computeChangedTargets(active.inputSnapshot, output),
      before: active.inputSnapshot,
      after: output,
    };
    host()?.replaceEditorSnapshot?.(output);
    conversation.results = [...conversation.results, result].slice(-MAX_RESULTS);
    updateMessages((current) => [...current, result]);
    finish();
  };

  const handleTerminal = (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (status.status === 'completed') {
      complete(status, active);
      return;
    }
    const fallback = status.status === 'canceled'
      ? __('AI edit was canceled.', 'kayzart-live-code-editor')
      : status.status === 'timed_out'
        ? __('AI edit timed out.', 'kayzart-live-code-editor')
        : status.status === 'enqueue_failed'
          ? __('AI edit could not be scheduled.', 'kayzart-live-code-editor')
          : __('AI edit failed.', 'kayzart-live-code-editor');
    setError(status.error?.message || fallback);
    finish();
  };

  const poll = async (active: ActiveJobRecord) => {
    pollAbortRef.current?.abort();
    const controller = new AbortController();
    pollAbortRef.current = controller;
    setRunning(true);
    host()?.setEditorLock?.(true);
    const interval = positiveInteger(active.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS);
    try {
      for (;;) {
        let status: AiJobStatusResponse;
        try {
          status = await getJob(active.statusUrl, nonce, controller.signal);
          if (mountedRef.current) setError('');
        } catch (caught) {
          if (caught instanceof DOMException && caught.name === 'AbortError') throw caught;
          if (mountedRef.current) {
            setError(__('Connection lost. Retrying the AI job status…', 'kayzart-live-code-editor'));
          }
          await sleep(interval, controller.signal);
          continue;
        }
        if (!mountedRef.current) return;
        setEvents(Array.isArray(status.events) ? status.events : []);
        if (isTerminalStatus(status.status)) {
          handleTerminal(status, active);
          return;
        }
        await sleep(interval, controller.signal);
      }
    } catch (caught) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return;
      if (!mountedRef.current) return;
      setError(caught instanceof Error ? caught.message : __('AI edit failed.', 'kayzart-live-code-editor'));
      setRunning(Boolean(loadActiveJob(postId)));
    }
  };

  useEffect(() => {
    mountedRef.current = true;
    const syncContexts = () => {
      const queued = Array.from(pendingContexts.values());
      pendingContexts.clear();
      if (queued.length) updateContexts(mergeContexts(conversation.contexts, queued));
      if (promptFocusRequested) {
        promptFocusRequested = false;
        window.requestAnimationFrame(() => promptRef.current?.focus());
      }
    };
    window.addEventListener(CONTEXT_SYNC_EVENT, syncContexts);
    syncContexts();
    const active = loadActiveJob(postId);
    if (active) {
      if (!conversation.messages.some((message) => message.role === 'user' && message.id === active.requestId)) {
        updateMessages((current) => [
          ...current,
          { id: active.requestId, role: 'user', text: active.prompt, contexts: active.contexts },
        ]);
      }
      void poll(active);
    }
    return () => {
      mountedRef.current = false;
      window.removeEventListener(CONTEXT_SYNC_EVENT, syncContexts);
      pollAbortRef.current?.abort();
      pollAbortRef.current = null;
      // Keep the editor locked while the server job remains active.
      if (!loadActiveJob(postId)) host()?.setEditorLock?.(false);
    };
  }, []);

  const promptBytes = useMemo(() => new TextEncoder().encode(prompt.trim()).length, [prompt]);
  const canSend = Boolean(ai?.available && !running && prompt.trim() && promptBytes <= MAX_PROMPT_BYTES);

  const send = async () => {
    if (!canSend || !ai) return;
    setError('');
    setEvents([]);
    const snapshot = host()?.getEditorSnapshot?.();
    const editorMode = host()?.getEditorMode?.();
    if (!snapshot || !editorMode) {
      setError(__('Editor state is unavailable.', 'kayzart-live-code-editor'));
      return;
    }
    const input = normalizeSnapshot(snapshot);
    const promptText = prompt.trim();
    const submittedContexts = [...contexts];
    const requestId = id('request');
    updateMessages((current) => [
      ...current,
      { id: requestId, role: 'user', text: promptText, contexts: submittedContexts },
    ]);
    setPrompt('');
    updateContexts([]);
    setRunning(true);
    host()?.setEditorLock?.(true);
    try {
      const created = await createJob(ai.jobsUrl, nonce, {
        ...input,
        requestId,
        post_id: postId,
        editorMode,
        prompt: promptText,
        selectedContexts: submittedContexts.length ? submittedContexts : undefined,
      });
      const active: ActiveJobRecord = {
        version: 1,
        postId,
        jobId: created.jobId,
        requestId: created.requestId,
        statusUrl: normalizeUrl(created.statusUrl),
        cancelUrl: normalizeUrl(created.cancelUrl),
        pollIntervalMs: positiveInteger(created.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS),
        timeoutMs: positiveInteger(created.timeoutMs, DEFAULT_TIMEOUT_MS),
        startedAt: Date.now(),
        prompt: promptText,
        contexts: submittedContexts,
        inputSnapshot: input,
      };
      saveActiveJob(active);
      await poll(active);
    } catch (caught) {
      const message = caught instanceof AiApiError || caught instanceof Error
        ? caught.message
        : __('AI edit failed.', 'kayzart-live-code-editor');
      setError(message);
      finish();
    }
  };

  const stop = async () => {
    const active = loadActiveJob(postId);
    if (!active || canceling) return;
    setCanceling(true);
    try {
      const status = await cancelJob(active.cancelUrl, nonce);
      setEvents(Array.isArray(status.events) ? status.events : []);
      if (isTerminalStatus(status.status)) handleTerminal(status, active);
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : __('Cancel request failed.', 'kayzart-live-code-editor'));
      setCanceling(false);
    }
  };

  return (
    <div className="kayzart-ai-panel">
      {ai ? <AvailabilityNotice ai={ai} /> : null}
      {error ? <div className="kayzart-ai-error" role="alert">{error}</div> : null}
      <div className="kayzart-ai-chat" role="log" aria-live="polite">
        {!messages.length ? <p className="kayzart-ai-empty">{__('Describe the landing page change you want.', 'kayzart-live-code-editor')}</p> : null}
        {messages.map((message) => message.role === 'user' ? (
          <div className="kayzart-ai-message kayzart-ai-message-user" key={message.id}>
            <p>{message.text}</p>
            {message.contexts.length ? <small>{message.contexts.map(contextLabel).join(', ')}</small> : null}
          </div>
        ) : (
          <div className="kayzart-ai-result" key={message.id}>
            <strong>{message.summary}</strong>
            <div className="kayzart-ai-targets">
              {message.changedTargets.map((target) => <span key={target}>{target.toUpperCase()}</span>)}
            </div>
            <div className="kayzart-ai-result-actions">
              <button type="button" onClick={() => host()?.replaceEditorSnapshot?.(message.before)}>
                {__('Revert to before applying', 'kayzart-live-code-editor')}
              </button>
              <button type="button" onClick={() => host()?.replaceEditorSnapshot?.(message.after)}>
                {__('Reapply proposal', 'kayzart-live-code-editor')}
              </button>
            </div>
          </div>
        ))}
      </div>
      {running ? (
        <div className="kayzart-ai-activity">
          <strong>{canceling ? __('Canceling…', 'kayzart-live-code-editor') : __('AI is editing…', 'kayzart-live-code-editor')}</strong>
          <ul>{events.map((event, index) => <li key={`${event.requestId}-${index}`}>{eventLabel(event)}</li>)}</ul>
        </div>
      ) : null}
      <div className="kayzart-ai-composer">
        {contexts.length ? (
          <div className="kayzart-ai-contexts">
            {contexts.map((context) => (
              <span key={context.lcId}>
                {contextLabel(context)}
                <button type="button" disabled={running} onClick={() => updateContexts(contexts.filter((item) => item.lcId !== context.lcId))} aria-label={__('Remove context', 'kayzart-live-code-editor')}>×</button>
              </span>
            ))}
          </div>
        ) : null}
        <textarea
          ref={promptRef}
          value={prompt}
          rows={4}
          disabled={running || !ai?.available}
          maxLength={MAX_PROMPT_BYTES}
          onChange={(event) => setPrompt(event.currentTarget.value)}
          placeholder={__('Example: Make the hero clearer and improve the primary button.', 'kayzart-live-code-editor')}
        />
        <div className="kayzart-ai-composer-footer">
          <small className={promptBytes > MAX_PROMPT_BYTES ? 'is-error' : ''}>{promptBytes}/{MAX_PROMPT_BYTES} bytes</small>
          <button type="button" className={running ? 'is-stop' : ''} disabled={running ? canceling : !canSend} onClick={running ? () => void stop() : () => void send()}>
            {running ? __('Stop', 'kayzart-live-code-editor') : __('Send', 'kayzart-live-code-editor')}
          </button>
        </div>
      </div>
    </div>
  );
}

function registerTab() {
  const tab = {
    id: AI_TAB_ID,
    label: __('AI Edit', 'kayzart-live-code-editor'),
    order: 10,
    mount: (container: HTMLElement) => {
      const root = createRoot(container);
      root.render(<AiEditorPanel />);
      return () => root.unmount();
    },
  };
  const register = host()?.registerSettingsTab;
  if (typeof register === 'function') window.__KAYZART_AI_TAB_UNREGISTER__ = register(tab);
  else {
    const queue = Array.isArray(window.KAYZART_SETTINGS_TAB_QUEUE) ? window.KAYZART_SETTINGS_TAB_QUEUE : [];
    queue.push(tab);
    window.KAYZART_SETTINGS_TAB_QUEUE = queue;
  }
}

function registerToolbar() {
  const action = {
    id: TOOLBAR_ACTION_ID,
    label: __('AI Edit', 'kayzart-live-code-editor'),
    tooltip: __('Edit with AI', 'kayzart-live-code-editor'),
    order: 10,
    placement: 'before-settings' as const,
    icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 15.5A2 2 0 0 0 8.5 14L2.4 12.5a.5.5 0 0 1 0-1L8.5 10A2 2 0 0 0 10 8.5l1.5-6.1a.5.5 0 0 1 1 0L14 8.5a2 2 0 0 0 1.5 1.5l6.1 1.5a.5.5 0 0 1 0 1L15.5 14a2 2 0 0 0-1.5 1.5l-1.5 6.1a.5.5 0 0 1-1 0z"/></svg>',
    onClick: () => openAi(false),
  };
  const register = host()?.registerToolbarAction;
  if (typeof register === 'function') window.__KAYZART_AI_TOOLBAR_UNREGISTER__ = register(action);
  else {
    const queue = Array.isArray(window.KAYZART_TOOLBAR_ACTION_QUEUE) ? window.KAYZART_TOOLBAR_ACTION_QUEUE : [];
    queue.push(action);
    window.KAYZART_TOOLBAR_ACTION_QUEUE = queue;
  }
}

function installContextEntrypoints() {
  window.addEventListener(PREVIEW_ACTION_EVENT, (raw) => {
    const event = raw as CustomEvent<{ actionId?: string }>;
    if (event.detail?.actionId === PREVIEW_ACTION_ID) openAi(true);
  });
  const refresh = () => {
    const panel = document.querySelector<HTMLElement>(ELEMENTS_PANEL_SELECTOR);
    if (!panel || panel.querySelector(`.${ELEMENTS_BUTTON_CLASS}`) || !config()?.available) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `kayzart-btn kayzart-btn-secondary ${ELEMENTS_BUTTON_CLASS}`;
    button.textContent = __('Edit with AI', 'kayzart-live-code-editor');
    button.addEventListener('click', () => openAi(true));
    panel.append(button);
  };
  new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true });
  refresh();
}

registerTab();
registerToolbar();
installContextEntrypoints();

const restored = loadActiveJob(Number(window.KAYZART.post_id || 0));
if (restored) {
  host()?.setEditorLock?.(true);
  window.requestAnimationFrame(() => openAi(false));
}
