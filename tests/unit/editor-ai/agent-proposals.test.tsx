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

function setup(options: { timelines: Timeline[]; editor?: typeof savedSnapshot; signatures?: string[] }) {
  let editor = options.editor || savedSnapshot;
  const replaceEditorSnapshot = vi.fn((snapshot: typeof savedSnapshot) => { editor = snapshot; return true; });
  const setPostTitle = vi.fn(async () => true);
  const createNotice = vi.fn();
  (window as any).wp = { data: { dispatch: () => ({ createNotice }) } };
  (window as any).KAYZART_EXTENSION_API = {
    registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
    getEditorSnapshot: vi.fn(() => editor), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot,
    setEditorLock: vi.fn(), isEditorLocked: vi.fn(() => false), setPostTitle,
  };
  const timelines = [...options.timelines];
  const signatures = [...(options.signatures || ['1'])];
  const json = (value: unknown) => new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } });
  const requests: Array<{ method: string; url: string; body: unknown }> = [];
  const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input);
    const method = init?.method || 'GET';
    requests.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : null });
    if (url.includes('/timeline/state')) return json({ ok: true, signature: signatures.length > 1 ? signatures.shift() : signatures[0] });
    if (url.includes('/timeline/40/snapshot') && url.includes('target=after')) return json({ ok: true, snapshot: proposedSnapshot });
    if (url.includes('/timeline/40/snapshot') && url.includes('target=before')) return json({ ok: true, snapshot: savedSnapshot });
    if (url.includes('/timeline/40/discard')) return json({ ok: true, item: { ...proposalItem, applicationStatus: 'discarded' } });
    if (url.includes('/timeline/merged')) return json({ ok: true, applied: 1 });
    if (url.includes('/timeline') && method === 'GET') {
      const page = timelines.length > 1 ? timelines.shift()! : timelines[0];
      return json({ ok: true, items: page.items, hasMore: false, nextCursor: null });
    }
    throw new Error(`Unexpected request ${method} ${url}`);
  });
  return { replaceEditorSnapshot, setPostTitle, createNotice, fetchMock, requests, editor: () => editor };
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
    await act(async () => root.unmount());
  });

  it('reports merged proposals after the editor saves', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const env = setup({ timelines: [{ items: [proposalItem] }, { items: [{ ...proposalItem, applicationStatus: 'applied' }] }] });
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());

    await act(async () => { window.dispatchEvent(new CustomEvent('kayzart-editor-saved')); await vi.advanceTimersByTimeAsync(200); });

    const merged = env.requests.find((request) => request.url.includes('/timeline/merged'));
    expect(merged?.method).toBe('POST');
    expect(merged?.body).toEqual({ post_id: 7, ids: [40] });
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Saved to the page.');
    await act(async () => root.unmount());
  });

  it('merges a proposal that arrives while the editor holds what the agent started from', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const env = setup({ timelines: [{ items: [] }, { items: [proposalItem] }], signatures: ['1', '2'] });
    const { container, root } = await render();
    expect(env.replaceEditorSnapshot).not.toHaveBeenCalled();

    await act(async () => { await vi.advanceTimersByTimeAsync(5000); });

    expect(env.replaceEditorSnapshot).toHaveBeenCalledWith(expect.objectContaining({ html: '<main>Proposed</main>' }));
    expect(env.createNotice).toHaveBeenCalledWith('info', 'Claude Code changed this page. The changes are in the editor and not saved yet.', expect.anything());
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('In the editor, not saved yet.');
    await act(async () => root.unmount());
  });

  it('only announces a proposal that arrives while the person has other changes', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'setInterval', 'clearInterval'] });
    const edited = { ...savedSnapshot, html: '<main>Edited</main>', baseHash: 'edited' };
    const env = setup({ timelines: [{ items: [] }, { items: [proposalItem] }], signatures: ['1', '2'], editor: edited });
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

  it('discarding a merged proposal puts the editor back and discards it on the server', async () => {
    const env = setup({ timelines: [{ items: [proposalItem] }, { items: [{ ...proposalItem, applicationStatus: 'discarded' }] }] });
    const { container, root } = await render();
    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());
    vi.spyOn(window, 'confirm').mockReturnValue(true);

    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-discard')!.click());

    expect(env.editor().html).toBe('<main>Saved</main>');
    expect(env.requests.some((request) => request.url.includes('/timeline/40/discard') && request.method === 'POST')).toBe(true);
    expect(container.querySelector('.kayzart-ai-result.is-proposal')?.textContent).toContain('Discarded.');
    await act(async () => root.unmount());
  });

  it('a merged title change renames the page', async () => {
    const renamed = { ...proposalItem, titleChange: { before: 'Old', after: 'New' } };
    const env = setup({ timelines: [{ items: [renamed] }] });
    const { container, root } = await render();

    await act(async () => container.querySelector<HTMLButtonElement>('.is-proposal .is-merge')!.click());

    expect(env.setPostTitle).toHaveBeenCalledWith('New');
    await act(async () => root.unmount());
  });
});
