import { createElement, createRoot, Fragment, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type {
  ActiveJobRecord, AiAvailability, AiJobEvent, AiJobStatus, AiJobStatusResponse, AiTimelineItem,
  SelectedElementContext,
} from './contract';
import { normalizeSnapshot } from './contract';
import {
  AiApiError, cancelJob, createJob, getJob, getTimeline,
  restoreTimeline, updateTimelineApplication,
} from './api';
import { DEFAULT_POLL_INTERVAL_MS, DEFAULT_TIMEOUT_MS, isTerminalStatus, positiveInteger, sleep } from './polling';
import { clearActiveJob, loadActiveJob, saveActiveJob } from './session';
import './style.css';

const AI_TAB_ID = 'kayzart-ai';
const TOOLBAR_ACTION_ID = 'kayzart-toolbar-ai-edit';
const PREVIEW_ACTION_EVENT = 'kayzart-preview-overlay-action';
const PREVIEW_ACTION_ID = 'kayzart-ai-edit-context';
const CONTEXT_SYNC_EVENT = 'kayzart-ai-context-sync';
const SAVE_EVENT = 'kayzart-editor-saved';
const MAX_PROMPT_BYTES = 8192;
const MAX_CONTEXTS = 20;
const ELEMENTS_PANEL_SELECTOR = '[data-kayzart-panel="elements"]';
const ELEMENTS_BUTTON_CLASS = 'kayzart-ai-elements-button';

declare global {
  interface Window {
    __KAYZART_AI_TAB_UNREGISTER__?: () => void;
    __KAYZART_AI_TOOLBAR_UNREGISTER__?: () => void;
  }
}

const draftState = { prompt: '', contexts: [] as SelectedElementContext[] };
const pendingContexts = new Map<string, SelectedElementContext>();
let promptFocusRequested = false;

function makeId(prefix: string) {
  const value = typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
  return `${prefix}-${value}`;
}

function config(): AiAvailability | undefined { return window.KAYZART.ai; }
function host() { return window.KAYZART_EXTENSION_API; }
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
function eventLabel(event: AiJobEvent) {
  if (event.event === 'progress') return event.message || __('Working…', 'kayzart-live-code-editor');
  if (event.event === 'tool_start') return sprintf(__('Running %s', 'kayzart-live-code-editor'), event.toolName || __('tool', 'kayzart-live-code-editor'));
  if (event.event === 'tool_end') return sprintf(__('Finished %s', 'kayzart-live-code-editor'), event.toolName || __('tool', 'kayzart-live-code-editor'));
  if (event.event === 'final') return __('Changes are ready.', 'kayzart-live-code-editor');
  return event.message || event.event;
}
function formatDate(value: string) {
  try { return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)); }
  catch { return value; }
}
function statusLabel(item: AiTimelineItem, status = item.executionStatus) {
  if (status === 'pending') return 'AI編集を待機中です';
  if (status === 'running') return '変更を適用中です';
  if (status === 'completed' && item.applicationStatus === 'applied') return '変更を適用しました';
  if (status === 'completed' && item.applicationStatus === 'reverted') return '変更を元に戻しました';
  if (status === 'completed') return '変更が完了しました';
  if (status === 'canceled') return 'AI編集をキャンセルしました';
  if (status === 'timed_out') return 'AI編集がタイムアウトしました';
  return 'AI編集に失敗しました';
}

function formatDuration(seconds: number) {
  if (seconds < 60) return `${seconds}秒`;
  const minutes = Math.floor(seconds / 60); const remainder = seconds % 60;
  return remainder ? `${minutes}分${remainder}秒` : `${minutes}分`;
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
  else if (!ai.providerConfigured) {
    title = __('Connect an AI provider', 'kayzart-live-code-editor');
    message = ai.canManageConnectors ? __('Connect an AI provider before sending an edit.', 'kayzart-live-code-editor') : __('Ask an administrator to configure an AI provider.', 'kayzart-live-code-editor');
  }
  return <div className="kayzart-ai-notice" role="status"><strong>{title}</strong><p>{message}</p>
    {!ai.providerConfigured && ai.canManageConnectors && ai.connectorsUrl ? <a href={ai.connectorsUrl}>{__('Open Connectors', 'kayzart-live-code-editor')}</a> : null}
  </div>;
}

