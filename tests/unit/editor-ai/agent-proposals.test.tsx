import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const savedSnapshot = { html: '<main>Saved</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'saved' };
const proposedSnapshot = { html: '<main>Proposed</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'proposed' };
const proposalItem = {
  id: 40, activityId: 'activity-40', type: 'agent_edit', jobId: null, requestId: null, prompt: null, contexts: [],
  executionStatus: 'completed', applicationStatus: 'pending', changedTargets: ['html'],
  changeStats: { html: { added: 1, removed: 1 } }, durationSeconds: null, timeoutMs: null,
  model: null, inputTokens: null, outputTokens: null, beforeHash: 'saved', afterHash: 'proposed',
  beforeJsMode: 'classic' as const, afterJsMode: 'classic' as const, revisionId: 201, sourceActivityId: null, sourcePrompt: null,
  restoreTarget: null, detailsAvailable: true, beforeAvailable: true, agentClient: 'Claude Code', agentAction: 'update' as const,
  summary: 'Rewrote the hero.', canPoll: false, revisionAvailable: false, proposal: { agent: 'agent', chain: 'chain' },
  author: { id: 1, name: 'Editor' }, createdAt: '2026-10-21T00:00:00Z', updatedAt: '2026-10-21T00:00:00Z',
};

type Timeline = { items: unknown[] };
/* An agent wrote a moment ago, then wrote again. */
const AGENT_AT_WORK = [{ signature: '1', agentIdleSeconds: 30 }, { signature: '2', agentIdleSeconds: 0 }];

type PollState = { signature: string; agentIdleSeconds: number | null };

function setup(options: { timelines: Array<Timeline | Error>; editor?: typeof savedSnapshot; states?: PollState[]; snapshot?: () => Promise<typeof proposedSnapshot> }) {
  let editor = options.editor || savedSnapshot;
  const replaceEditorSnapshot = vi.fn((snapshot: typeof savedSnapshot) => { editor = snapshot; return true; });
  const createNotice = vi.fn();
  (window as any).wp = { data: { dispatch: () => ({ createNotice }) } };
  (window as any).KAYZART_EXTENSION_API = {
    registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
    getEditorSnapshot: vi.fn(() => editor), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot,
    setEditorLock: vi.fn(), isEditorLocked: vi.fn(() => false),
  };
  const timelines = [...options.timelines];
  const states = [...(options.states || [{ signature: '1', agentIdleSeconds: null }])];
  const json = (value: unknown) => new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } });
  const requests: Array<{ method: string; url: string; body: unknown }> = [];
  const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input);
    const method = init?.method || 'GET';
    requests.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : null });
    if (url.includes('/timeline/state')) return json({ ok: true, ...(states.length > 1 ? states.shift() : states[0]) });
    if (url.includes('/timeline/40/snapshot') && url.includes('target=after')) return json({ ok: true, snapshot: options.snapshot ? await options.snapshot() : proposedSnapshot });
    if (url.includes('/timeline/40/snapshot') && url.includes('target=before')) return json({ ok: true, snapshot: savedSnapshot });
    if (url.includes('/timeline/40/discard')) return json({ ok: true, item: { ...proposalItem, applicationStatus: 'discarded' } });
    if (url.includes('/timeline') && method === 'GET') {
      const page = timelines.length > 1 ? timelines.shift()! : timelines[0];
      if (page instanceof Error) throw page;
      return json({ ok: true, items: page.items, hasMore: false, nextCursor: null });
    }
    throw new Error(`Unexpected request ${method} ${url}`);
  });
  return { replaceEditorSnapshot, createNotice, fetchMock, requests, editor: () => editor, setEditor: (next: typeof savedSnapshot) => { editor = next; } };
}

async function render() {
  const { AiEditorPanel } = await import('../../../src/editor-ai/main');
  const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
  await act(async () => root.render(<AiEditorPanel />));
  return { container, root };
}

