import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const beforeSnapshot = { html: '<main>Before</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'before' };
const afterSnapshot = { html: '<main>After</main>', customHead: '', css: 'main{}', js: '', jsMode: 'classic' as const, baseHash: 'after' };
const timelineItem = {
  id: 12, activityId: 'activity-12', type: 'ai_edit', jobId: 'job-1', requestId: 'request-1', prompt: 'Improve the hero', contexts: [],
  executionStatus: 'completed', applicationStatus: 'applied', changedTargets: ['html', 'css'], changeStats: { html: { added: 2, removed: 1 }, css: { added: 1, removed: 0 } }, durationSeconds: 18, model: 'gpt-4o', inputTokens: 1234, outputTokens: 567, beforeHash: 'before', afterHash: 'after',
  revisionId: null, sourceActivityId: null, sourcePrompt: null, restoreTarget: null, detailsAvailable: true, canPoll: true,
  revisionAvailable: false, author: { id: 1, name: 'Editor' }, createdAt: '2026-07-15T00:00:00Z', updatedAt: '2026-07-15T00:00:01Z',
};

describe('AiEditorPanel', () => {
  beforeEach(() => {
    vi.resetModules(); sessionStorage.clear(); document.body.innerHTML = '';
    (window as any).KAYZART = { post_id: 7, restNonce: 'nonce', ai: {
      available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true, schedulerPresent: true, canEdit: true,
      jobsUrl: '/jobs', jobsBaseUrl: '/jobs/', timelineUrl: '/timeline', timelineBaseUrl: '/timeline/', connectorsUrl: '/connectors', canManageConnectors: true,
    } };
  });
  afterEach(() => { vi.restoreAllMocks(); sessionStorage.clear(); document.body.innerHTML = ''; });

  it('persists the prompt timeline, hides summary, and applies the completed snapshot', async () => {
    const replaceEditorSnapshot = vi.fn(); const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot, setEditorLock,
    };
    let created = false;
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: created ? [timelineItem] : [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') { created = true; return json({ ok: true, jobId: 'job-1', requestId: 'request-1', status: 'pending', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem }, 202); }
      if (url.includes('/jobs/job-1') && !url.includes('/cancel')) return json({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'completed', events: [{ event: 'final', requestId: 'request-1', summary: 'Updated hero' }],
        snapshot: afterSnapshot, error: null, usage: null, cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt,
        startedAt: timelineItem.createdAt, finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
      });
      if (url.includes('/application')) return json({ ok: true, item: timelineItem });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Describe'));
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    await act(async () => { const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set; setter?.call(textarea, 'Improve the hero'); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    await act(async () => (Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement).click());

    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenCalledWith(afterSnapshot));
    const createCall = fetchMock.mock.calls.find(([url]) => String(url) === '/jobs');
    expect(JSON.parse(String(createCall?.[1]?.body))).toMatchObject({ post_id: 7, prompt: 'Improve the hero', html: '<main>Before</main>' });
    await vi.waitFor(() => expect(container.textContent).toContain('Improve the hero'));
    expect(container.textContent).not.toContain('Updated hero');
    expect(container.textContent).toContain('変更を適用しました');
    expect(container.textContent).toContain('HTML+2−1');
    expect(container.textContent).toContain('詳細');
    const details = container.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    (container.querySelector('summary') as HTMLElement).click();
    expect(details.open).toBe(true);
    expect(container.textContent).toContain('gpt-4o');
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenCalledWith(true); expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('restores the persisted timeline after the panel is remounted', async () => {
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ ok: true, items: [timelineItem], hasMore: false, nextCursor: null }), { status: 200, headers: { 'Content-Type': 'application/json' } })));

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Improve the hero'));
    expect(container.textContent).toContain('変更を適用しました');
    await act(async () => root.unmount());
  });

  it('shows live progress when the timeline item is still pending', async () => {
    const pendingTimelineItem = { ...timelineItem, executionStatus: 'pending' as const, applicationStatus: 'not_applied' as const };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    let created = false;
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: created ? [pendingTimelineItem] : [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') { created = true; return json({ ok: true, jobId: 'job-1', requestId: 'request-1', status: 'pending', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: pendingTimelineItem }, 202); }
      if (url.includes('/jobs/job-1') && !url.includes('/cancel')) return json({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'running', events: [{ event: 'progress', requestId: 'request-1', message: 'Generating the update' }],
        snapshot: null, error: null, usage: null, cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt,
        startedAt: timelineItem.createdAt, finishedAt: null, pollIntervalMs: 1, timeoutMs: 600000,
      });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Describe'));
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    await act(async () => { const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set; setter?.call(textarea, 'Improve the hero'); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    await act(async () => (Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement).click());

    await vi.waitFor(() => expect(container.textContent).toContain('Generating the update'));
    expect(container.textContent).toContain('変更を適用中です');
    await act(async () => root.unmount());
  });

  it('reports an initial timeline loading failure instead of leaving an empty panel', async () => {
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ code: 'timeline_failed', message: 'Timeline unavailable' }), { status: 500, headers: { 'Content-Type': 'application/json' } })));

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Timeline unavailable'));
    expect(container.textContent).toContain('Describe the landing page change you want.');
    await act(async () => root.unmount());
  });
});
