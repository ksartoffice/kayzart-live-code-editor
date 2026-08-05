import { createElement, Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import type {
  ActiveJobRecord, AiAvailability, AiJobEvent, AiJobStatus, AiJobStatusResponse, AiTimelineItem,
  EditorSnapshot, SelectedElementContext,
} from './contract';
import { normalizeSnapshot } from './contract';
import {
  AiApiError, cancelJob, createJob, getJob, getTimeline, getTimelineSnapshot,
  restoreTimeline, updateTimelineApplication,
} from './api';
import { DEFAULT_POLL_INTERVAL_MS, DEFAULT_TIMEOUT_MS, isRetryableHttpStatus, isTerminalStatus, positiveInteger, sameSnapshotIdentity, sleep } from './polling';
import { clearActiveJob, loadActiveJob, saveActiveJob } from './session';
import './style.css';

const AI_TAB_ID = 'kayzart-ai';
const TOOLBAR_ACTION_ID = 'kayzart-toolbar-ai-edit';
const PREVIEW_ACTION_EVENT = 'kayzart-preview-overlay-action';
const PREVIEW_ACTION_ID = 'kayzart-ai-edit-context';
const CONTEXT_SYNC_EVENT = 'kayzart-ai-context-sync';
const SAVE_EVENT = 'kayzart-editor-saved';
const DEFAULT_MAX_PROMPT_CHARS = 8000;
const MAX_CONTEXTS = 20;
const ELEMENTS_PANEL_SELECTOR = '[data-kayzart-panel="elements"]';
const ELEMENTS_BUTTON_CLASS = 'kayzart-ai-elements-button';

type PendingConflict = {
  output: EditorSnapshot;
  activityId?: number;
};

type InitialRequestAttempt = {
  initialRequestId: string;
  requestId: string;
  terminal: boolean;
};

const draftState = { prompt: '', contexts: [] as SelectedElementContext[] };
const pendingContexts = new Map<string, SelectedElementContext>();
const initialRequestAttempts = new Map<number, InitialRequestAttempt>();
const TERMINAL_INITIAL_REQUEST_CODES = new Set([
  'kayzart_ai_timeline_create_failed',
  'kayzart_ai_enqueue_failed',
  'kayzart_ai_initial_request_terminal',
]);
let promptFocusRequested = false;

function makeId(prefix: string) {
  const value = typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `${prefix}-${value}`;
}

function config(): AiAvailability | undefined { return window.KAYZART.ai; }
function host() { return window.KAYZART_EXTENSION_API; }
function getInitialRequestAttempt(postId: number) {
  const ai = config();
  const initialRequest = ai?.initialRequest;
  if (!initialRequest) {
    initialRequestAttempts.delete(postId);
    return null;
  }
  const current = initialRequestAttempts.get(postId);
  if (current?.initialRequestId === initialRequest.requestId) return current;
  const attempt = { initialRequestId: initialRequest.requestId, requestId: initialRequest.requestId, terminal: false };
  initialRequestAttempts.set(postId, attempt);
  return attempt;
}
function clearRuntimeInitialRequest(postId: number, initialRequestId: string) {
  const ai = config();
  if (ai?.initialRequest?.requestId === initialRequestId) ai.initialRequest = null;
  initialRequestAttempts.delete(postId);
}
function markInitialRequestAttemptTerminal(postId: number, requestId: string) {
  const attempt = getInitialRequestAttempt(postId);
  if (attempt?.requestId === requestId) attempt.terminal = true;
}
function reconcileRuntimeInitialRequest(postId: number, items: AiTimelineItem[]) {
  const attempt = getInitialRequestAttempt(postId);
  if (!attempt) return;
  const item = items.find((candidate) => candidate.type === 'ai_edit' && candidate.requestId === attempt.requestId);
  if (!item || item.executionStatus === null) return;
  if (item.executionStatus === 'enqueue_failed') {
    attempt.terminal = true;
    return;
  }
  clearRuntimeInitialRequest(postId, attempt.initialRequestId);
}
function normalizeUrl(url: string) {
  try { return new URL(url, window.location.origin).toString(); } catch { return url; }
}
function contextLabel(context: { lcId?: string; tagName?: string }) {
  return context.tagName ? `<${context.tagName.toLowerCase()}>` : context.lcId || __('Element', 'kayzart-live-code-editor');
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
/* Show the attempt counter only near the limit, where it can change what the
   user does (wait vs. stop and rewrite). Counting down from the limit keeps the
   threshold meaningful once the limit becomes configurable. */
const TURNS_LEFT_BEFORE_COUNTER = 3;
const ELAPSED_VISIBLE_AFTER_SECONDS = 10;

function targetLabel(target?: string) {
  return target ? target.toUpperCase() : '';
}
function toolLabel(event: AiJobEvent) {
  const target = targetLabel(event.target);
  switch (event.toolName) {
    case 'search_text':
      return __('Searching the code…', 'kayzart-live-code-editor');
    case 'read_document':
      return target ? sprintf(
        /* translators: %s: edit target such as HTML, CSS or JS. */
        __('Reading the %s…', 'kayzart-live-code-editor'), target,
      ) : __('Reading the code…', 'kayzart-live-code-editor');
    case 'read_selection':
      return __('Checking the selected element…', 'kayzart-live-code-editor');
    case 'replace_string':
    case 'replace_many':
      return target ? sprintf(
        /* translators: %s: edit target such as HTML, CSS or JS. */
        __('Editing the %s…', 'kayzart-live-code-editor'), target,
      ) : __('Editing the code…', 'kayzart-live-code-editor');
    case 'finish_edit':
      return __('Wrapping up the changes…', 'kayzart-live-code-editor');
    case 'finish_without_edit':
      return __('Preparing a reply…', 'kayzart-live-code-editor');
    case 'list_ai_edits':
    case 'get_ai_edit':
      return __('Looking up past edits…', 'kayzart-live-code-editor');
    default:
      return __('Working…', 'kayzart-live-code-editor');
  }
}
/* Returns '' for events with nothing worth saying, so the previous line stays. */
function eventLabel(event: AiJobEvent) {
  if (event.event === 'progress') {
    if (event.phase === 'finalization') return __('Wrapping up the changes…', 'kayzart-live-code-editor');
    if (event.turn && event.maxTurns && event.turn > event.maxTurns - TURNS_LEFT_BEFORE_COUNTER) return sprintf(
      /* translators: 1: current attempt, 2: maximum attempts. */
      __('Still working… (%1$d/%2$d)', 'kayzart-live-code-editor'), event.turn, event.maxTurns,
    );
    return __('Thinking…', 'kayzart-live-code-editor');
  }
  if (event.event === 'tool_start') return toolLabel(event);
  /* A successful tool needs no line of its own; only a retry is worth showing. */
  if (event.event === 'tool_end') return event.ok === false ? __('Retrying the edit…', 'kayzart-live-code-editor') : '';
  if (event.event === 'final') return __('Changes are ready.', 'kayzart-live-code-editor');
  return event.message || '';
}
function liveStatusLabel(events: AiJobEvent[]) {
  for (let index = events.length - 1; index >= 0; index -= 1) {
    const label = eventLabel(events[index]);
    if (label) return label;
  }
  return '';
}
function formatElapsed(seconds: number) {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
function formatDate(value: string) {
  try { return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)); }
  catch { return value; }
}
function statusLabel(item: AiTimelineItem, status = item.executionStatus) {
  if (status === 'pending') return __('AI edit is waiting.', 'kayzart-live-code-editor');
  if (status === 'running') return __('Applying changes.', 'kayzart-live-code-editor');
  if (status === 'completed' && item.applicationStatus === 'applied') return __('Changes were applied.', 'kayzart-live-code-editor');
  if (status === 'completed' && item.applicationStatus === 'reverted') return __('Changes were reverted.', 'kayzart-live-code-editor');
  if (status === 'completed') return __('Changes are ready.', 'kayzart-live-code-editor');
  if (status === 'canceled') return __('AI edit was canceled.', 'kayzart-live-code-editor');
  if (status === 'timed_out') return __('AI edit timed out.', 'kayzart-live-code-editor');
  return __('AI edit failed.', 'kayzart-live-code-editor');
}

function formatDuration(seconds: number) {
  if (seconds < 60) return sprintf(
    /* translators: %d: number of seconds. */
    _n('%d second', '%d seconds', seconds, 'kayzart-live-code-editor'), seconds,
  );
  const minutes = Math.floor(seconds / 60); const remainder = seconds % 60;
  if (!remainder) return sprintf(
    /* translators: %d: number of minutes. */
    _n('%d minute', '%d minutes', minutes, 'kayzart-live-code-editor'), minutes,
  );
  return sprintf(
    /* translators: 1: minutes, 2: seconds. */
    __('%1$d minutes %2$d seconds', 'kayzart-live-code-editor'),
    minutes,
    remainder,
  );
}

function AiIcon() {
  return <svg className="kayzart-ai-result-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.5l1.7 6.3a2.1 2.1 0 0 0 1.5 1.5l6.3 1.7-6.3 1.7a2.1 2.1 0 0 0-1.5 1.5L12 21.5l-1.7-6.3a2.1 2.1 0 0 0-1.5-1.5L2.5 12l6.3-1.7a2.1 2.1 0 0 0 1.5-1.5L12 2.5z" /></svg>;
}
function RestoreIcon() {
  return <svg className="kayzart-ai-system-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 7l-5 5 5 5M4 12h11a5 5 0 0 1 0 10h-3" /></svg>;
}

function AvailabilityNotice({ ai }: { ai: AiAvailability }) {
  if (ai.available) return null;
  let title: string = __('AI editing is unavailable', 'kayzart-live-code-editor');
  let message: string = __('Ask the site administrator to check the AI configuration.', 'kayzart-live-code-editor');
  if (!ai.featureEnabled) message = __('AI editing has been disabled by site policy.', 'kayzart-live-code-editor');
  else if (!ai.sdkPresent) message = __('The WordPress AI Client could not be loaded.', 'kayzart-live-code-editor');
  else if (!ai.schedulerPresent) message = __('The background job scheduler could not be loaded.', 'kayzart-live-code-editor');
  else if (!ai.mbstringPresent) message = __('The PHP mbstring extension is required for AI editing.', 'kayzart-live-code-editor');
  else if (!ai.domPresent) message = __('The PHP DOM extension is required for AI editing.', 'kayzart-live-code-editor');
  else if (!ai.providerConfigured) {
    title = __('Connect an AI provider', 'kayzart-live-code-editor');
    message = ai.canManageConnectors ? __('Connect an AI provider before sending an edit.', 'kayzart-live-code-editor') : __('Ask an administrator to configure an AI provider.', 'kayzart-live-code-editor');
  }
  return <div className="kayzart-ai-notice" role="status"><strong>{title}</strong><p>{message}</p>
    {!ai.providerConfigured && ai.canManageConnectors && ai.connectorsUrl ? <a href={ai.connectorsUrl}>{__('Open Connectors', 'kayzart-live-code-editor')}</a> : null}
  </div>;
}

export function AiEditorPanel({ active = true }: { active?: boolean } = {}) {
  const ai = config();
  const postId = Number(window.KAYZART.post_id || 0);
  const nonce = window.KAYZART.restNonce || '';
  const promptRef = useRef<HTMLTextAreaElement | null>(null);
  const promptValueRef = useRef(draftState.prompt);
  const chatRef = useRef<HTMLDivElement | null>(null);
  const pollAbortRef = useRef<AbortController | null>(null);
  const jobCreationInFlightRef = useRef(false);
  const mountedRef = useRef(true);
  const timelineRecoveryRef = useRef(false);
  const initialRequestAttemptedRef = useRef(false);
  const blockedRecoveryJobIdsRef = useRef(new Set<string>());
  const [prompt, setPromptState] = useState(draftState.prompt);
  const [contexts, setContextsState] = useState<SelectedElementContext[]>(draftState.contexts);
  const [items, setItems] = useState<AiTimelineItem[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [cursor, setCursor] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [initialTimelineSettled, setInitialTimelineSettled] = useState(false);
  const [initialRequestReconciled, setInitialRequestReconciled] = useState(!ai?.initialRequest);
  const [events, setEvents] = useState<AiJobEvent[]>([]);
  const [liveJob, setLiveJob] = useState<{ requestId: string; status: AiJobStatus } | null>(null);
  const [running, setRunning] = useState(false);
  const [canceling, setCanceling] = useState(false);
  const [elapsed, setElapsed] = useState(0);
  const [error, setError] = useState('');
  const [optimistic, setOptimistic] = useState<{ requestId: string; prompt: string; contexts: SelectedElementContext[] } | null>(null);
  const [pendingConflict, setPendingConflict] = useState<PendingConflict | null>(null);
  const [resolvingConflict, setResolvingConflict] = useState(false);
  const [editorIdentity, setEditorIdentity] = useState<Pick<EditorSnapshot, 'baseHash' | 'jsMode'> | null>(() => {
    const snapshot = host()?.getEditorSnapshot?.();
    return snapshot ? { baseHash: snapshot.baseHash, jsMode: snapshot.jsMode } : null;
  });

  const setPrompt = (value: string) => { promptValueRef.current = value; draftState.prompt = value; setPromptState(value); };
  const restorePromptIfEmpty = (value: string) => {
    if (promptValueRef.current === '') setPrompt(value);
  };
  const setContexts = (value: SelectedElementContext[]) => { draftState.contexts = value; setContextsState(value); };
  const refresh = async (settleInitialTimeline = false) => {
    if (!ai?.timelineUrl) {
      setLoading(false);
      if (settleInitialTimeline) setInitialTimelineSettled(true);
      return;
    }
    try {
      const page = await getTimeline(ai.timelineUrl, nonce, postId);
      if (!mountedRef.current) return;
      reconcileRuntimeInitialRequest(postId, page.items);
      setItems(page.items); setHasMore(page.hasMore); setCursor(page.nextCursor);
    } catch (caught) {
      if (mountedRef.current) setError(caught instanceof Error ? caught.message : __('History could not be loaded.', 'kayzart-live-code-editor'));
    } finally {
      if (mountedRef.current) {
        setLoading(false);
        if (settleInitialTimeline) setInitialTimelineSettled(true);
      }
    }
  };
  const finish = () => {
    clearActiveJob(postId); setRunning(false); setCanceling(false); setEvents([]); setLiveJob(null); setOptimistic(null);
    host()?.setEditorLock?.(false); void refresh();
  };
  const markApplied = async (activityId?: number) => {
    if (!activityId || !ai) return;
    try { await updateTimelineApplication(ai.timelineBaseUrl, nonce, activityId, 'applied'); } catch { /* The applied result remains recoverable from the timeline. */ }
  };
  const complete = async (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (!status.snapshot) { setError(__('AI response is missing its snapshot.', 'kayzart-live-code-editor')); finish(); return; }
    const output = normalizeSnapshot(status.snapshot);
    const current = host()?.getEditorSnapshot?.();
    if (current && sameSnapshotIdentity(current, output)) {
      setEditorIdentity({ baseHash: output.baseHash, jsMode: output.jsMode });
      await markApplied(active.activityId);
      finish();
      return;
    }
    if (!current || !sameSnapshotIdentity(current, active.inputSnapshot)) {
      setPendingConflict({ output, activityId: active.activityId });
      finish();
      return;
    }
    const replace = host()?.replaceEditorSnapshot;
    if (typeof replace !== 'function' || !replace(output)) {
      setError(__('The AI result could not be applied to the editor.', 'kayzart-live-code-editor'));
      finish();
      return;
    }
    setEditorIdentity({ baseHash: output.baseHash, jsMode: output.jsMode });
    await markApplied(active.activityId);
    finish();
  };
  const keepCurrentSnapshot = () => {
    setPendingConflict(null); setResolvingConflict(false); void refresh();
  };
  const applyConflictingSnapshot = async () => {
    if (!pendingConflict || resolvingConflict) return;
    const current = host()?.getEditorSnapshot?.();
    const replace = host()?.replaceEditorSnapshot;
    if (!current || typeof replace !== 'function') {
      setError(__('The editor state is unavailable. The AI result was not applied.', 'kayzart-live-code-editor'));
      return;
    }
    setResolvingConflict(true); setError('');
    if (!sameSnapshotIdentity(current, pendingConflict.output) && !replace(pendingConflict.output)) {
      setError(__('The AI result could not be applied to the editor.', 'kayzart-live-code-editor'));
      setResolvingConflict(false);
      return;
    }
    setEditorIdentity({ baseHash: pendingConflict.output.baseHash, jsMode: pendingConflict.output.jsMode });
    await markApplied(pendingConflict.activityId);
    setPendingConflict(null); setResolvingConflict(false); void refresh();
  };
  const terminal = (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (status.status === 'completed') { void complete(status, active); return; }
    const fallback = status.status === 'canceled' ? __('AI edit was canceled.', 'kayzart-live-code-editor')
      : status.status === 'timed_out' ? __('AI edit timed out.', 'kayzart-live-code-editor')
        : status.status === 'enqueue_failed' ? __('AI edit could not be scheduled.', 'kayzart-live-code-editor') : __('AI edit failed.', 'kayzart-live-code-editor');
    restorePromptIfEmpty(active.prompt); setError(status.error?.message || fallback); finish();
  };
  const poll = async (active: ActiveJobRecord) => {
    pollAbortRef.current?.abort();
    const controller = new AbortController(); pollAbortRef.current = controller;
    let pollingActive = active;
    const failPolling = (message: string) => {
      blockedRecoveryJobIdsRef.current.add(pollingActive.jobId);
      setError(message);
      finish();
    };
    if (Date.now() >= pollingActive.startedAt + pollingActive.timeoutMs) {
      failPolling(__('AI edit timed out while waiting for its status.', 'kayzart-live-code-editor'));
      return;
    }
    setRunning(true); setLiveJob({ requestId: active.requestId, status: 'pending' }); host()?.setEditorLock?.(true);
    const interval = positiveInteger(active.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS);
    try {
      for (;;) {
        const remainingBeforeRequest = pollingActive.startedAt + pollingActive.timeoutMs - Date.now();
        if (remainingBeforeRequest <= 0) {
          failPolling(__('AI edit timed out while waiting for its status.', 'kayzart-live-code-editor'));
          return;
        }
        let status: AiJobStatusResponse;
        const requestController = new AbortController();
        const abortRequest = () => requestController.abort();
        controller.signal.addEventListener('abort', abortRequest, { once: true });
        const requestTimeout = window.setTimeout(abortRequest, remainingBeforeRequest);
        try { status = await getJob(active.statusUrl, nonce, requestController.signal); setError(''); }
        catch (caught) {
          if (caught instanceof DOMException && caught.name === 'AbortError') {
            if (controller.signal.aborted) throw caught;
            failPolling(__('AI edit timed out while waiting for its status.', 'kayzart-live-code-editor'));
            return;
          }
          if (caught instanceof AiApiError && !isRetryableHttpStatus(caught.status)) {
            failPolling(caught.message);
            return;
          }
          const remaining = pollingActive.startedAt + pollingActive.timeoutMs - Date.now();
          if (remaining <= 0) {
            failPolling(__('AI edit timed out while waiting for its status.', 'kayzart-live-code-editor'));
            return;
          }
          setError(__('Connection lost. Retrying the AI job status…', 'kayzart-live-code-editor'));
          await sleep(Math.min(interval, remaining), controller.signal); continue;
        } finally {
          window.clearTimeout(requestTimeout);
          controller.signal.removeEventListener('abort', abortRequest);
        }
        if (!mountedRef.current) return;
        setEvents(Array.isArray(status.events) ? status.events : []); setLiveJob({ requestId: active.requestId, status: status.status });
        const serverStartedAt = Date.parse(status.createdAt);
        const corrected: ActiveJobRecord = {
          ...pollingActive,
          startedAt: Number.isFinite(serverStartedAt) ? serverStartedAt : pollingActive.startedAt,
          timeoutMs: positiveInteger(status.timeoutMs, pollingActive.timeoutMs),
          pollIntervalMs: positiveInteger(status.pollIntervalMs, pollingActive.pollIntervalMs),
        };
        if (corrected.startedAt !== pollingActive.startedAt || corrected.timeoutMs !== pollingActive.timeoutMs || corrected.pollIntervalMs !== pollingActive.pollIntervalMs) {
          pollingActive = corrected;
          saveActiveJob(pollingActive);
        }
        if (isTerminalStatus(status.status)) { terminal(status, pollingActive); return; }
        const remaining = pollingActive.startedAt + pollingActive.timeoutMs - Date.now();
        if (remaining <= 0) {
          failPolling(__('AI edit timed out while waiting for its status.', 'kayzart-live-code-editor'));
          return;
        }
        await sleep(Math.min(positiveInteger(pollingActive.pollIntervalMs, interval), remaining), controller.signal);
      }
    } catch (caught) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return;
      if (mountedRef.current) failPolling(caught instanceof Error ? caught.message : __('AI edit failed.', 'kayzart-live-code-editor'));
    }
  };

  useEffect(() => {
    mountedRef.current = true;
    const syncContexts = () => {
      const queued = Array.from(pendingContexts.values()); pendingContexts.clear();
      if (queued.length) setContexts(mergeContexts(draftState.contexts, queued));
      if (promptFocusRequested) { promptFocusRequested = false; window.requestAnimationFrame(() => promptRef.current?.focus()); }
    };
    const saved = () => { window.setTimeout(() => void refresh(), 150); };
    window.addEventListener(CONTEXT_SYNC_EVENT, syncContexts); window.addEventListener(SAVE_EVENT, saved);
    syncContexts(); void refresh(true);
    const active = loadActiveJob(postId); if (active) void poll(active);
    return () => {
      mountedRef.current = false; window.removeEventListener(CONTEXT_SYNC_EVENT, syncContexts); window.removeEventListener(SAVE_EVENT, saved);
      pollAbortRef.current?.abort(); pollAbortRef.current = null;
      if (!loadActiveJob(postId)) host()?.setEditorLock?.(false);
    };
  }, []);

  useEffect(() => {
    const syncEditorIdentity = () => {
      const snapshot = host()?.getEditorSnapshot?.();
      setEditorIdentity(snapshot ? { baseHash: snapshot.baseHash, jsMode: snapshot.jsMode } : null);
    };
    syncEditorIdentity();
    return host()?.subscribeEditorSnapshot?.(syncEditorIdentity);
  }, []);

  /* A long edit looks stalled when the status line sits still, so track how long
     the job has been running and surface it once the wait is notable. */
  useEffect(() => {
    if (!running) { setElapsed(0); return; }
    const startedAt = loadActiveJob(postId)?.startedAt || Date.now();
    const tick = () => setElapsed(Math.max(0, Math.floor((Date.now() - startedAt) / 1000)));
    tick();
    const timer = window.setInterval(tick, 1000);
    return () => window.clearInterval(timer);
  }, [running, postId]);

  useEffect(() => {
    if (!ai || running || timelineRecoveryRef.current || loadActiveJob(postId)) return;
    const item = [...items].reverse().find((candidate) => candidate.type === 'ai_edit' && candidate.canPoll && candidate.jobId && !blockedRecoveryJobIdsRef.current.has(candidate.jobId) && (candidate.executionStatus === 'pending' || candidate.executionStatus === 'running'));
    if (!item?.jobId || !item.requestId) return;
    timelineRecoveryRef.current = true;
    setRunning(true); setLiveJob({ requestId: item.requestId, status: item.executionStatus || 'pending' }); host()?.setEditorLock?.(true);
    void getTimelineSnapshot(ai.timelineBaseUrl, nonce, item.id, 'before').then((response) => {
      if (!mountedRef.current) return;
      const active: ActiveJobRecord = {
        version: 1, postId, jobId: item.jobId as string, requestId: item.requestId as string,
        statusUrl: normalizeUrl(`${ai.jobsBaseUrl}${item.jobId}`), cancelUrl: normalizeUrl(`${ai.jobsBaseUrl}${item.jobId}/cancel`),
        pollIntervalMs: DEFAULT_POLL_INTERVAL_MS, timeoutMs: positiveInteger(item.timeoutMs || 0, DEFAULT_TIMEOUT_MS),
        startedAt: Date.parse(item.createdAt) || Date.now(), prompt: item.prompt || '', contexts: item.contexts as SelectedElementContext[],
        inputSnapshot: normalizeSnapshot(response.snapshot), activityId: item.id,
      };
      saveActiveJob(active); void poll(active);
    }).catch((caught) => {
      blockedRecoveryJobIdsRef.current.add(item.jobId as string);
      setRunning(false); setLiveJob(null);
      host()?.setEditorLock?.(false);
      if (mountedRef.current) setError(caught instanceof Error ? caught.message : __('The original AI input could not be recovered.', 'kayzart-live-code-editor'));
    });
  }, [items, running]);

  // Counted in code points so the number matches PHP's mb_strlen(), which is what the server enforces.
  const promptChars = useMemo(() => [...prompt.trim()].length, [prompt]);
  const maxPromptChars = positiveInteger(Number(ai?.maxPromptChars), DEFAULT_MAX_PROMPT_CHARS);
  const canSend = Boolean(ai?.available && initialTimelineSettled && !running && !pendingConflict && prompt.trim() && promptChars <= maxPromptChars);
  const loadOlder = async () => {
    if (!ai || !cursor || !chatRef.current) return;
    const element = chatRef.current; const previousHeight = element.scrollHeight; setLoading(true);
    try {
      const page = await getTimeline(ai.timelineUrl, nonce, postId, cursor);
      setItems((current) => [...page.items, ...current]); setHasMore(page.hasMore); setCursor(page.nextCursor);
      window.requestAnimationFrame(() => { element.scrollTop += element.scrollHeight - previousHeight; });
    } catch (caught) { setError(caught instanceof Error ? caught.message : __('History could not be loaded.', 'kayzart-live-code-editor')); }
    finally { setLoading(false); }
  };
  const send = async (override?: { prompt: string; contexts: SelectedElementContext[]; requestId?: string; initialRequestId?: string }) => {
    if ((!canSend && !override) || !ai || running || jobCreationInFlightRef.current) return;
    jobCreationInFlightRef.current = true;
    const snapshot = host()?.getEditorSnapshot?.(); const editorMode = host()?.getEditorMode?.();
    if (!snapshot || !editorMode) {
      jobCreationInFlightRef.current = false;
      setError(__('Editor state is unavailable.', 'kayzart-live-code-editor'));
      return;
    }
    const input = normalizeSnapshot(snapshot); const promptText = override?.prompt || prompt.trim(); const submittedContexts = override?.contexts || [...contexts];
    const initialAttempt = !override ? getInitialRequestAttempt(postId) : null;
    if (initialAttempt?.terminal) {
      initialAttempt.requestId = makeId('request');
      initialAttempt.terminal = false;
    }
    const initialRequestId = override?.initialRequestId || initialAttempt?.initialRequestId;
    const requestId = override?.requestId || initialAttempt?.requestId || makeId('request'); setError(''); setEvents([]); setOptimistic({ requestId, prompt: promptText, contexts: submittedContexts });
    setPrompt(''); setContexts([]); setRunning(true); host()?.setEditorLock?.(true);
    try {
      const created = await createJob(ai.jobsUrl, nonce, { ...input, requestId, initialRequestId, post_id: postId, editorMode, prompt: promptText, selectedContexts: submittedContexts.length ? submittedContexts : undefined });
      if (initialRequestId) clearRuntimeInitialRequest(postId, initialRequestId);
      const active: ActiveJobRecord = {
        version: 1, postId, jobId: created.jobId, requestId: created.requestId,
        statusUrl: normalizeUrl(created.statusUrl), cancelUrl: normalizeUrl(created.cancelUrl),
        pollIntervalMs: positiveInteger(created.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS), timeoutMs: positiveInteger(created.timeoutMs, DEFAULT_TIMEOUT_MS),
        startedAt: Date.now(), prompt: promptText, contexts: submittedContexts, inputSnapshot: input, activityId: created.timelineItem?.id,
      };
      saveActiveJob(active);
      jobCreationInFlightRef.current = false;
      if (created.timelineItem) setItems((current) => current.some((item) => item.requestId === created.requestId) ? current : [...current, created.timelineItem as AiTimelineItem]);
      setOptimistic(null); setLiveJob({ requestId: created.requestId, status: created.status });
      void refresh(); await poll(active);
    } catch (caught) {
      jobCreationInFlightRef.current = false;
      if (initialRequestId && caught instanceof AiApiError && TERMINAL_INITIAL_REQUEST_CODES.has(caught.code)) {
        markInitialRequestAttemptTerminal(postId, requestId);
      }
      setError(caught instanceof AiApiError || caught instanceof Error ? caught.message : __('AI edit failed.', 'kayzart-live-code-editor'));
      restorePromptIfEmpty(promptText); setOptimistic(null); setRunning(false); host()?.setEditorLock?.(false); void refresh();
    }
  };
  const rerun = (item: AiTimelineItem) => {
    const resolver = host()?.getElementContext;
    const retryContexts: SelectedElementContext[] = [];
    for (const context of item.contexts) {
      const resolved = context.lcId ? resolver?.(context.lcId) : null;
      if (!resolved) {
        setError(__('The selected element is no longer available. Select it again before running the edit.', 'kayzart-live-code-editor'));
        return;
      }
      retryContexts.push(resolved);
    }
    void send({ prompt: item.prompt || '', contexts: retryContexts });
  };

  useEffect(() => {
    const initialRequest = ai?.initialRequest;
    if (!initialRequest) {
      setInitialRequestReconciled(true);
      return;
    }
    if (initialRequestAttemptedRef.current || !initialTimelineSettled || running) return;
    initialRequestAttemptedRef.current = true;
    setPrompt(initialRequest.prompt);
    setInitialRequestReconciled(true);
    const initialAttempt = getInitialRequestAttempt(postId);
    if (initialAttempt?.terminal) return;
    const activeTimelineJob = items.some((item) => item.type === 'ai_edit' && (item.executionStatus === 'pending' || item.executionStatus === 'running'));
    if (!ai?.available || activeTimelineJob || loadActiveJob(postId)) return;
    void send({ prompt: initialRequest.prompt, contexts: [], requestId: initialAttempt?.requestId || initialRequest.requestId, initialRequestId: initialRequest.requestId });
  }, [ai?.initialRequest, ai?.available, items, initialTimelineSettled, postId, running]);
  const stop = async () => {
    const active = loadActiveJob(postId); if (!active || canceling) return;
    setCanceling(true);
    try { const status = await cancelJob(active.cancelUrl, nonce); if (isTerminalStatus(status.status)) terminal(status, active); }
    catch (caught) { setError(caught instanceof Error ? caught.message : __('Cancel request failed.', 'kayzart-live-code-editor')); setCanceling(false); }
  };
  const snapshotPosition = (item: AiTimelineItem, current: Pick<EditorSnapshot, 'baseHash' | 'jsMode'> | null): 'before' | 'after' | 'other' => {
    const matchesBefore = Boolean(current && item.beforeHash && item.beforeJsMode && current.baseHash === item.beforeHash && current.jsMode === item.beforeJsMode);
    const matchesAfter = Boolean(current && item.afterHash && item.afterJsMode && current.baseHash === item.afterHash && current.jsMode === item.afterJsMode);
    if (matchesBefore && !matchesAfter) return 'before';
    if (matchesAfter && !matchesBefore) return 'after';
    if (matchesBefore && matchesAfter) return item.applicationStatus === 'reverted' ? 'before' : 'after';
    return 'other';
  };
  const restore = async (item: AiTimelineItem, target: 'before' | 'after') => {
    if (!ai) return;
    const current = host()?.getEditorSnapshot?.();
    const position = snapshotPosition(item, current ? { baseHash: current.baseHash, jsMode: current.jsMode } : null);
    if (position === target) return;
    if (position === 'other' && !window.confirm(__('Your current unsaved changes will be replaced. Continue?', 'kayzart-live-code-editor'))) return;
    try {
      const result = await getTimelineSnapshot(ai.timelineBaseUrl, nonce, item.id, target);
      const snapshot = normalizeSnapshot(result.snapshot);
      const replace = host()?.replaceEditorSnapshot;
      if (typeof replace !== 'function' || !replace(snapshot)) {
        setError(__('The edit could not be restored because the editor rejected the snapshot.', 'kayzart-live-code-editor'));
        return;
      }
      setEditorIdentity({ baseHash: snapshot.baseHash, jsMode: snapshot.jsMode });
      try {
        await restoreTimeline(ai.timelineBaseUrl, nonce, item.id, target);
        await refresh();
      } catch (_caught) {
        await refresh();
        setError(__('The editor was restored, but the edit history could not be updated.', 'kayzart-live-code-editor'));
      }
    } catch (caught) { setError(caught instanceof Error ? caught.message : __('The edit could not be restored.', 'kayzart-live-code-editor')); }
  };

  const liveStatus = liveStatusLabel(events);

  const renderAi = (item: AiTimelineItem) => {
    const isLive = liveJob?.requestId === item.requestId;
    const executionStatus = isLive ? liveJob.status : item.executionStatus;
    const failed = executionStatus && ['error', 'canceled', 'timed_out', 'enqueue_failed'].includes(executionStatus);
    const position = snapshotPosition(item, editorIdentity);
    return <div className="kayzart-ai-exchange" key={item.activityId}>
      <div className="kayzart-ai-message kayzart-ai-message-user"><p>{item.prompt}</p>
        {item.contexts.length ? <small>{item.contexts.map(contextLabel).join(', ')}</small> : null}
        <span>{item.author.name} · {formatDate(item.createdAt)}</span>
      </div>
      <div className={`kayzart-ai-result is-${executionStatus || 'unknown'}`}>
        <div className="kayzart-ai-result-heading"><AiIcon /><strong>{statusLabel(item, executionStatus)}</strong></div>
        {item.changedTargets.length ? <div className="kayzart-ai-targets">{item.changedTargets.map((value) => {
          const stat = item.changeStats?.[value];
          return <span key={value}><b>{value.toUpperCase()}</b>{stat ? <><i>+{stat.added}</i><em>−{stat.removed}</em></> : null}</span>;
        })}</div> : null}
        {isLive && running && liveStatus ? <p className="kayzart-ai-status">{elapsed >= ELAPSED_VISIBLE_AFTER_SECONDS ? sprintf(
          /* translators: 1: what the AI is doing, 2: elapsed time as m:ss. */
          __('%1$s (%2$s)', 'kayzart-live-code-editor'), liveStatus, formatElapsed(elapsed),
        ) : liveStatus}</p> : null}
        {isLive && events.length ? <details className="kayzart-ai-details is-log"><summary>{__('Run log', 'kayzart-live-code-editor')}</summary><dl>
          {events.map((event, index) => <Fragment key={`${event.requestId}-${index}`}>
            <dt>{event.toolName || event.event}</dt>
            <dd>{[event.message, event.turn ? `${event.turn}/${event.maxTurns}` : '', event.inputSummary, event.outputSummary].filter(Boolean).join(' → ')}</dd>
          </Fragment>)}
        </dl></details> : null}
        {executionStatus === 'completed' && item.detailsAvailable ? <div className="kayzart-ai-result-actions">
          <button type="button" className="is-restore-before" disabled={position === 'before'} title={position === 'before' ? __('This is the current state.', 'kayzart-live-code-editor') : undefined} onClick={() => void restore(item, 'before')}>{__('Restore before changes', 'kayzart-live-code-editor')}</button>
          <button type="button" className="is-restore-after" disabled={position === 'after'} title={position === 'after' ? __('This is the current state.', 'kayzart-live-code-editor') : undefined} onClick={() => void restore(item, 'after')}>{__('Restore this result', 'kayzart-live-code-editor')}</button>
        </div> : null}
        {executionStatus === 'completed' && !item.detailsAvailable ? <p className="kayzart-ai-expired">{__('The retention period for these changes has ended.', 'kayzart-live-code-editor')}</p> : null}
        {executionStatus === 'completed' && (item.model || item.inputTokens !== null || item.outputTokens !== null || item.durationSeconds !== null) ? <details className="kayzart-ai-details"><summary>{__('Details', 'kayzart-live-code-editor')}</summary><dl>
          {item.model ? <><dt>{__('Model', 'kayzart-live-code-editor')}</dt><dd>{item.model}</dd></> : null}
          {item.inputTokens !== null ? <><dt>{__('Input', 'kayzart-live-code-editor')}</dt><dd>{sprintf(
            /* translators: %s: formatted token count. */
            __('%s tokens', 'kayzart-live-code-editor'), item.inputTokens.toLocaleString(),
          )}</dd></> : null}
          {item.outputTokens !== null ? <><dt>{__('Output', 'kayzart-live-code-editor')}</dt><dd>{sprintf(
            /* translators: %s: formatted token count. */
            __('%s tokens', 'kayzart-live-code-editor'), item.outputTokens.toLocaleString(),
          )}</dd></> : null}
          {item.durationSeconds !== null ? <><dt>{__('Duration', 'kayzart-live-code-editor')}</dt><dd>{formatDuration(item.durationSeconds)}</dd></> : null}
        </dl></details> : null}
        {failed ? <div className="kayzart-ai-result-actions"><button type="button" disabled={running} onClick={() => rerun(item)}>{__('Run again', 'kayzart-live-code-editor')}</button><button type="button" onClick={() => { setPrompt(item.prompt || ''); promptRef.current?.focus(); }}>{__('Return to input', 'kayzart-live-code-editor')}</button></div> : null}
      </div>
    </div>;
  };

  return <div className="kayzart-ai-panel" hidden={!active}>
    {ai ? <AvailabilityNotice ai={ai} /> : null}
    {error ? <div className="kayzart-ai-error" role="alert">{error}</div> : null}
    {pendingConflict ? <div className="kayzart-ai-conflict" role="alert">
      <strong>{__('The editor changed while the AI edit was running.', 'kayzart-live-code-editor')}</strong>
      <p>{__('Choose whether to keep the current editor content or replace it with the completed AI result.', 'kayzart-live-code-editor')}</p>
      <div className="kayzart-ai-conflict-actions">
        <button type="button" className="is-keep" disabled={resolvingConflict} onClick={keepCurrentSnapshot}>{__('Keep current content', 'kayzart-live-code-editor')}</button>
        <button type="button" className="is-replace" disabled={resolvingConflict} onClick={() => void applyConflictingSnapshot()}>{resolvingConflict ? __('Applying…', 'kayzart-live-code-editor') : __('Replace with AI result', 'kayzart-live-code-editor')}</button>
      </div>
    </div> : null}
    <div className="kayzart-ai-chat" ref={chatRef} role="log" aria-live="polite">
      {hasMore ? <button className="kayzart-ai-load-more" type="button" disabled={loading} onClick={() => void loadOlder()}>{loading ? __('Loading…', 'kayzart-live-code-editor') : __('Load earlier history', 'kayzart-live-code-editor')}</button> : null}
      {!loading && !items.length && !optimistic ? <p className="kayzart-ai-empty">{__('Describe the landing page change you want.', 'kayzart-live-code-editor')}</p> : null}
      {items.map((item) => item.type === 'ai_edit' ? renderAi(item) : item.type === 'save' ? <div className="kayzart-ai-save-divider" key={item.activityId}>
        <span>{__('Changes saved · ', 'kayzart-live-code-editor')}</span>{item.revisionAvailable ? <button type="button" onClick={() => host()?.openSettingsTab?.('history')}>{sprintf(
          /* translators: %d: revision ID. */
          __('Revision #%d', 'kayzart-live-code-editor'), item.revisionId || 0,
        )}</button> : <><span>{sprintf(
          /* translators: %d: revision ID. */
          __('Revision #%d', 'kayzart-live-code-editor'), item.revisionId || 0,
        )}</span><em>{__('Revision was deleted.', 'kayzart-live-code-editor')}</em></>}
      </div> : <div className={`kayzart-ai-system is-${item.restoreTarget === 'before' ? 'before' : 'after'}`} key={item.activityId}>
        <RestoreIcon /><strong>{sprintf(item.restoreTarget === 'before'
          /* translators: %d: source AI edit activity ID. */
          ? __('Restored edit #%d to its previous state.', 'kayzart-live-code-editor')
          /* translators: %d: source AI edit activity ID. */
          : __('Restored the result of edit #%d.', 'kayzart-live-code-editor'), item.sourceActivityId || 0)}</strong><small>{item.author.name} · {formatDate(item.createdAt)}</small>
      </div>)}
      {optimistic && !items.some((item) => item.requestId === optimistic.requestId) ? <div className="kayzart-ai-exchange">
        <div className="kayzart-ai-message kayzart-ai-message-user"><p>{optimistic.prompt}</p><small>{optimistic.contexts.map(contextLabel).join(', ')}</small></div>
        <div className={`kayzart-ai-result is-${liveJob?.requestId === optimistic.requestId ? liveJob.status : 'pending'}`}><div className="kayzart-ai-result-heading"><AiIcon /><strong>{liveJob?.requestId === optimistic.requestId && liveJob.status === 'running' ? __('Applying changes.', 'kayzart-live-code-editor') : __('AI edit is waiting.', 'kayzart-live-code-editor')}</strong></div>
          {liveJob?.requestId === optimistic.requestId && liveStatus ? <p className="kayzart-ai-status">{liveStatus}</p> : null}
        </div>
      </div> : null}
    </div>
    <div className="kayzart-ai-composer">
      {contexts.length ? <div className="kayzart-ai-contexts">{contexts.map((context) => <span key={context.lcId}>{contextLabel(context)}<button type="button" onClick={() => setContexts(contexts.filter((item) => item.lcId !== context.lcId))} aria-label={__('Remove context', 'kayzart-live-code-editor')}>×</button></span>)}</div> : null}
      <textarea ref={promptRef} value={prompt} rows={4} disabled={!ai?.available || !initialRequestReconciled} onChange={(event) => setPrompt(event.currentTarget.value)} placeholder={__('Example: Make the hero clearer and improve the primary button.', 'kayzart-live-code-editor')} />
      <div className="kayzart-ai-composer-footer"><small className={promptChars > maxPromptChars ? 'is-error' : ''}>{sprintf(
        /* translators: 1: current instruction length, 2: maximum instruction length. */
        __('%1$d/%2$d characters', 'kayzart-live-code-editor'), promptChars, maxPromptChars,
      )}</small><div>
        {running ? <button type="button" className="is-stop" disabled={canceling} onClick={() => void stop()}>{canceling ? __('Canceling…', 'kayzart-live-code-editor') : __('Stop', 'kayzart-live-code-editor')}</button> : null}
        <button type="button" disabled={!canSend} onClick={() => void send()}>{__('Send', 'kayzart-live-code-editor')}</button>
      </div></div>
    </div>
  </div>;
}

function registerToolbar() {
  const action = { id: TOOLBAR_ACTION_ID, label: __('AI Edit', 'kayzart-live-code-editor'), tooltip: __('Edit with AI', 'kayzart-live-code-editor'), order: 10, placement: 'before-settings' as const,
    icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 15.5A2 2 0 0 0 8.5 14L2.4 12.5a.5.5 0 0 1 0-1L8.5 10A2 2 0 0 0 10 8.5l1.5-6.1a.5.5 0 0 1 1 0L14 8.5a2 2 0 0 0 1.5 1.5l6.1 1.5a.5.5 0 0 1 0 1L15.5 14a2 2 0 0 0-1.5 1.5l-1.5 6.1a.5.5 0 0 1-1 0z"/></svg>', onClick: () => openAi(false) };
  const register = host()?.registerToolbarAction;
  if (typeof register === 'function') register(action);
}
function installContextEntrypoints() {
  window.addEventListener(PREVIEW_ACTION_EVENT, (raw) => { const event = raw as CustomEvent<{ actionId?: string }>; if (event.detail?.actionId === PREVIEW_ACTION_ID) openAi(true); });
  const refresh = () => { const panel = document.querySelector<HTMLElement>(ELEMENTS_PANEL_SELECTOR); if (!panel || panel.querySelector(`.${ELEMENTS_BUTTON_CLASS}`) || !config()?.available) return; const button = document.createElement('button'); button.type = 'button'; button.className = `kayzart-btn kayzart-btn-secondary ${ELEMENTS_BUTTON_CLASS}`; button.textContent = __('Edit with AI', 'kayzart-live-code-editor'); button.addEventListener('click', () => openAi(true)); panel.append(button); };
  new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true }); refresh();
}

let initialized = false;

export function initAiEditorIntegration() {
  if (initialized) return;
  initialized = true;
  registerToolbar();
  installContextEntrypoints();

  const restored = loadActiveJob(Number(window.KAYZART.post_id || 0));
  if (restored) {
    host()?.setEditorLock?.(true);
    window.requestAnimationFrame(() => openAi(false));
    return;
  }

  const ai = config();
  const postId = Number(window.KAYZART.post_id || 0);
  if (ai?.timelineUrl && postId > 0) {
    void getTimeline(ai.timelineUrl, window.KAYZART.restNonce || '', postId).then((page) => {
      const active = page.items.some((item) => item.type === 'ai_edit' && item.canPoll && (item.executionStatus === 'pending' || item.executionStatus === 'running'));
      if (active) {
        host()?.setEditorLock?.(true);
        window.requestAnimationFrame(() => openAi(false));
      }
    }).catch(() => { /* Availability UI reports recoverable REST failures when opened. */ });
  }
}