describe('AiEditorPanel agent proposals', () => {
  beforeEach(() => {
    vi.resetModules(); sessionStorage.clear(); document.body.innerHTML = '';
    (window as any).KAYZART = { post_id: 7, restNonce: 'nonce', ai: {
      available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true, schedulerPresent: true, mbstringPresent: true, domPresent: true, canEdit: true,
      jobsUrl: '/jobs', jobsBaseUrl: '/jobs/', timelineUrl: '/timeline', timelineBaseUrl: '/timeline/', connectorsUrl: '/connectors', canManageConnectors: true,
      agentProposalChecks: 'poll',
    } };
  });
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); sessionStorage.clear(); document.body.innerHTML = ''; delete (window as any).wp; });

  it('announces a waiting proposal on open and merges it only when asked', async () => {
    const env = setup({ timelines: [{ items: [proposalItem] }] });
    const { container, root } = await render();

    expect(container.querySelector('.kayzart-ai-proposal-notice')?.textContent).toContain('1 proposal from an external agent is waiting.');
    const card = container.querySelector('.kayzart-ai-result.is-proposal')!;
    expect(card.textContent).toContain('Claude Code proposed changes to this page');
    expect(card.textContent).toContain('Not in the editor yet.');
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();

    await act(async () => card.querySelector<HTMLButtonElement>('.is-merge')!.click());

    expect(env.replaceEditorSnapshot).toHaveBeenCalledWith(expect.objectContaining({ html: '<main>Proposed</main>' }));
    expect(container.querySelector('.kayzart-ai-proposal-notice')).toBeNull();
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('In the editor, not saved yet.');
    expect(container.querySelector('.is-proposal .is-merge')).toBeNull();
    expect(container.querySelector('.is-proposal .is-discard')).toBeNull();
    expect(card.textContent).toContain('To undo the changes, use Undo in each code editor.');
    await act(async () => root.unmount());
  });

  it('shows a merged proposal as saved once the server settled the save', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const env = setup({ timelines: [{ items: [proposalItem] }, { items: [proposalItem] }, { items: [{ ...proposalItem, applicationStatus: 'applied' }] }] });
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());

    await act(async () => { window.dispatchEvent(new CustomEvent('kayzart-editor-saved')); await vi.advanceTimersByTimeAsync(200); });

    expect(env.requests.some((request) => request.url.includes('/timeline/merged'))).toBe(false);
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Saved to the page.');
    await act(async () => root.unmount());
  });

  it('reads whether a proposal is in the editor from the editor content', async () => {
    const env = setup({ timelines: [{ items: [proposalItem] }] });
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());
    expect(container.querySelector('.is-proposal')?.textContent).toContain('In the editor, not saved yet.');

    // Anything else replaces the editor content, such as restoring an earlier state.
    const host = (window as any).KAYZART_EXTENSION_API;
    const listeners: Array<() => void> = [];
    host.subscribeEditorSnapshot = (listener: () => void) => { listeners.push(listener); return () => undefined; };
    await act(async () => root.unmount());
    const again = await render();
    env.replaceEditorSnapshot(savedSnapshot);
    await act(async () => listeners.forEach((listener) => listener()));

    expect(again.container.querySelector('.is-proposal')?.textContent).toContain('Not in the editor yet.');
    expect(again.container.querySelector('.is-proposal .is-merge')).not.toBeNull();
    await act(async () => again.root.unmount());
  });

  it('announces proposals during a save without replacing the editor', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const env = setup({ timelines: [{ items: [] }, { items: [proposalItem] }], states: AGENT_AT_WORK });
    let saving = true;
    (window as any).KAYZART_EXTENSION_API.isSaving = () => saving;
    const { root } = await render();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.createNotice).toHaveBeenCalledWith('info', 'Claude Code proposed changes to this page. Review them in AI Edit.', expect.anything());

    // The save finishes; its history reload still only announces proposals.
    saving = false;
    await act(async () => { window.dispatchEvent(new CustomEvent('kayzart-editor-saved')); await vi.advanceTimersByTimeAsync(200); });
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    await act(async () => root.unmount());
  });

  it('only announces a proposal even when the editor holds what the agent started from', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const env = setup({ timelines: [{ items: [] }, { items: [] }, { items: [proposalItem] }], states: AGENT_AT_WORK });
    const { container, root } = await render();
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });

    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.createNotice).toHaveBeenCalledWith('info', 'Claude Code proposed changes to this page. Review them in AI Edit.', expect.anything());
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Not in the editor yet.');
    expect(env.requests.some((request) => request.url.includes('/snapshot'))).toBe(false);
    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
    expect(env.createNotice).toHaveBeenCalledTimes(1);
    await act(async () => root.unmount());
  });

  it('finds a proposal that arrived right after the first history load', async () => {
    const env = setup({ timelines: [{ items: [] }, { items: [proposalItem] }], states: [{ signature: '2', agentIdleSeconds: 0 }] });
    const { container, root } = await render();

    expect(container.querySelector('.is-proposal .is-merge')).not.toBeNull();
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    await act(async () => root.unmount());
  });

  it('only announces a proposal that arrives while the person has other changes', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const edited = { ...savedSnapshot, html: '<main>Edited</main>', baseHash: 'edited' };
    const env = setup({ timelines: [{ items: [] }, { items: [] }, { items: [proposalItem] }], states: AGENT_AT_WORK, editor: edited });
    const { container, root } = await render();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });

    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.createNotice).toHaveBeenCalledWith('info', 'Claude Code proposed changes to this page. Review them in AI Edit.', expect.anything());
    expect(container.querySelector('.is-proposal .is-merge')).not.toBeNull();

    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());
    expect(confirm).toHaveBeenCalled();
    expect(env.replaceEditorSnapshot).toHaveBeenCalled();
    await act(async () => root.unmount());
  });

  it('retries a failed history refresh even when the polling signature has not changed', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval', 'clearTimeout'] });
    const env = setup({
      timelines: [{ items: [] }, new Error('Temporary connection failure'), { items: [proposalItem] }],
      states: [{ signature: '2', agentIdleSeconds: 0 }],
    });
    const { container, root } = await render();
    expect(container.querySelector('.is-proposal')).toBeNull();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });

    expect(container.querySelector('.is-proposal .is-merge')).not.toBeNull();
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.createNotice).toHaveBeenCalledTimes(1);
    await act(async () => root.unmount());
  });

  it('checks for edits made while the manual proposal fetch is pending', async () => {
    let resolveSnapshot!: (snapshot: typeof proposedSnapshot) => void;
    const env = setup({
      timelines: [{ items: [proposalItem] }],
      snapshot: () => new Promise((resolve) => { resolveSnapshot = resolve; }),
    });
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());
    env.setEditor({ ...savedSnapshot, html: '<main>Typed while waiting</main>', baseHash: 'typed' });
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);

    await act(async () => resolveSnapshot(proposedSnapshot));

    expect(confirm).toHaveBeenCalledTimes(1);
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.editor().baseHash).toBe('typed');
    await act(async () => root.unmount());
  });

  it('does not apply a manual proposal when a save starts during its fetch', async () => {
    let resolveSnapshot!: (snapshot: typeof proposedSnapshot) => void;
    const env = setup({
      timelines: [{ items: [proposalItem] }],
      snapshot: () => new Promise((resolve) => { resolveSnapshot = resolve; }),
    });
    let saving = false;
    (window as any).KAYZART_EXTENSION_API.isSaving = () => saving;
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());
    saving = true;

    await act(async () => resolveSnapshot(proposedSnapshot));

    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(container.querySelector('.is-proposal .is-merge')).not.toBeNull();
    await act(async () => root.unmount());
  });

  it('discards a waiting proposal without changing unsaved content or fetching a rollback snapshot', async () => {
    const edited = { ...savedSnapshot, html: '<main>Edited</main>', baseHash: 'edited' };
    const env = setup({ editor: edited, timelines: [{ items: [proposalItem] }, { items: [proposalItem] }, { items: [{ ...proposalItem, applicationStatus: 'discarded' }] }] });
    const { container, root } = await render();
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-discard')!.click());

    expect(env.editor()).toEqual(edited);
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(env.requests.some((request) => request.url.includes('/snapshot'))).toBe(false);
    expect(env.requests.some((request) => request.url.includes('/timeline/40/discard') && request.method === 'POST')).toBe(true);
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Discarded.');
    await act(async () => root.unmount());
  });

  it('never checks where agents cannot connect', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval', 'clearTimeout'] });
    (window as any).KAYZART.ai.agentProposalChecks = 'none';
    const env = setup({ timelines: [{ items: [] }] });
    const { root } = await render();

    await act(async () => { await vi.advanceTimersByTimeAsync(10 * 60 * 1000); window.dispatchEvent(new Event('focus')); });

    expect(env.requests.filter((request) => request.url.includes('/timeline/state'))).toHaveLength(0);
    await act(async () => root.unmount());
  });

  it('checks only when the person comes back where no agent has been used yet', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval', 'clearTimeout', 'Date'] });
    (window as any).KAYZART.ai.agentProposalChecks = 'focus';
    const env = setup({ timelines: [{ items: [] }, { items: [] }, { items: [proposalItem] }], states: AGENT_AT_WORK });
    const { container, root } = await render();
    const checks = () => env.requests.filter((request) => request.url.includes('/timeline/state')).length;

    await act(async () => { await vi.advanceTimersByTimeAsync(10 * 60 * 1000); });
    expect(checks()).toBe(1);

    // The person asked the agent in another window and comes back.
    await act(async () => { window.dispatchEvent(new Event('focus')); await vi.advanceTimersByTimeAsync(0); });

    expect(checks()).toBe(2);
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Not in the editor yet.');
    await act(async () => root.unmount());
  });

  it('checks once a minute while no agent is at work on the page', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval', 'clearTimeout'] });
    const env = setup({ timelines: [{ items: [] }], states: [{ signature: '1', agentIdleSeconds: 3600 }] });
    const { root } = await render();
    const checks = () => env.requests.filter((request) => request.url.includes('/timeline/state')).length;
    expect(checks()).toBe(1);

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });
    expect(checks()).toBe(1);
    await act(async () => { await vi.advanceTimersByTimeAsync(55000); });
    expect(checks()).toBe(2);
    await act(async () => root.unmount());
  });
});
