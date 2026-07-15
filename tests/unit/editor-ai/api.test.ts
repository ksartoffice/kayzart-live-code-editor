import { afterEach, describe, expect, it, vi } from 'vitest';
import { AiApiError, createJob, getJob } from '../../../src/editor-ai/api';

describe('AI REST client', () => {
  afterEach(() => vi.restoreAllMocks());

  it('sends the REST nonce and JSON request payload', async () => {
    const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true, jobId: 'job-1' }), { status: 202 })
    );
    const payload = {
      requestId: 'request-1', post_id: 3, editorMode: 'normal' as const, prompt: 'Edit',
      html: '<main/>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'hash',
    };
    await createJob('/jobs', 'nonce-value', payload);
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(new Headers(init.headers).get('X-WP-Nonce')).toBe('nonce-value');
    expect(JSON.parse(String(init.body))).toMatchObject({ requestId: 'request-1', post_id: 3 });
  });

  it('uses the WordPress REST message and status for errors', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ code: 'kayzart_ai_post_locked', message: 'Already running.' }), { status: 409 })
    );
    await expect(getJob('/jobs/id', 'nonce')).rejects.toMatchObject<Partial<AiApiError>>({
      message: 'Already running.', status: 409, code: 'kayzart_ai_post_locked',
    });
  });

  it('falls back safely when an error body is not JSON', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(new Response('gateway failure', { status: 503 }));
    await expect(getJob('/jobs/id', 'nonce')).rejects.toThrow('REST request failed (503)');
  });
});
