import type { ActiveJobRecord } from './contract';

const PREFIX = 'kayzart.ai.activeJob.';

function key(postId: number) {
  return `${PREFIX}${postId}`;
}

function isSameOriginUrl(value: unknown) {
  if (typeof value !== 'string' || !value) return false;
  try {
    return new URL(value, window.location.origin).origin === window.location.origin;
  } catch {
    return false;
  }
}

export function saveActiveJob(record: ActiveJobRecord) {
  try {
    window.sessionStorage.setItem(key(record.postId), JSON.stringify(record));
  } catch {
    // The server job remains authoritative when browser storage is unavailable.
  }
}

export function clearActiveJob(postId: number) {
  try {
    window.sessionStorage.removeItem(key(postId));
  } catch {
    // Ignore restricted storage environments.
  }
}

export function loadActiveJob(postId: number, now = Date.now()): ActiveJobRecord | null {
  try {
    const raw = window.sessionStorage.getItem(key(postId));
    if (!raw) return null;
    const value = JSON.parse(raw) as Partial<ActiveJobRecord>;
    const valid =
      value.version === 1 &&
      value.postId === postId &&
      typeof value.jobId === 'string' && Boolean(value.jobId) &&
      typeof value.requestId === 'string' && Boolean(value.requestId) &&
      isSameOriginUrl(value.statusUrl) &&
      isSameOriginUrl(value.cancelUrl) &&
      typeof value.startedAt === 'number' &&
      typeof value.timeoutMs === 'number' && value.timeoutMs > 0 &&
      typeof value.prompt === 'string' &&
      Array.isArray(value.contexts) && value.contexts.length <= 20 &&
      Boolean(value.inputSnapshot) && typeof value.inputSnapshot?.html === 'string';
    if (!valid || now - Number(value.startedAt) > Number(value.timeoutMs) + 60000) {
      clearActiveJob(postId);
      return null;
    }
    return value as ActiveJobRecord;
  } catch {
    clearActiveJob(postId);
    return null;
  }
}