export function AiEditorPanel() {
  const ai = config();
  const postId = Number(window.KAYZART.post_id || 0);
  const nonce = window.KAYZART.restNonce || '';
  const promptRef = useRef<HTMLTextAreaElement | null>(null);
  const chatRef = useRef<HTMLDivElement | null>(null);
  const pollAbortRef = useRef<AbortController | null>(null);
  const mountedRef = useRef(true);
  const timelineRecoveryRef = useRef(false);
  const [prompt, setPromptState] = useState(draftState.prompt);
  const [contexts, setContextsState] = useState<SelectedElementContext[]>(draftState.contexts);
  const [items, setItems] = useState<AiTimelineItem[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [cursor, setCursor] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [events, setEvents] = useState<AiJobEvent[]>([]);
  const [liveJob, setLiveJob] = useState<{ requestId: string; status: AiJobStatus } | null>(null);
  const [running, setRunning] = useState(false);
  const [canceling, setCanceling] = useState(false);
  const [error, setError] = useState('');
  const [optimistic, setOptimistic] = useState<{ requestId: string; prompt: string; contexts: SelectedElementContext[] } | null>(null);
  const [editorHash, setEditorHash] = useState(() => host()?.getEditorSnapshot?.()?.baseHash || '');

  const setPrompt = (value: string) => { draftState.prompt = value; setPromptState(value); };
  const setContexts = (value: SelectedElementContext[]) => { draftState.contexts = value; setContextsState(value); };
  const refresh = async () => {
    if (!ai?.timelineUrl) { setLoading(false); return; }
    try {
      const page = await getTimeline(ai.timelineUrl, nonce, postId);
      if (!mountedRef.current) return;
      setItems(page.items); setHasMore(page.hasMore); setCursor(page.nextCursor);
    } catch (caught) {
      if (mountedRef.current) setError(caught instanceof Error ? caught.message : __('History could not be loaded.', 'kayzart-live-code-editor'));
    } finally {
      if (mountedRef.current) setLoading(false);
    }
  };
  const finish = () => {
    clearActiveJob(postId); setRunning(false); setCanceling(false); setEvents([]); setLiveJob(null); setOptimistic(null);
    host()?.setEditorLock?.(false); void refresh();
  };
  const complete = async (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (!status.snapshot) { setError(__('AI response is missing its snapshot.', 'kayzart-live-code-editor')); finish(); return; }
    const output = normalizeSnapshot(status.snapshot);
    host()?.replaceEditorSnapshot?.(output);
    setEditorHash(output.baseHash);
    if (active.activityId && ai) {
      try { await updateTimelineApplication(ai.timelineBaseUrl, nonce, active.activityId, 'applied'); } catch { /* The job remains recoverable. */ }
    }
    finish();
  };
  const terminal = (status: AiJobStatusResponse, active: ActiveJobRecord) => {
    if (status.status === 'completed') { void complete(status, active); return; }
    const fallback = status.status === 'canceled' ? __('AI edit was canceled.', 'kayzart-live-code-editor')
      : status.status === 'timed_out' ? __('AI edit timed out.', 'kayzart-live-code-editor')
        : status.status === 'enqueue_failed' ? __('AI edit could not be scheduled.', 'kayzart-live-code-editor') : __('AI edit failed.', 'kayzart-live-code-editor');
    setError(status.error?.message || fallback); finish();
  };
  const poll = async (active: ActiveJobRecord) => {
    pollAbortRef.current?.abort();
    const controller = new AbortController(); pollAbortRef.current = controller;
    setRunning(true); setLiveJob({ requestId: active.requestId, status: 'pending' }); host()?.setEditorLock?.(true);
    const interval = positiveInteger(active.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS);
    try {
      for (;;) {
        let status: AiJobStatusResponse;
        try { status = await getJob(active.statusUrl, nonce, controller.signal); setError(''); }
        catch (caught) {
          if (caught instanceof DOMException && caught.name === 'AbortError') throw caught;
          setError(__('Connection lost. Retrying the AI job status…', 'kayzart-live-code-editor'));
          await sleep(interval, controller.signal); continue;
        }
        if (!mountedRef.current) return;
        setEvents(Array.isArray(status.events) ? status.events : []); setLiveJob({ requestId: active.requestId, status: status.status });
        if (isTerminalStatus(status.status)) { terminal(status, active); return; }
        await sleep(interval, controller.signal);
      }
    } catch (caught) {
      if (caught instanceof DOMException && caught.name === 'AbortError') return;
      if (mountedRef.current) setError(caught instanceof Error ? caught.message : __('AI edit failed.', 'kayzart-live-code-editor'));
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
    syncContexts(); void refresh();
    const active = loadActiveJob(postId); if (active) void poll(active);
    return () => {
      mountedRef.current = false; window.removeEventListener(CONTEXT_SYNC_EVENT, syncContexts); window.removeEventListener(SAVE_EVENT, saved);
      pollAbortRef.current?.abort(); pollAbortRef.current = null;
      if (!loadActiveJob(postId)) host()?.setEditorLock?.(false);
    };
  }, []);

  useEffect(() => {
    const syncEditorHash = () => setEditorHash(host()?.getEditorSnapshot?.()?.baseHash || '');
    syncEditorHash();
    return host()?.subscribeEditorSnapshot?.(syncEditorHash);
  }, []);

  useEffect(() => {
    if (!ai || running || timelineRecoveryRef.current || loadActiveJob(postId)) return;
    const item = [...items].reverse().find((candidate) => candidate.type === 'ai_edit' && candidate.canPoll && (candidate.executionStatus === 'pending' || candidate.executionStatus === 'running'));
    const snapshot = host()?.getEditorSnapshot?.();
    if (!item?.jobId || !item.requestId || !snapshot) return;
    timelineRecoveryRef.current = true;
    const active: ActiveJobRecord = {
      version: 1, postId, jobId: item.jobId, requestId: item.requestId,
      statusUrl: normalizeUrl(`${ai.jobsBaseUrl}${item.jobId}`), cancelUrl: normalizeUrl(`${ai.jobsBaseUrl}${item.jobId}/cancel`),
      pollIntervalMs: DEFAULT_POLL_INTERVAL_MS, timeoutMs: DEFAULT_TIMEOUT_MS,
      startedAt: Date.parse(item.createdAt) || Date.now(), prompt: item.prompt || '', contexts: item.contexts as SelectedElementContext[],
      inputSnapshot: normalizeSnapshot(snapshot), activityId: item.id,
    };
    saveActiveJob(active); void poll(active);
  }, [items, running]);

  const promptBytes = useMemo(() => new TextEncoder().encode(prompt.trim()).length, [prompt]);
  const canSend = Boolean(ai?.available && !running && prompt.trim() && promptBytes <= MAX_PROMPT_BYTES);
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
  const send = async (override?: { prompt: string; contexts: SelectedElementContext[] }) => {
    if ((!canSend && !override) || !ai) return;
    const snapshot = host()?.getEditorSnapshot?.(); const editorMode = host()?.getEditorMode?.();
    if (!snapshot || !editorMode) { setError(__('Editor state is unavailable.', 'kayzart-live-code-editor')); return; }
    const input = normalizeSnapshot(snapshot); const promptText = override?.prompt || prompt.trim(); const submittedContexts = override?.contexts || [...contexts];
    const requestId = makeId('request'); setError(''); setEvents([]); setOptimistic({ requestId, prompt: promptText, contexts: submittedContexts });
    setPrompt(''); setContexts([]); setRunning(true); host()?.setEditorLock?.(true);
    try {
      const created = await createJob(ai.jobsUrl, nonce, { ...input, requestId, post_id: postId, editorMode, prompt: promptText, selectedContexts: submittedContexts.length ? submittedContexts : undefined });
      const active: ActiveJobRecord = {
        version: 1, postId, jobId: created.jobId, requestId: created.requestId,
        statusUrl: normalizeUrl(created.statusUrl), cancelUrl: normalizeUrl(created.cancelUrl),
        pollIntervalMs: positiveInteger(created.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS), timeoutMs: positiveInteger(created.timeoutMs, DEFAULT_TIMEOUT_MS),
        startedAt: Date.now(), prompt: promptText, contexts: submittedContexts, inputSnapshot: input, activityId: created.timelineItem?.id,
      };
      saveActiveJob(active);
      if (created.timelineItem) setItems((current) => current.some((item) => item.requestId === created.requestId) ? current : [...current, created.timelineItem as AiTimelineItem]);
      setOptimistic(null); setLiveJob({ requestId: created.requestId, status: created.status });
      void refresh(); await poll(active);
    } catch (caught) {
      setError(caught instanceof AiApiError || caught instanceof Error ? caught.message : __('AI edit failed.', 'kayzart-live-code-editor'));
      setPrompt(promptText); setOptimistic(null); setRunning(false); host()?.setEditorLock?.(false); void refresh();
    }
  };
  const stop = async () => {
    const active = loadActiveJob(postId); if (!active || canceling) return;
    setCanceling(true);
    try { const status = await cancelJob(active.cancelUrl, nonce); if (isTerminalStatus(status.status)) terminal(status, active); }
    catch (caught) { setError(caught instanceof Error ? caught.message : __('Cancel request failed.', 'kayzart-live-code-editor')); setCanceling(false); }
  };
  const snapshotPosition = (item: AiTimelineItem, hash: string): 'before' | 'after' | 'other' => {
    const matchesBefore = Boolean(hash && item.beforeHash && hash === item.beforeHash);
    const matchesAfter = Boolean(hash && item.afterHash && hash === item.afterHash);
    if (matchesBefore && !matchesAfter) return 'before';
    if (matchesAfter && !matchesBefore) return 'after';
    if (matchesBefore && matchesAfter) return item.applicationStatus === 'reverted' ? 'before' : 'after';
    return 'other';
  };
  const restore = async (item: AiTimelineItem, target: 'before' | 'after') => {
    if (!ai) return;
    const currentHash = host()?.getEditorSnapshot?.()?.baseHash || '';
    const position = snapshotPosition(item, currentHash);
    if (position === target) return;
    if (position === 'other' && !window.confirm('現在の未保存変更が置き換えられます。続行しますか？')) return;
    try {
      const result = await restoreTimeline(ai.timelineBaseUrl, nonce, item.id, target);
      const snapshot = normalizeSnapshot(result.snapshot);
      host()?.replaceEditorSnapshot?.(snapshot);
      setEditorHash(snapshot.baseHash);
      await refresh();
    } catch (caught) { setError(caught instanceof Error ? caught.message : __('The edit could not be restored.', 'kayzart-live-code-editor')); }
  };

  const renderAi = (item: AiTimelineItem) => {
    const isLive = liveJob?.requestId === item.requestId;
    const executionStatus = isLive ? liveJob.status : item.executionStatus;
    const failed = executionStatus && ['error', 'canceled', 'timed_out', 'enqueue_failed'].includes(executionStatus);
    const position = snapshotPosition(item, editorHash);
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
        {isLive && running && events.length ? <ul className="kayzart-ai-events">{events.slice(-8).map((event, index) => <li key={`${event.requestId}-${index}`}>{eventLabel(event)}</li>)}</ul> : null}
        {executionStatus === 'completed' && item.detailsAvailable ? <div className="kayzart-ai-result-actions">
          <button type="button" className="is-restore-before" disabled={position === 'before'} title={position === 'before' ? '現在この状態です' : undefined} onClick={() => void restore(item, 'before')}>変更前に戻す</button>
          <button type="button" className="is-restore-after" disabled={position === 'after'} title={position === 'after' ? '現在この状態です' : undefined} onClick={() => void restore(item, 'after')}>この結果を復元</button>
        </div> : null}
        {executionStatus === 'completed' && !item.detailsAvailable ? <p className="kayzart-ai-expired">変更内容の保持期間が終了しました。</p> : null}
        {executionStatus === 'completed' && (item.model || item.inputTokens !== null || item.outputTokens !== null || item.durationSeconds !== null) ? <details className="kayzart-ai-details"><summary>詳細</summary><dl>
          {item.model ? <><dt>モデル</dt><dd>{item.model}</dd></> : null}
          {item.inputTokens !== null ? <><dt>入力</dt><dd>{item.inputTokens.toLocaleString()} トークン</dd></> : null}
          {item.outputTokens !== null ? <><dt>出力</dt><dd>{item.outputTokens.toLocaleString()} トークン</dd></> : null}
          {item.durationSeconds !== null ? <><dt>処理時間</dt><dd>{formatDuration(item.durationSeconds)}</dd></> : null}
        </dl></details> : null}
        {failed ? <div className="kayzart-ai-result-actions"><button type="button" disabled={running} onClick={() => void send({ prompt: item.prompt || '', contexts: item.contexts as SelectedElementContext[] })}>{__('Run again', 'kayzart-live-code-editor')}</button><button type="button" onClick={() => { setPrompt(item.prompt || ''); promptRef.current?.focus(); }}>{__('Return to input', 'kayzart-live-code-editor')}</button></div> : null}
      </div>
    </div>;
  };

  return <div className="kayzart-ai-panel">
    {ai ? <AvailabilityNotice ai={ai} /> : null}
    {error ? <div className="kayzart-ai-error" role="alert">{error}</div> : null}
    <div className="kayzart-ai-chat" ref={chatRef} role="log" aria-live="polite">
      {hasMore ? <button className="kayzart-ai-load-more" type="button" disabled={loading} onClick={() => void loadOlder()}>{loading ? __('Loading…', 'kayzart-live-code-editor') : __('Load earlier history', 'kayzart-live-code-editor')}</button> : null}
      {!loading && !items.length && !optimistic ? <p className="kayzart-ai-empty">{__('Describe the landing page change you want.', 'kayzart-live-code-editor')}</p> : null}
      {items.map((item) => item.type === 'ai_edit' ? renderAi(item) : item.type === 'save' ? <div className="kayzart-ai-save-divider" key={item.activityId}>
        <span>変更を保存しました・</span>{item.revisionAvailable ? <button type="button" onClick={() => host()?.openSettingsTab?.('history')}>Revision #{item.revisionId}</button> : <><span>Revision #{item.revisionId}</span><em>Revisionは削除済みです</em></>}
      </div> : <div className={`kayzart-ai-system is-${item.restoreTarget === 'before' ? 'before' : 'after'}`} key={item.activityId}>
        <RestoreIcon /><strong>{sprintf(item.restoreTarget === 'before' ? '編集 #%d の変更前に戻しました' : '編集 #%d の結果を復元しました', item.sourceActivityId || 0)}</strong><small>{item.author.name} · {formatDate(item.createdAt)}</small>
      </div>)}
      {optimistic && !items.some((item) => item.requestId === optimistic.requestId) ? <div className="kayzart-ai-exchange">
        <div className="kayzart-ai-message kayzart-ai-message-user"><p>{optimistic.prompt}</p><small>{optimistic.contexts.map(contextLabel).join(', ')}</small></div>
        <div className={`kayzart-ai-result is-${liveJob?.requestId === optimistic.requestId ? liveJob.status : 'pending'}`}><div className="kayzart-ai-result-heading"><AiIcon /><strong>{liveJob?.requestId === optimistic.requestId && liveJob.status === 'running' ? '変更を適用中です' : 'AI編集を待機中です'}</strong></div>
          {liveJob?.requestId === optimistic.requestId && events.length ? <ul className="kayzart-ai-events">{events.slice(-8).map((event, index) => <li key={`${event.requestId}-${index}`}>{eventLabel(event)}</li>)}</ul> : null}
        </div>
      </div> : null}
    </div>
    <div className="kayzart-ai-composer">
      {contexts.length ? <div className="kayzart-ai-contexts">{contexts.map((context) => <span key={context.lcId}>{contextLabel(context)}<button type="button" onClick={() => setContexts(contexts.filter((item) => item.lcId !== context.lcId))} aria-label={__('Remove context', 'kayzart-live-code-editor')}>×</button></span>)}</div> : null}
      <textarea ref={promptRef} value={prompt} rows={4} disabled={!ai?.available} maxLength={MAX_PROMPT_BYTES} onChange={(event) => setPrompt(event.currentTarget.value)} placeholder={__('Example: Make the hero clearer and improve the primary button.', 'kayzart-live-code-editor')} />
      <div className="kayzart-ai-composer-footer"><small className={promptBytes > MAX_PROMPT_BYTES ? 'is-error' : ''}>{promptBytes}/{MAX_PROMPT_BYTES} bytes</small><div>
        {running ? <button type="button" className="is-stop" disabled={canceling} onClick={() => void stop()}>{canceling ? __('Canceling…', 'kayzart-live-code-editor') : __('Stop', 'kayzart-live-code-editor')}</button> : null}
        <button type="button" disabled={!canSend} onClick={() => void send()}>{__('Send', 'kayzart-live-code-editor')}</button>
      </div></div>
    </div>
  </div>;
}

function registerTab() {
  const tab = { id: AI_TAB_ID, label: __('AI Edit', 'kayzart-live-code-editor'), order: 10, mount: (container: HTMLElement) => { const root = createRoot(container); root.render(<AiEditorPanel />); return () => root.unmount(); } };
  const register = host()?.registerSettingsTab;
  if (typeof register === 'function') window.__KAYZART_AI_TAB_UNREGISTER__ = register(tab);
  else { const queue = Array.isArray(window.KAYZART_SETTINGS_TAB_QUEUE) ? window.KAYZART_SETTINGS_TAB_QUEUE : []; queue.push(tab); window.KAYZART_SETTINGS_TAB_QUEUE = queue; }
}
function registerToolbar() {
  const action = { id: TOOLBAR_ACTION_ID, label: __('AI Edit', 'kayzart-live-code-editor'), tooltip: __('Edit with AI', 'kayzart-live-code-editor'), order: 10, placement: 'before-settings' as const,
    icon: '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9.9 15.5A2 2 0 0 0 8.5 14L2.4 12.5a.5.5 0 0 1 0-1L8.5 10A2 2 0 0 0 10 8.5l1.5-6.1a.5.5 0 0 1 1 0L14 8.5a2 2 0 0 0 1.5 1.5l6.1 1.5a.5.5 0 0 1 0 1L15.5 14a2 2 0 0 0-1.5 1.5l-1.5 6.1a.5.5 0 0 1-1 0z"/></svg>', onClick: () => openAi(false) };
  const register = host()?.registerToolbarAction;
  if (typeof register === 'function') window.__KAYZART_AI_TOOLBAR_UNREGISTER__ = register(action);
  else { const queue = Array.isArray(window.KAYZART_TOOLBAR_ACTION_QUEUE) ? window.KAYZART_TOOLBAR_ACTION_QUEUE : []; queue.push(action); window.KAYZART_TOOLBAR_ACTION_QUEUE = queue; }
}
function installContextEntrypoints() {
  window.addEventListener(PREVIEW_ACTION_EVENT, (raw) => { const event = raw as CustomEvent<{ actionId?: string }>; if (event.detail?.actionId === PREVIEW_ACTION_ID) openAi(true); });
  const refresh = () => { const panel = document.querySelector<HTMLElement>(ELEMENTS_PANEL_SELECTOR); if (!panel || panel.querySelector(`.${ELEMENTS_BUTTON_CLASS}`) || !config()?.available) return; const button = document.createElement('button'); button.type = 'button'; button.className = `kayzart-btn kayzart-btn-secondary ${ELEMENTS_BUTTON_CLASS}`; button.textContent = __('Edit with AI', 'kayzart-live-code-editor'); button.addEventListener('click', () => openAi(true)); panel.append(button); };
  new MutationObserver(refresh).observe(document.body, { childList: true, subtree: true }); refresh();
}

registerTab(); registerToolbar(); installContextEntrypoints();
const restored = loadActiveJob(Number(window.KAYZART.post_id || 0));
if (restored) { host()?.setEditorLock?.(true); window.requestAnimationFrame(() => openAi(false)); }
else {
  const ai = config(); const postId = Number(window.KAYZART.post_id || 0);
  if (ai?.timelineUrl && postId > 0) {
    void getTimeline(ai.timelineUrl, window.KAYZART.restNonce || '', postId).then((page) => {
      const active = page.items.some((item) => item.type === 'ai_edit' && item.canPoll && (item.executionStatus === 'pending' || item.executionStatus === 'running'));
      if (active) { host()?.setEditorLock?.(true); window.requestAnimationFrame(() => openAi(false)); }
    }).catch(() => { /* Availability UI reports recoverable REST failures when opened. */ });
  }
}
