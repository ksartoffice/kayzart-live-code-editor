import type {
  AiConnectionState,
  AiCreateJobResponse,
  AiEditRequest,
  AiJobStatusResponse,
  AiTimelineItem,
  AiTimelineResponse,
  AiTimelineRestoreResponse,
  AiTimelineSnapshotResponse,
} from './contract';

export class AiApiError extends Error {
  status: number;
  code: string;

  constructor(message: string, status = 0, code = '') {
    super(message);
    this.name = 'AiApiError';
    this.status = status;
    this.code = code;
  }
}

const INVALID_NONCE_CODE = 'rest_cookie_invalid_nonce';
const INVALID_RESPONSE_CODE = 'kayzart_invalid_response';

type WpApiFetchGlobal = { wp?: { apiFetch?: { nonceEndpoint?: string } } };

function send(url: string, nonce: string, init: RequestInit) {
  const headers = new Headers(init.headers || {});
  headers.set('X-WP-Nonce', nonce);
  // WordPress turns display_errors off only for requests that ask for JSON, so
  // without this a PHP notice from any plugin can corrupt a GET response.
  headers.set('Accept', 'application/json');
  if (init.body) headers.set('Content-Type', 'application/json');
  return fetch(url, { ...init, credentials: 'same-origin', headers });
}

async function isInvalidNonceResponse(response: Response) {
  if (response.status !== 403) return false;
  try {
    const data = await response.clone().json() as { code?: unknown } | null;
    return data?.code === INVALID_NONCE_CODE;
  } catch {
    return false;
  }
}

/* The nonce goes stale after a new login or once it ages out, while the editor
   stays open. WordPress answers that before any route callback runs, so the
   request can be repeated safely with a nonce from the endpoint core's
   wp.apiFetch uses for the same recovery. */
async function fetchFreshNonce(signal?: AbortSignal | null) {
  const endpoint = (window as Window & WpApiFetchGlobal).wp?.apiFetch?.nonceEndpoint;
  if (!endpoint) return '';
  try {
    const response = await fetch(endpoint, { credentials: 'same-origin', signal: signal || undefined });
    const nonce = response.ok ? (await response.text()).trim() : '';
    return /^[A-Za-z0-9]+$/.test(nonce) && nonce !== '0' ? nonce : '';
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === 'AbortError') throw caught;
    return '';
  }
}

async function fetchJson<T>(url: string, nonce: string, init: RequestInit): Promise<T> {
  // Callers may hold the nonce from an earlier render; prefer the latest one.
  const sentNonce = window.KAYZART?.restNonce || nonce;
  let response = await send(url, sentNonce, init);
  if (await isInvalidNonceResponse(response)) {
    const freshNonce = await fetchFreshNonce(init.signal);
    if (freshNonce && freshNonce !== sentNonce) {
      if (window.KAYZART) window.KAYZART.restNonce = freshNonce;
      response = await send(url, freshNonce, init);
    }
  }
  const refreshedNonce = response.headers.get('X-WP-Nonce');
  if (refreshedNonce && window.KAYZART) window.KAYZART.restNonce = refreshedNonce;
  const text = await response.text();
  let data: Record<string, unknown> | null = null;
  try {
    const parsed: unknown = text ? JSON.parse(text) : null;
    if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) data = parsed as Record<string, unknown>;
  } catch {
    data = null;
  }
  if (!response.ok) {
    const message = typeof data?.message === 'string' ? data.message : `REST request failed (${response.status})`;
    throw new AiApiError(message, response.status, typeof data?.code === 'string' ? data.code : '');
  }
  // Status 0 keeps a malformed reply retryable while polling, like a dropped connection.
  if (!data) throw new AiApiError(`Invalid REST response (${response.status})`, 0, INVALID_RESPONSE_CODE);
  return data as T;
}

export async function getAvailability(url: string, nonce: string, postId: number) {
  const target = new URL(url, window.location.origin);
  target.searchParams.set('post_id', String(postId));
  const response = await fetchJson<{ ok: boolean; ai: AiConnectionState }>(target.toString(), nonce, { method: 'GET', cache: 'no-store' });
  if (response.ok !== true || typeof response.ai?.available !== 'boolean') throw new AiApiError('Invalid availability response');
  return response.ai;
}

export function createJob(url: string, nonce: string, payload: AiEditRequest, signal?: AbortSignal) {
  return fetchJson<AiCreateJobResponse>(url, nonce, {
    method: 'POST',
    body: JSON.stringify(payload),
    signal,
  });
}

export function getJob(url: string, nonce: string, signal?: AbortSignal) {
  return fetchJson<AiJobStatusResponse>(url, nonce, { method: 'GET', signal });
}

export function cancelJob(url: string, nonce: string, signal?: AbortSignal) {
  return fetchJson<AiJobStatusResponse>(url, nonce, { method: 'POST', body: '{}', signal });
}

export async function getTimeline(url: string, nonce: string, postId: number, before?: number) {
  const target = new URL(url, window.location.origin);
  target.searchParams.set('post_id', String(postId));
  if (before) target.searchParams.set('before', String(before));
  const response = await fetchJson<AiTimelineResponse>(target.toString(), nonce, { method: 'GET' });
  if (!Array.isArray(response.items)) throw new AiApiError('Invalid timeline response', 0, INVALID_RESPONSE_CODE);
  return response;
}

export function getTimelineSnapshot(baseUrl: string, nonce: string, id: number, target: 'before' | 'after') {
  const url = new URL(`${baseUrl}${id}/snapshot`, window.location.origin);
  url.searchParams.set('target', target);
  return fetchJson<AiTimelineSnapshotResponse>(url.toString(), nonce, { method: 'GET' });
}

export function updateTimelineApplication(baseUrl: string, nonce: string, id: number, status: 'applied' | 'reverted') {
  return fetchJson<{ ok: boolean; item: AiTimelineItem }>(`${baseUrl}${id}/application`, nonce, {
    method: 'POST', body: JSON.stringify({ status }),
  });
}

export function restoreTimeline(baseUrl: string, nonce: string, id: number, target: 'before' | 'after') {
  return fetchJson<AiTimelineRestoreResponse>(`${baseUrl}${id}/restore`, nonce, {
    method: 'POST', body: JSON.stringify({ target }),
  });
}
