import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

const beforeSnapshot = { html: '<main>Before</main>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'before' };
const afterSnapshot = { html: '<main>After</main>', customHead: '', css: 'main{}', js: '', jsMode: 'classic' as const, baseHash: 'after' };
const timelineItem = {
  id: 12, activityId: 'activity-12', type: 'ai_edit', jobId: 'job-1', requestId: 'request-1', prompt: 'Improve the hero', contexts: [],
  executionStatus: 'completed', applicationStatus: 'applied', changedTargets: ['html', 'css'], changeStats: { html: { added: 2, removed: 1 }, css: { added: 1, removed: 0 } }, durationSeconds: 18, timeoutMs: 600000, model: 'gpt-4o', inputTokens: 1234, outputTokens: 567, beforeHash: 'before', afterHash: 'after', beforeJsMode: 'classic' as const, afterJsMode: 'classic' as const,
  revisionId: null, sourceActivityId: null, sourcePrompt: null, restoreTarget: null, detailsAvailable: true, canPoll: true,
  revisionAvailable: false, author: { id: 1, name: 'Editor' }, createdAt: '2026-07-15T00:00:00Z', updatedAt: '2026-07-15T00:00:01Z',
};

describe('AiEditorPanel', () => {
  const runCompletedJob = async (options: {
    getCurrent: () => typeof beforeSnapshot | undefined;
    beforeCompletion?: () => void;
    replaceEditorSnapshot?: ReturnType<typeof vi.fn>;
  }) => {
    const replaceEditorSnapshot = options.replaceEditorSnapshot || vi.fn(() => true);
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => options.getCurrent()), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot, setEditorLock,
    };
    let created = false;
    const notAppliedItem = { ...timelineItem, applicationStatus: 'not_applied' as const };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: created ? [notAppliedItem] : [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') { created = true; return json({ ok: true, jobId: 'job-1', requestId: 'request-1', status: 'pending', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: notAppliedItem }, 202); }
      if (url.includes('/jobs/job-1') && !url.includes('/cancel')) {
        options.beforeCompletion?.();
        return json({
          ok: true, jobId: 'job-1', requestId: 'request-1', status: 'completed', events: [], snapshot: afterSnapshot, error: null, usage: null,
          cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt,
          startedAt: timelineItem.createdAt, finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
        });
      }
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
    return { container, fetchMock, replaceEditorSnapshot, root, setEditorLock };
  };

  const runFailedSubmission = async (options: {
    nextDraft?: string;
    failDuringCreate?: boolean;
    terminalStatus?: 'error' | 'canceled';
  }) => {
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    const pendingItem = { ...timelineItem, executionStatus: 'pending' as const, applicationStatus: 'not_applied' as const };
    const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } });
    let resolveFailure!: (response: Response) => void;
    const failure = new Promise<Response>((resolve) => { resolveFailure = resolve; });
    let failureRequested = false;
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return Promise.resolve(json({ ok: true, items: [], hasMore: false, nextCursor: null }));
      if (url === '/jobs' && method === 'POST') {
        failureRequested = Boolean(options.failDuringCreate);
        return options.failDuringCreate
          ? failure
          : Promise.resolve(json({ ok: true, jobId: 'job-failed', requestId: 'request-failed', status: 'pending', statusUrl: '/jobs/job-failed', cancelUrl: '/jobs/job-failed/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: pendingItem }, 202));
      }
      if (url.includes('/jobs/job-failed')) {
        failureRequested = true;
        return failure;
      }
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Describe'));
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
    await act(async () => { setter?.call(textarea, 'Improve the hero'); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    await act(async () => (Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement).click());
    await vi.waitFor(() => expect(failureRequested).toBe(true));
    if (options.nextDraft !== undefined) {
      await act(async () => { setter?.call(textarea, options.nextDraft); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    }

    await act(async () => {
      resolveFailure(options.failDuringCreate
        ? json({ code: 'kayzart_ai_job_create_failed', message: 'Job creation failed.' }, 503)
        : json({
          ok: true, jobId: 'job-failed', requestId: 'request-failed', status: options.terminalStatus || 'error', events: [], snapshot: null,
          error: options.terminalStatus === 'canceled' ? null : { message: 'AI edit failed.' }, usage: null, cancelRequested: false,
          createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt, startedAt: timelineItem.createdAt,
          finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
        }));
      await Promise.resolve();
    });
    await vi.waitFor(() => expect(container.querySelector('.kayzart-ai-error')).not.toBeNull());
    return { root, textarea };
  };

  beforeEach(() => {
    vi.resetModules(); sessionStorage.clear(); document.body.innerHTML = '';
    (window as any).KAYZART = { post_id: 7, restNonce: 'nonce', ai: {
      available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true, schedulerPresent: true, mbstringPresent: true, domPresent: true, canEdit: true,
      jobsUrl: '/jobs', jobsBaseUrl: '/jobs/', timelineUrl: '/timeline', timelineBaseUrl: '/timeline/', connectorsUrl: '/connectors', canManageConnectors: true,
    } };
  });
  afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); sessionStorage.clear(); document.body.innerHTML = ''; });

  it('persists the prompt timeline, hides summary, and applies the completed snapshot', async () => {
    const replaceEditorSnapshot = vi.fn(() => true); const setEditorLock = vi.fn();
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
    await vi.waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(true));
    const createCall = fetchMock.mock.calls.find(([url]) => String(url) === '/jobs');
    expect(JSON.parse(String(createCall?.[1]?.body))).toMatchObject({ post_id: 7, prompt: 'Improve the hero', html: '<main>Before</main>' });
    await vi.waitFor(() => expect(container.textContent).toContain('Improve the hero'));
    expect(container.textContent).not.toContain('Updated hero');
    expect(container.textContent).toContain('Changes were applied.');
    expect(container.textContent).toContain('HTML+2−1');
    expect(container.textContent).toContain('Details');
    const details = container.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
    (container.querySelector('summary') as HTMLElement).click();
    expect(details.open).toBe(true);
    expect(container.textContent).toContain('gpt-4o');
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenCalledWith(true); expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('preserves a new draft when the running job fails', async () => {
    const { root, textarea } = await runFailedSubmission({ nextDraft: 'Add a pricing section.' });
    expect(textarea.value).toBe('Add a pricing section.');
    await act(async () => root.unmount());
  });

  it('restores the submitted prompt after cancellation when the composer is still empty', async () => {
    const { root, textarea } = await runFailedSubmission({ terminalStatus: 'canceled' });
    expect(textarea.value).toBe('Improve the hero');
    await act(async () => root.unmount());
  });

  it('preserves a draft entered while job creation is pending when creation fails', async () => {
    const { root, textarea } = await runFailedSubmission({ nextDraft: 'Add customer testimonials.', failDuringCreate: true });
    expect(textarea.value).toBe('Add customer testimonials.');
    await act(async () => root.unmount());
  });

  it('automatically sends a pending initial request once the editor timeline is ready', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    const replaceEditorSnapshot = vi.fn(() => true);
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot, setEditorLock: vi.fn(),
    };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') return json({ ok: true, jobId: 'job-initial', requestId: 'initial-request-7', status: 'pending', statusUrl: '/jobs/job-initial', cancelUrl: '/jobs/job-initial/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: null }, 202);
      if (url.includes('/jobs/job-initial')) return json({
        ok: true, jobId: 'job-initial', requestId: 'initial-request-7', status: 'completed', events: [], snapshot: afterSnapshot, error: null, usage: null,
        cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt, startedAt: timelineItem.createdAt, finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
      });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url) === '/jobs')).toBe(true));
    const createCall = fetchMock.mock.calls.find(([url]) => String(url) === '/jobs');
    expect(JSON.parse(String(createCall?.[1]?.body))).toMatchObject({ requestId: 'initial-request-7', initialRequestId: 'initial-request-7', prompt: 'Create a product landing page.', post_id: 7 });
    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenCalledWith(afterSnapshot));
    expect((window as any).KAYZART.ai.initialRequest).toBeNull();
    expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/jobs')).toHaveLength(1);
    await act(async () => root.unmount());

    const remountedContainer = document.createElement('div'); document.body.append(remountedContainer); const remountedRoot = createRoot(remountedContainer);
    await act(async () => remountedRoot.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(remountedContainer.textContent).toContain('Describe'));
    expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/jobs')).toHaveLength(1);
    await act(async () => remountedRoot.unmount());
  });

  it('reuses the initial request ID when a failed automatic creation is retried from the composer', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(() => true), setEditorLock: vi.fn(),
    };
    const acceptedItem = { ...timelineItem, jobId: 'job-initial', requestId: 'initial-request-7', prompt: 'Create a product landing page.', executionStatus: 'completed' as const };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const submittedBodies: Array<Record<string, unknown>> = [];
    let createAttempts = 0;
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') {
        createAttempts += 1;
        submittedBodies.push(JSON.parse(String(init?.body)) as Record<string, unknown>);
        if (createAttempts === 1) return json({ code: 'kayzart_ai_job_create_failed', message: 'Job creation failed.' }, 503);
        return json({ ok: true, jobId: 'job-initial', requestId: 'initial-request-7', status: 'pending', statusUrl: '/jobs/job-initial', cancelUrl: '/jobs/job-initial/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: acceptedItem }, 202);
      }
      if (url.includes('/jobs/job-initial')) return json({
        ok: true, jobId: 'job-initial', requestId: 'initial-request-7', status: 'completed', events: [], snapshot: afterSnapshot, error: null, usage: null,
        cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt, startedAt: timelineItem.createdAt, finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
      });
      if (url.includes('/application')) return json({ ok: true, item: acceptedItem });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.querySelector('.kayzart-ai-error')).not.toBeNull());
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    expect(textarea.value).toBe('Create a product landing page.');

    await act(async () => (Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement).click());
    await vi.waitFor(() => expect(submittedBodies).toHaveLength(2));
    expect(submittedBodies.map((body) => body.requestId)).toEqual(['initial-request-7', 'initial-request-7']);
    expect(submittedBodies.map((body) => body.initialRequestId)).toEqual(['initial-request-7', 'initial-request-7']);
    expect((window as any).KAYZART.ai.initialRequest).toBeNull();
    await act(async () => root.unmount());
  });

  it('allocates a fresh request ID after a terminal initial creation failure', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(() => true), setEditorLock: vi.fn(),
    };
    const acceptedItem = { ...timelineItem, jobId: 'job-replacement', requestId: 'request-replacement', prompt: 'Create a revised landing page.', executionStatus: 'completed' as const };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const submittedBodies: Array<Record<string, unknown>> = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') {
        const body = JSON.parse(String(init?.body)) as Record<string, unknown>;
        submittedBodies.push(body);
        if (submittedBodies.length === 1) return json({ code: 'kayzart_ai_timeline_create_failed', message: 'Timeline creation failed.' }, 503);
        return json({ ok: true, jobId: 'job-replacement', requestId: body.requestId, status: 'pending', statusUrl: '/jobs/job-replacement', cancelUrl: '/jobs/job-replacement/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: { ...acceptedItem, requestId: body.requestId } }, 202);
      }
      if (url.includes('/jobs/job-replacement')) return json({
        ok: true, jobId: 'job-replacement', requestId: submittedBodies[1].requestId, status: 'completed', events: [], snapshot: afterSnapshot, error: null, usage: null,
        cancelRequested: false, createdAt: timelineItem.createdAt, updatedAt: timelineItem.updatedAt, startedAt: timelineItem.createdAt, finishedAt: timelineItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000,
      });
      if (url.includes('/application')) return json({ ok: true, item: acceptedItem });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.querySelector('.kayzart-ai-error')).not.toBeNull());
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    const setter = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value')?.set;
    await act(async () => { setter?.call(textarea, 'Create a revised landing page.'); textarea.dispatchEvent(new Event('input', { bubbles: true })); });
    await act(async () => (Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement).click());

    await vi.waitFor(() => expect(submittedBodies).toHaveLength(2));
    expect(submittedBodies[0].requestId).toBe('initial-request-7');
    expect(submittedBodies[1].requestId).not.toBe('initial-request-7');
    expect(submittedBodies[1]).toMatchObject({ initialRequestId: 'initial-request-7', prompt: 'Create a revised landing page.' });
    expect((window as any).KAYZART.ai.initialRequest).toBeNull();
    await act(async () => root.unmount());
  });

  it('reconciles an accepted initial request from the timeline without resubmitting it', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => afterSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(() => true), setEditorLock: vi.fn(),
    };
    const acceptedItem = { ...timelineItem, requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [acceptedItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Create a product landing page.'));
    expect((window as any).KAYZART.ai.initialRequest).toBeNull();
    expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/jobs')).toHaveLength(0);
    await act(async () => root.unmount());
  });

  it('rotates a terminal initial attempt while reusing a replacement ID after a pre-persistence failure', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(() => true), setEditorLock: vi.fn(),
    };
    const enqueueFailedItem = { ...timelineItem, jobId: 'job-initial', requestId: 'initial-request-7', prompt: 'Create a product landing page.', executionStatus: 'enqueue_failed' as const };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const submittedBodies: Array<Record<string, unknown>> = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [enqueueFailedItem], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') {
        submittedBodies.push(JSON.parse(String(init?.body)) as Record<string, unknown>);
        if (submittedBodies.length === 2) return json({ code: 'kayzart_ai_enqueue_failed', message: 'The AI edit job could not be scheduled.' }, 503);
        return json({ code: 'kayzart_ai_job_create_failed', message: 'Job creation failed.' }, 503);
      }
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Create a product landing page.'));
    expect(submittedBodies).toHaveLength(0);

    const sendButton = () => Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-composer-footer button')).at(-1) as HTMLButtonElement;
    await act(async () => sendButton().click());
    await vi.waitFor(() => expect(submittedBodies).toHaveLength(1));
    const firstReplacementId = submittedBodies[0].requestId;
    expect(firstReplacementId).not.toBe('initial-request-7');
    expect(submittedBodies[0].initialRequestId).toBe('initial-request-7');

    await act(async () => sendButton().click());
    await vi.waitFor(() => expect(submittedBodies).toHaveLength(2));
    expect(submittedBodies[1].requestId).toBe(firstReplacementId);

    await act(async () => sendButton().click());
    await vi.waitFor(() => expect(submittedBodies).toHaveLength(3));
    expect(submittedBodies[2].requestId).not.toBe(firstReplacementId);
    expect(submittedBodies[2].initialRequestId).toBe('initial-request-7');
    expect((window as any).KAYZART.ai.initialRequest).toMatchObject({ requestId: 'initial-request-7' });
    await act(async () => root.unmount());
  });

  it('uses a new request ID when rerunning history while an initial request is still pending', async () => {
    (window as any).KAYZART.ai.initialRequest = { requestId: 'initial-request-7', prompt: 'Create a product landing page.' };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot: vi.fn(() => true), setEditorLock: vi.fn(),
    };
    const failedItem = { ...timelineItem, jobId: 'job-failed', requestId: 'old-failed-request', executionStatus: 'error' as const, applicationStatus: 'not_applied' as const };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const submittedBodies: Array<Record<string, unknown>> = [];
    vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [failedItem], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') {
        submittedBodies.push(JSON.parse(String(init?.body)) as Record<string, unknown>);
        return json({ code: 'kayzart_ai_job_create_failed', message: 'Job creation failed.' }, 503);
      }
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.querySelector('.kayzart-ai-error')).not.toBeNull());
    const rerunButton = Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-result-actions button')).find((button) => button.textContent === 'Run again') as HTMLButtonElement;
    await act(async () => rerunButton.click());
    await vi.waitFor(() => expect(submittedBodies).toHaveLength(2));
    expect(submittedBodies[0].requestId).toBe('initial-request-7');
    expect(submittedBodies[1].requestId).not.toBe('initial-request-7');
    expect(String(submittedBodies[1].requestId)).toMatch(/^request-/);
    expect(submittedBodies[1].initialRequestId).toBeUndefined();
    await act(async () => root.unmount());
  });

  it('keeps a changed editor snapshot instead of applying a completed AI result', async () => {
    const divergentSnapshot = { ...beforeSnapshot, html: '<main>History</main>', baseHash: 'history' };
    let current: typeof beforeSnapshot | undefined = beforeSnapshot;
    const result = await runCompletedJob({ getCurrent: () => current, beforeCompletion: () => { current = divergentSnapshot; } });

    await vi.waitFor(() => expect(result.container.textContent).toContain('The editor changed while the AI edit was running.'));
    expect(result.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(result.setEditorLock).toHaveBeenLastCalledWith(false);

    const keep = result.container.querySelector<HTMLButtonElement>('.kayzart-ai-conflict-actions .is-keep') as HTMLButtonElement;
    await act(async () => keep.click());
    expect(result.container.textContent).not.toContain('The editor changed while the AI edit was running.');
    expect(current).toBe(divergentSnapshot);
    expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);
    await act(async () => result.root.unmount());
  });

  it('treats a JavaScript mode-only change as an application conflict', async () => {
    const modeChangedSnapshot = { ...beforeSnapshot, jsMode: 'module' as const };
    let current: any = beforeSnapshot;
    const result = await runCompletedJob({ getCurrent: () => current, beforeCompletion: () => { current = modeChangedSnapshot; } });

    await vi.waitFor(() => expect(result.container.textContent).toContain('The editor changed while the AI edit was running.'));
    expect(result.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);
    await act(async () => result.root.unmount());
  });

  it('applies a conflicting AI result only after the user explicitly chooses it', async () => {
    const divergentSnapshot = { ...beforeSnapshot, html: '<main>History</main>', baseHash: 'history' };
    let current: typeof beforeSnapshot | undefined = beforeSnapshot;
    const replaceEditorSnapshot = vi.fn((snapshot) => { current = snapshot; return true; });
    const result = await runCompletedJob({ getCurrent: () => current, beforeCompletion: () => { current = divergentSnapshot; }, replaceEditorSnapshot });

    await vi.waitFor(() => expect(result.container.textContent).toContain('Replace with AI result'));
    expect(replaceEditorSnapshot).not.toHaveBeenCalled();
    const replace = result.container.querySelector<HTMLButtonElement>('.kayzart-ai-conflict-actions .is-replace') as HTMLButtonElement;
    await act(async () => replace.click());

    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenCalledWith(afterSnapshot));
    await vi.waitFor(() => expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(true));
    expect(current).toStrictEqual(afterSnapshot);
    expect(result.container.textContent).not.toContain('Replace with AI result');
    await act(async () => result.root.unmount());
  });

  it('marks an already-present AI result as applied without replacing it again', async () => {
    let current: typeof beforeSnapshot | undefined = beforeSnapshot;
    const result = await runCompletedJob({ getCurrent: () => current, beforeCompletion: () => { current = afterSnapshot; } });

    await vi.waitFor(() => expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(true));
    expect(result.replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(result.container.textContent).not.toContain('The editor changed while the AI edit was running.');
    await act(async () => result.root.unmount());
  });

  it('explains that DOM support is required when that availability gate fails', async () => {
    (window as any).KAYZART.ai = { ...(window as any).KAYZART.ai, available: false, domPresent: false };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    vi.spyOn(globalThis, 'fetch').mockImplementation(() => Promise.resolve(new Response(JSON.stringify({ ok: true, items: [], hasMore: false, nextCursor: null }), { status: 200, headers: { 'Content-Type': 'application/json' } })));

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));

    await vi.waitFor(() => expect(container.textContent).toContain('The PHP DOM extension is required for AI editing.'));
    await act(async () => root.unmount());
  });

  it('keeps the conflict recoverable when the editor is unavailable or rejects replacement', async () => {
    const divergentSnapshot = { ...beforeSnapshot, html: '<main>History</main>', baseHash: 'history' };
    let current: typeof beforeSnapshot | undefined = beforeSnapshot;
    const replaceEditorSnapshot = vi.fn(() => false);
    const result = await runCompletedJob({ getCurrent: () => current, beforeCompletion: () => { current = divergentSnapshot; }, replaceEditorSnapshot });

    await vi.waitFor(() => expect(result.container.textContent).toContain('Replace with AI result'));
    const replace = result.container.querySelector<HTMLButtonElement>('.kayzart-ai-conflict-actions .is-replace') as HTMLButtonElement;
    await act(async () => replace.click());
    expect(result.container.textContent).toContain('The AI result could not be applied to the editor.');
    expect(result.container.textContent).toContain('Replace with AI result');
    expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);

    current = undefined;
    await act(async () => replace.click());
    expect(result.container.textContent).toContain('The editor state is unavailable. The AI result was not applied.');
    expect(result.fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);
    await act(async () => result.root.unmount());
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
    expect(container.textContent).toContain('Changes were applied.');
    await act(async () => root.unmount());
  });

  it('recovers a running job against its retained input snapshot', async () => {
    const pendingItem = { ...timelineItem, executionStatus: 'running' as const, applicationStatus: 'not_applied' as const, createdAt: new Date(Date.now() - 15 * 60 * 1000).toISOString(), timeoutMs: 1800000 };
    const divergentSnapshot = { ...beforeSnapshot, html: '<main>Saved later</main>', baseHash: 'saved-later' };
    const replaceEditorSnapshot = vi.fn(() => true); const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => divergentSnapshot), getEditorMode: vi.fn(() => 'normal'), replaceEditorSnapshot, setEditorLock,
    };
    const requestOrder: string[] = [];
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET'; requestOrder.push(url);
      if (url.includes('/timeline/12/snapshot')) return json({ ok: true, snapshot: beforeSnapshot });
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [pendingItem], hasMore: false, nextCursor: null });
      if (url.includes('/jobs/job-1')) return json({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'completed', events: [], snapshot: afterSnapshot, error: null, usage: null,
        cancelRequested: false, createdAt: pendingItem.createdAt, updatedAt: pendingItem.updatedAt, startedAt: pendingItem.createdAt,
        finishedAt: pendingItem.updatedAt, pollIntervalMs: 1, timeoutMs: 1800000,
      });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('The editor changed while the AI edit was running.'));
    expect(requestOrder.findIndex((url) => url.includes('/timeline/12/snapshot'))).toBeLessThan(requestOrder.findIndex((url) => url.includes('/jobs/job-1')));
    expect(replaceEditorSnapshot).not.toHaveBeenCalled();
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/application'))).toBe(false);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('does not poll when the retained input snapshot cannot be recovered', async () => {
    const pendingItem = { ...timelineItem, executionStatus: 'running' as const, applicationStatus: 'not_applied' as const, createdAt: new Date().toISOString(), timeoutMs: 1800000 };
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock,
    };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
      const url = String(input);
      if (url.includes('/timeline/12/snapshot')) return json({ code: 'kayzart_ai_snapshot_expired', message: 'Snapshot expired.' }, 410);
      if (url.includes('/timeline')) return json({ ok: true, items: [pendingItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Snapshot expired.'));
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/jobs/job-1'))).toBe(false);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('stops polling a permanent status failure and does not recover it again', async () => {
    const pendingItem = { ...timelineItem, executionStatus: 'running' as const, applicationStatus: 'not_applied' as const, createdAt: new Date().toISOString(), timeoutMs: 600000 };
    sessionStorage.setItem('kayzart.ai.activeJob.7', JSON.stringify({
      version: 1, postId: 7, jobId: 'job-1', requestId: 'request-1', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel',
      pollIntervalMs: 1, timeoutMs: 600000, startedAt: Date.now(), prompt: 'Improve', contexts: [], inputSnapshot: beforeSnapshot, activityId: 12,
    }));
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock,
    };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
      const url = String(input);
      if (url.includes('/jobs/job-1')) return json({ code: 'rest_forbidden', message: 'Permission denied.' }, 403);
      if (url.includes('/timeline')) return json({ ok: true, items: [pendingItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Permission denied.'));
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/jobs/job-1'))).toHaveLength(1);
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/timeline/12/snapshot'))).toBe(false);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('updates the persisted deadline from a successful status response', async () => {
    const serverCreatedAt = new Date(Date.now() - 1000).toISOString();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
      const url = String(input);
      if (url.includes('/jobs/job-1')) return json({
        ok: true, jobId: 'job-1', requestId: 'request-1', status: 'running', events: [{ event: 'progress', requestId: 'request-1', message: 'Still working' }],
        snapshot: null, error: null, usage: null, cancelRequested: false, createdAt: serverCreatedAt, updatedAt: serverCreatedAt,
        startedAt: serverCreatedAt, finishedAt: null, pollIntervalMs: 10000, timeoutMs: 1800000,
      });
      if (url.includes('/timeline')) return json({ ok: true, items: [], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    sessionStorage.setItem('kayzart.ai.activeJob.7', JSON.stringify({
      version: 1, postId: 7, jobId: 'job-1', requestId: 'request-1', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel',
      pollIntervalMs: 1, timeoutMs: 600000, startedAt: Date.now(), prompt: 'Improve', contexts: [], inputSnapshot: beforeSnapshot, activityId: 12,
    }));
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(JSON.parse(String(sessionStorage.getItem('kayzart.ai.activeJob.7'))).timeoutMs).toBe(1800000));
    const stored = JSON.parse(String(sessionStorage.getItem('kayzart.ai.activeJob.7')));
    expect(stored.startedAt).toBe(Date.parse(serverCreatedAt));
    expect(stored.timeoutMs).toBe(1800000);
    expect(stored.pollIntervalMs).toBe(10000);
    await act(async () => root.unmount());
  });

  it('stops retrying transient status failures at the client deadline', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-15T00:00:00Z'));
    const startedAt = Date.now();
    const pendingItem = { ...timelineItem, executionStatus: 'running' as const, applicationStatus: 'not_applied' as const, createdAt: new Date(startedAt).toISOString(), timeoutMs: 30 };
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock,
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input) => {
      const url = String(input);
      if (url.includes('/jobs/job-1')) throw new TypeError('Network unavailable');
      if (url.includes('/timeline')) return json({ ok: true, items: [pendingItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    sessionStorage.setItem('kayzart.ai.activeJob.7', JSON.stringify({
      version: 1, postId: 7, jobId: 'job-1', requestId: 'request-1', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel',
      pollIntervalMs: 1, timeoutMs: 30, startedAt, prompt: 'Improve', contexts: [], inputSnapshot: beforeSnapshot, activityId: 12,
    }));
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => { root.render(<AiEditorPanel />); await Promise.resolve(); });
    await act(async () => {
      await vi.advanceTimersByTimeAsync(30);
    });
    expect(container.textContent).toContain('AI edit timed out while waiting for its status.');
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/jobs/job-1')).length).toBeGreaterThan(1);
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/timeline/12/snapshot'))).toBe(false);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('aborts a hanging status request at the client deadline', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-07-15T00:00:00Z'));
    const startedAt = Date.now();
    const setEditorLock = vi.fn();
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getEditorMode: vi.fn(() => 'normal'), setEditorLock,
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input);
      if (url.includes('/jobs/job-1')) return new Promise((_resolve, reject) => {
        init?.signal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
      });
      if (url.includes('/timeline')) return json({ ok: true, items: [], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    sessionStorage.setItem('kayzart.ai.activeJob.7', JSON.stringify({
      version: 1, postId: 7, jobId: 'job-1', requestId: 'request-1', statusUrl: '/jobs/job-1', cancelUrl: '/jobs/job-1/cancel',
      pollIntervalMs: 1, timeoutMs: 30, startedAt, prompt: 'Improve', contexts: [], inputSnapshot: beforeSnapshot, activityId: 12,
    }));
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => { root.render(<AiEditorPanel />); await Promise.resolve(); });
    await act(async () => { await vi.advanceTimersByTimeAsync(30); });
    expect(container.textContent).toContain('AI edit timed out while waiting for its status.');
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/jobs/job-1'))).toHaveLength(1);
    expect(sessionStorage.getItem('kayzart.ai.activeJob.7')).toBeNull();
    expect(setEditorLock).toHaveBeenLastCalledWith(false);
    await act(async () => root.unmount());
  });

  it('re-resolves timeline selections before rerunning a failed edit', async () => {
    const failedItem = { ...timelineItem, executionStatus: 'error' as const, applicationStatus: 'not_applied' as const, contexts: [{ lcId: 'hero', tagName: 'section', text: 'Hero' }] };
    const resolvedContext = { lcId: 'hero', tagName: 'section', attributes: [{ name: 'class', value: 'hero' }], text: 'Hero', outerHTML: '<section class="hero">Hero</section>', sourceRange: { startOffset: 0, endOffset: 37 } };
    const getElementContext = vi.fn(() => resolvedContext);
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getElementContext,
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    let submitted: any;
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [failedItem], hasMore: false, nextCursor: null });
      if (url === '/jobs' && method === 'POST') {
        submitted = JSON.parse(String(init?.body));
        return json({ ok: true, jobId: 'job-2', requestId: 'request-2', status: 'error', statusUrl: '/jobs/job-2', cancelUrl: '/jobs/job-2/cancel', pollIntervalMs: 1, timeoutMs: 600000, timelineItem: failedItem });
      }
      if (url.includes('/jobs/job-2')) return json({ ok: true, jobId: 'job-2', requestId: 'request-2', status: 'error', events: [], snapshot: null, error: { message: 'Failed' }, usage: null, cancelRequested: false, createdAt: failedItem.createdAt, updatedAt: failedItem.updatedAt, startedAt: failedItem.createdAt, finishedAt: failedItem.updatedAt, pollIntervalMs: 1, timeoutMs: 600000 });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Run again'));
    const rerun = Array.from(container.querySelectorAll<HTMLButtonElement>('button')).find((button) => button.textContent === 'Run again') as HTMLButtonElement;
    await act(async () => rerun.click());

    await vi.waitFor(() => expect(submitted).toBeDefined());
    expect(getElementContext).toHaveBeenCalledWith('hero');
    expect(submitted.selectedContexts).toEqual([resolvedContext]);
    expect(fetchMock.mock.calls.filter(([url]) => String(url) === '/jobs')).toHaveLength(1);
    await act(async () => root.unmount());
  });

  it('does not rerun a selected edit when its timeline element no longer exists', async () => {
    const failedItem = { ...timelineItem, executionStatus: 'error' as const, applicationStatus: 'not_applied' as const, contexts: [{ lcId: 'removed-hero', tagName: 'section', text: 'Hero' }] };
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
      getEditorSnapshot: vi.fn(() => beforeSnapshot), getElementContext: vi.fn(() => null),
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [failedItem], hasMore: false, nextCursor: null });
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Run again'));
    const rerun = Array.from(container.querySelectorAll<HTMLButtonElement>('button')).find((button) => button.textContent === 'Run again') as HTMLButtonElement;
    await act(async () => rerun.click());

    expect(container.textContent).toContain('The selected element is no longer available. Select it again before running the edit.');
    expect(fetchMock.mock.calls.some(([url]) => String(url) === '/jobs')).toBe(false);
    await act(async () => root.unmount());
  });

  it('shows both snapshot actions and confirms only before replacing divergent edits', async () => {
    let currentSnapshot = afterSnapshot;
    let onSnapshotChange: (() => void) | undefined;
    const replaceEditorSnapshot = vi.fn((snapshot) => { currentSnapshot = snapshot; onSnapshotChange?.(); return true; });
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
      getEditorSnapshot: vi.fn(() => currentSnapshot), replaceEditorSnapshot,
      subscribeEditorSnapshot: vi.fn((listener) => { onSnapshotChange = listener; return () => { onSnapshotChange = undefined; }; }),
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const requestOrder: string[] = [];
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      requestOrder.push(`${method} ${url}`);
      if (url.includes('/snapshot') && method === 'GET') {
        const target = new URL(url).searchParams.get('target');
        return json({ ok: true, snapshot: target === 'before' ? beforeSnapshot : afterSnapshot });
      }
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [timelineItem], hasMore: false, nextCursor: null });
      if (url.includes('/restore') && method === 'POST') {
        return json({ ok: true, snapshot: beforeSnapshot, item: null });
      }
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Restore before changes'));
    const actions = () => Array.from(container.querySelectorAll<HTMLButtonElement>('.kayzart-ai-result-actions button'));
    expect(actions()).toHaveLength(2);
    expect(actions()[0].disabled).toBe(false);
    expect(actions()[1].disabled).toBe(true);

    currentSnapshot = { ...afterSnapshot, jsMode: 'module' };
    await act(async () => onSnapshotChange?.());
    expect(actions()[1].disabled).toBe(false);
    currentSnapshot = afterSnapshot;
    await act(async () => onSnapshotChange?.());

    await act(async () => actions()[0].click());
    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenCalledWith(beforeSnapshot));
    expect(requestOrder.findIndex((request) => request.includes('/snapshot'))).toBeLessThan(requestOrder.findIndex((request) => request.includes('/restore')));
    expect(actions()[0].disabled).toBe(true);
    expect(actions()[1].disabled).toBe(false);

    currentSnapshot = { ...beforeSnapshot, html: '<main>Diverged</main>', baseHash: 'diverged' };
    await act(async () => onSnapshotChange?.());
    expect(actions()[0].disabled).toBe(false);
    expect(actions()[1].disabled).toBe(false);
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    await act(async () => actions()[0].click());
    expect(confirm).toHaveBeenCalled();
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/restore'))).toHaveLength(1);
    await act(async () => actions()[1].click());
    await vi.waitFor(() => expect(replaceEditorSnapshot).toHaveBeenLastCalledWith(afterSnapshot));
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/restore'))).toHaveLength(2);
    expect(container.textContent).not.toContain('Your current unsaved changes will be replaced. Continue?');
    await act(async () => root.unmount());
  });

  it('does not record a restore when the editor rejects the snapshot', async () => {
    const replaceEditorSnapshot = vi.fn(() => false);
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
      getEditorSnapshot: vi.fn(() => afterSnapshot), replaceEditorSnapshot,
    };
    const json = (value: unknown) => Promise.resolve(new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/snapshot') && method === 'GET') return json({ ok: true, snapshot: beforeSnapshot });
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [timelineItem], hasMore: false, nextCursor: null });
      if (url.includes('/restore') && method === 'POST') throw new Error('Restore state must not be recorded.');
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Restore before changes'));
    const restoreBefore = container.querySelector<HTMLButtonElement>('.is-restore-before') as HTMLButtonElement;
    await act(async () => restoreBefore.click());

    await vi.waitFor(() => expect(container.textContent).toContain('The edit could not be restored because the editor rejected the snapshot.'));
    expect(replaceEditorSnapshot).toHaveBeenCalledWith(beforeSnapshot);
    expect(fetchMock.mock.calls.some(([url]) => String(url).includes('/restore'))).toBe(false);
    expect(restoreBefore.disabled).toBe(false);
    await act(async () => root.unmount());
  });

  it('reports a history failure after restoring the editor snapshot', async () => {
    let currentSnapshot = afterSnapshot;
    const replaceEditorSnapshot = vi.fn((snapshot) => { currentSnapshot = snapshot; return true; });
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), getEditorMode: vi.fn(() => 'normal'), setEditorLock: vi.fn(),
      getEditorSnapshot: vi.fn(() => currentSnapshot), replaceEditorSnapshot,
    };
    const json = (value: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(value), { status, headers: { 'Content-Type': 'application/json' } }));
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation((input, init) => {
      const url = String(input); const method = init?.method || 'GET';
      if (url.includes('/snapshot') && method === 'GET') return json({ ok: true, snapshot: beforeSnapshot });
      if (url.includes('/timeline') && method === 'GET') return json({ ok: true, items: [timelineItem], hasMore: false, nextCursor: null });
      if (url.includes('/restore') && method === 'POST') return json({ code: 'restore_failed', message: 'History unavailable' }, 500);
      throw new Error(`Unexpected request: ${method} ${url}`);
    });

    const { AiEditorPanel } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div'); document.body.append(container); const root = createRoot(container);
    await act(async () => root.render(<AiEditorPanel />));
    await vi.waitFor(() => expect(container.textContent).toContain('Restore before changes'));
    const restoreBefore = container.querySelector<HTMLButtonElement>('.is-restore-before') as HTMLButtonElement;
    await act(async () => restoreBefore.click());

    await vi.waitFor(() => expect(container.textContent).toContain('The editor was restored, but the edit history could not be updated.'));
    expect(replaceEditorSnapshot).toHaveBeenCalledWith(beforeSnapshot);
    expect(currentSnapshot).toStrictEqual(beforeSnapshot);
    expect(fetchMock.mock.calls.filter(([url]) => String(url).includes('/restore'))).toHaveLength(1);
    expect(restoreBefore.disabled).toBe(true);
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
        snapshot: null, error: null, usage: null, cancelRequested: false, createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
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
    expect(container.textContent).toContain('Applying changes.');
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
