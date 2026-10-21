import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const beforeSnapshot = { html: '<main>Before</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'before' };
const afterSnapshot = { html: '<main>After</main>', customHead: '', css: 'main{}', js: '', jsMode: 'classic' as const, baseHash: 'after' };
const agentItem = {
  id: 30, activityId: 'activity-30', type: 'agent_edit', jobId: null, requestId: null, prompt: null, contexts: [],
  executionStatus: 'completed', applicationStatus: 'applied', changedTargets: ['html', 'css'],
  changeStats: { html: { added: 3, removed: 1 }, css: { added: 1, removed: 0 } }, durationSeconds: null, timeoutMs: null,
  model: null, inputTokens: null, outputTokens: null, beforeHash: 'before', afterHash: 'after',
  beforeJsMode: 'classic' as const, afterJsMode: 'classic' as const, revisionId: 101, sourceActivityId: null, sourcePrompt: null,
  restoreTarget: null, detailsAvailable: true, beforeAvailable: true, agentClient: 'Claude Code', agentAction: 'update' as const,
  summary: 'Fixed the pricing heading and removed the dead nav link.', canPoll: false, revisionAvailable: true,
  author: { id: 1, name: 'Editor' }, createdAt: '2026-10-19T00:00:00Z', updatedAt: '2026-10-19T00:00:00Z',
};
const createdItem = {
  ...agentItem, id: 29, activityId: 'activity-29', revisionId: 100, agentAction: 'create' as const, summary: null,
  beforeAvailable: false, beforeHash: 'empty', afterHash: 'before',
};

describe('AiEditorPanel agent edits', () => {
  beforeEach(() => {
    vi.resetModules(); sessionStorage.clear(); document.body.innerHTML = '';
    (window as any).KAYZART = { post_id: 7, restNonce: 'nonce', ai: {
      available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true, schedulerPresent: true, mbstringPresent: true, domPresent: true, canEdit: true,
      jobsUrl: '/jobs', jobsBaseUrl: '/jobs/', timelineUrl: '/timeline', timelineBaseUrl: '/timeline/', connectorsUrl: '/connectors', canManageConnectors: true,
    } };
  });
  afterEach(() => { vi.restoreAllMocks(); sessionStorage.clear(); document.body.innerHTML = ''; });

  it('shows who changed the page and restores the state before an agent edit', async () => {
    const replaceEditorSnapshot = vi.fn(() => true);
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => afterSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot, setEditorLock: vi.fn(),
    };
    const json = (value: unknown) => new Response(JSON.stringify(value), { headers: { 'Content-Type': 'application/json' } });
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input);
      const method = init?.method || 'GET';
      if (url.includes('/timeline/30/snapshot')) return json({ ok: true, snapshot: beforeSnapshot });
      if (url.includes('/timeline/30/restore')) return json({ ok: true, snapshot: beforeSnapshot, item: null });
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [createdItem, agentItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request ${method} ${url}`);
    });
    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));

    const cards = container.querySelectorAll('.kayzart-ai-result.is-agent');
    expect(cards).toHaveLength(2);
    expect(cards[0].textContent).toContain('Claude Code created this page');
    expect(cards[0].querySelector('.is-restore-before')).toBeNull();
    expect(cards[1].textContent).toContain('Claude Code edited this page');
    expect(cards[1].textContent).toContain('Fixed the pricing heading and removed the dead nav link.');
    expect(cards[1].textContent).toContain('External agent (MCP) as Editor');
    expect(cards[1].querySelector('.kayzart-ai-targets')?.textContent).toContain('HTML+3−1');
    expect(cards[1].querySelector<HTMLButtonElement>('.is-restore-after')?.disabled).toBe(true);

    await act(async () => cards[1].querySelector<HTMLButtonElement>('.is-restore-before')!.click());

    expect(replaceEditorSnapshot).toHaveBeenCalledWith(expect.objectContaining({ html: '<main>Before</main>' }));
    expect(fetchMock.mock.calls.some(([input, init]) => String(input).includes('/timeline/30/restore') && init?.method === 'POST')).toBe(true);
    await act(async () => root.unmount());
  });

  it('shows a title-only agent edit with its previous title and no restore actions', async () => {
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => afterSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(), setEditorLock: vi.fn(),
    };
    const renamed = {
      ...agentItem, id: 31, activityId: 'activity-31', changedTargets: [], changeStats: null, revisionId: null,
      detailsAvailable: false, beforeAvailable: false, summary: 'Renamed the page.',
      titleChange: { before: 'Old title', after: 'New title' },
    };
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => new Response(
      JSON.stringify({ ok: true, items: [renamed], hasMore: false, nextCursor: null }),
      { headers: { 'Content-Type': 'application/json' } },
    ));
    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));

    const card = container.querySelector('.kayzart-ai-result.is-agent')!;
    expect(card.querySelector('.kayzart-ai-agent-title')?.textContent).toBe('Title: “Old title” → “New title”');
    expect(card.querySelector('.kayzart-ai-result-actions')).toBeNull();
    expect(card.querySelector('.kayzart-ai-expired')).toBeNull();
    await act(async () => root.unmount());
  });
});
