import type { AiJobStatus, ChangedTarget, EditorSnapshot } from './contract';

export const DEFAULT_POLL_INTERVAL_MS = 1000;
export const DEFAULT_TIMEOUT_MS = 600000;

export function positiveInteger(value: number, fallback: number) {
  return Number.isFinite(value) && value > 0 ? Math.trunc(value) : fallback;
}

export function isTerminalStatus(status: AiJobStatus) {
  return ['completed', 'error', 'canceled', 'timed_out', 'enqueue_failed'].includes(status);
}

export function sameSnapshotIdentity(left: Pick<EditorSnapshot, 'baseHash' | 'jsMode'>, right: Pick<EditorSnapshot, 'baseHash' | 'jsMode'>) {
  return left.baseHash === right.baseHash && left.jsMode === right.jsMode;
}

export function isRetryableHttpStatus(status: number) {
  return status === 0 || status === 408 || status === 429 || status >= 500;
}

export function computeChangedTargets(before: EditorSnapshot, after: EditorSnapshot): ChangedTarget[] {
  const changed: ChangedTarget[] = [];
  if (before.html !== after.html) changed.push('html');
  if (before.customHead !== after.customHead) changed.push('head');
  if (before.css !== after.css) changed.push('css');
  if (before.js !== after.js || before.jsMode !== after.jsMode) changed.push('js');
  return changed;
}

export function sleep(ms: number, signal: AbortSignal) {
  return new Promise<void>((resolve, reject) => {
    const id = window.setTimeout(resolve, ms);
    signal.addEventListener(
      'abort',
      () => {
        window.clearTimeout(id);
        reject(new DOMException('Polling stopped', 'AbortError'));
      },
      { once: true }
    );
  });
}
