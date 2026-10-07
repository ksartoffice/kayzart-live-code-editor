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

async function fetchJson<T>(url: string, nonce: string, init: RequestInit): Promise<T> {
  const headers = new Headers(init.headers || {});
  headers.set('X-WP-Nonce', nonce);
  if (init.body) headers.set('Content-Type', 'application/json');
  const response = await fetch(url, { ...init, credentials: 'same-origin', headers });
  const refreshedNonce = response.headers.get('X-WP-Nonce');
  if (refreshedNonce && window.KAYZART) window.KAYZART.restNonce = refreshedNonce;
  const text = await response.text();
  let data: Record<string, unknown> = {};
  try {
    data = text ? (JSON.parse(text) as Record<string, unknown>) : {};
  } catch {
    data = {};
  }
  if (!response.ok) {
    const message = typeof data.message === 'string' ? data.message : `REST request failed (${response.status})`;
    throw new AiApiError(message, response.status, typeof data.code === 'string' ? data.code : '');
  }
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

export function getTimeline(url: string, nonce: string, postId: number, before?: number) {
  const target = new URL(url, window.location.origin);
  target.searchParams.set('post_id', String(postId));
  if (before) target.searchParams.set('before', String(before));
  return fetchJson<AiTimelineResponse>(target.toString(), nonce, { method: 'GET' });
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
