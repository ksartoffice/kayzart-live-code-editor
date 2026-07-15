import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const beforeSnapshot = {
  html: '<main>Before</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'before',
};
const afterSnapshot = {
  html: '<main>After</main>', customHead: '', css: 'main{}', js: '', jsMode: 'classic' as const, baseHash: 'after',
};

describe('AiEditorPanel', () => {
  beforeEach(() => {
    vi.resetModules();
    sessionStorage.clear();
    document.body.innerHTML = '';
    (window as any).KAYZART = {
      post_id: 7,
      restNonce: 'nonce',
      ai: {
        available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true,
        schedulerPresent: true, canEdit: true, jobsUrl: '/jobs', jobsBaseUrl: '/jobs/',
        connectorsUrl: '/connectors', canManageConnectors: true,
      },
    };
  });

  afterEach(() => {
    vi.restoreAllMocks();
    sessionStorage.clear();
    document.body.innerHTML = '';
  });

  it('sends the unsaved snapshot, applies completion, and supports revert and reapply', async () => {
    const replaceEditorSnapshot = vi.fn();
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()),
      registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot),
      getEditorMode: vi.fn(() => 'normal'),
      replaceEditorSnapshot,
      setEditorLock,
    };
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'pending',
        statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel', pollIntervalMs: 1, timeoutMs: 600000,
      }), { status: 202, headers: { 'Content-Type': 'application/json' } }))
      .mockResolvedValueOnce(new Response(JSON.stringify({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'completed',
        events: [{ event: 'final', requestId: 'request-1', summary: 'Updated hero', snapshot: afterSnapshot }],
        snapshot: afterSnapshot, error: null, usage: null, cancelRequested: false,
        createdAt: '2026-07-15T00:00:00Z', updatedAt: '2026-07-15T00:00:01Z',
        startedAt: '2026-07-15T00:00:00Z', finishedAt: '2026-07-15T00:00:01Z',
        pollIntervalMs: 1, timeoutMs: 600000,
      }), { status: 200, headers: { 'Content-Type': 'application/json' } }));

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));

    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    await act(async () => {
      const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
      setter?.call(textarea, 'Improve the hero');
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await act(async () => (container.querySelector('.kayzart-ai-composer-footer button') as HTMLButtonElement).click());

    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenCalledWith(afterSnapshot));
    const requestBody = JSON.parse(String(fetchMock.mock.calls[0][1]?.body));
    expect(requestBody).toMatchObject({
      post_id: 7, editorMode: 'normal', prompt: 'Improve the hero', html: '<main>Before</main>', baseHash: 'before',
    });
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenCalledWith(true);
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    expect(container.textContent).toContain('Updated hero');

    const actions = Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-result-actions button'));
    await act(async () => actions[0].click());
    await act(async () => actions[1].click());
    expect(replaceEditorSnapshot).toHaveBeenNthCalledWith(2, beforeSnapshot);
    expect(replaceEditorSnapshot).toHaveBeenNthCalledWith(3, afterSnapshot);

    await act(async () => root.unmount());
  });
});
