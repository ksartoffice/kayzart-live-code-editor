import type { AiCreateJobResponse, AiEditRequest, AiJobStatusResponse } from './contract';

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
