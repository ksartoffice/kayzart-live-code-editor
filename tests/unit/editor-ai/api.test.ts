import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AiApiError, createJob, getJob, getTimeline, getTimelineSnapshot, restoreTimeline } from '../../../src/editor-ai/api';

describe('AI REST client', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).KAYZART;
    delete (window as any).wp;
  });

  it('sends the REST nonce and JSON request payload', async () => {
    const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ ok: true, jobId: 'job-1' }), { status: 202 })
    );
    const payload = {
      requestId: 'request-1', initialRequestId: 'initial-request-1', post_id: 3, editorMode: 'normal' as const, prompt: 'Edit',
      html: '<main/>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'hash',
    };
    await createJob('/jobs', 'nonce-value', payload);
    const init = fetchMock.mock.calls[0][1] as RequestInit;
    expect(new Headers(init.headers).get('X-WP-Nonce')).toBe('nonce-value');
    expect(JSON.parse(String(init.body))).toMatchObject({ requestId: 'request-1', initialRequestId: 'initial-request-1', post_id: 3 });
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

  it('asks for JSON so WordPress keeps PHP notices out of the response', async () => {
    const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 }));
    await getJob('/jobs/id', 'nonce');
    expect(new Headers((fetchMock.mock.calls[0][1] as RequestInit).headers).get('Accept')).toBe('application/json');
  });

  it('uses the status fallback when an error body is JSON null', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(new Response('null', { status: 503 }));
    await expect(getJob('/jobs/id', 'nonce')).rejects.toMatchObject({ message: 'REST request failed (503)', status: 503 });
  });

  it.each([
    ['JSON null', 'null'],
    ['an HTML page', '<html><body>Please wait</body></html>'],
    ['JSON after a PHP notice', '<br /><b>Notice</b>: x<br />{"ok":true}'],
    ['a JSON array', '[]'],
    ['an empty body', ''],
  ])('rejects a successful response carrying %s as retryable', async (_label, body) => {
    vi.spyOn(window, 'fetch').mockResolvedValue(new Response(body, { status: 200 }));
    await expect(getJob('/jobs/id', 'nonce')).rejects.toMatchObject({ status: 0, code: 'kayzart_invalid_response' });
  });

  it('rejects a timeline response without items', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 }));
    await expect(getTimeline('/timeline', 'nonce', 7)).rejects.toMatchObject({ status: 0, code: 'kayzart_invalid_response' });
  });

  it('uses stable timeline cursors and posts restore targets', async () => {
    const fetchMock = vi.spyOn(window, 'fetch').mockImplementation(() => Promise.resolve(
      new Response(JSON.stringify({ ok: true, items: [], hasMore: false, nextCursor: null, snapshot: {} }), { status: 200 })
    ));
    await getTimeline('/timeline', 'nonce', 7, 51);
    expect(String(fetchMock.mock.calls[0][0])).toContain('post_id=7');
    expect(String(fetchMock.mock.calls[0][0])).toContain('before=51');
    await getTimelineSnapshot('/timeline/', 'nonce', 12, 'before');
    expect(String(fetchMock.mock.calls[1][0])).toContain('/timeline/12/snapshot?target=before');
    await restoreTimeline('/timeline/', 'nonce', 12, 'before');
    expect(String(fetchMock.mock.calls[2][0])).toBe('/timeline/12/restore');
    expect(JSON.parse(String(fetchMock.mock.calls[2][1]?.body))).toEqual({ target: 'before' });
  });

  describe('stale REST nonce', () => {
    const invalidNonce = () => new Response(JSON.stringify({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed' }), { status: 403 });
    const nonceEndpoint = '/wp-admin/admin-ajax.php?action=rest-nonce';
    const payload = {
      requestId: 'request-1', post_id: 3, editorMode: 'normal' as const, prompt: 'Edit',
      html: '<main/>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'hash',
    };
    const nonceOf = (init: unknown) => new Headers((init as RequestInit | undefined)?.headers).get('X-WP-Nonce');

    beforeEach(() => {
      (window as any).KAYZART = { restNonce: 'stale' };
      (window as any).wp = { apiFetch: { nonceEndpoint } };
    });

    it('refreshes the nonce and repeats the request once', async () => {
      const fetchMock = vi.spyOn(window, 'fetch')
        .mockResolvedValueOnce(invalidNonce())
        .mockResolvedValueOnce(new Response('fresh123', { status: 200 }))
        .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, jobId: 'job-1' }), { status: 202 }));
      await expect(createJob('/jobs', 'stale', payload)).resolves.toMatchObject({ jobId: 'job-1' });
      expect(fetchMock).toHaveBeenCalledTimes(3);
      expect(String(fetchMock.mock.calls[1][0])).toBe(nonceEndpoint);
      expect(nonceOf(fetchMock.mock.calls[0][1])).toBe('stale');
      expect(nonceOf(fetchMock.mock.calls[2][1])).toBe('fresh123');
      expect(JSON.parse(String(fetchMock.mock.calls[2][1]?.body))).toMatchObject({ requestId: 'request-1' });
      expect((window as any).KAYZART.restNonce).toBe('fresh123');
    });

    it('sends the latest nonce instead of one captured earlier', async () => {
      (window as any).KAYZART.restNonce = 'latest';
      const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ok: true }), { status: 200 }));
      await getJob('/jobs/id', 'captured');
      expect(nonceOf(fetchMock.mock.calls[0][1])).toBe('latest');
    });

    it('reports the failure when the repeated request is rejected again', async () => {
      const fetchMock = vi.spyOn(window, 'fetch')
        .mockResolvedValueOnce(invalidNonce())
        .mockResolvedValueOnce(new Response('fresh123', { status: 200 }))
        .mockResolvedValueOnce(invalidNonce());
      await expect(getJob('/jobs/id', 'stale')).rejects.toMatchObject({ status: 403, code: 'rest_cookie_invalid_nonce' });
      expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('reports the failure when no fresh nonce is available', async () => {
      const fetchMock = vi.spyOn(window, 'fetch')
        .mockResolvedValueOnce(invalidNonce())
        .mockResolvedValueOnce(new Response('0', { status: 400 }));
      await expect(getJob('/jobs/id', 'stale')).rejects.toMatchObject({ status: 403, code: 'rest_cookie_invalid_nonce' });
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('does not repeat requests rejected for other reasons', async () => {
      const fetchMock = vi.spyOn(window, 'fetch').mockResolvedValue(
        new Response(JSON.stringify({ code: 'rest_forbidden', message: 'Sorry.' }), { status: 403 })
      );
      await expect(createJob('/jobs', 'stale', payload)).rejects.toMatchObject({ status: 403, code: 'rest_forbidden' });
      expect(fetchMock).toHaveBeenCalledTimes(1);
    });
  });
});
