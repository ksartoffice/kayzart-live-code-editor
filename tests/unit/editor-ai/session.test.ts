import { beforeEach, describe, expect, it } from 'vitest';
import type { ActiveJobRecord } from '../../../src/editor-ai/contract';
import { clearActiveJob, loadActiveJob, saveActiveJob } from '../../../src/editor-ai/session';

const record: ActiveJobRecord = {
  version: 1, postId: 12, jobId: 'job', requestId: 'request', statusUrl: '/status', cancelUrl: '/cancel',
  pollIntervalMs: 1000, timeoutMs: 600000, startedAt: 1000, prompt: 'Edit', contexts: [],
  inputSnapshot: { html: '<main/>', customHead: '', css: '', js: '', jsMode: 'classic', baseHash: 'hash' },
};

describe('active AI job session', () => {
  beforeEach(() => sessionStorage.clear());

  it('restores a valid active job for the same post', () => {
    saveActiveJob(record);
    expect(loadActiveJob(12, 2000)).toEqual(record);
    expect(loadActiveJob(13, 2000)).toBeNull();
  });

  it('discards invalid and expired state', () => {
    sessionStorage.setItem('kayzart.ai.activeJob.12', '{"version":2}');
    expect(loadActiveJob(12, 2000)).toBeNull();
    saveActiveJob(record);
    expect(loadActiveJob(12, 700002)).toBeNull();

    sessionStorage.setItem('kayzart.ai.activeJob.12', JSON.stringify({
      ...record,
      statusUrl: 'https://attacker.example/status',
    }));
    expect(loadActiveJob(12, 2000)).toBeNull();
  });

  it('clears terminal state explicitly', () => {
    saveActiveJob(record);
    clearActiveJob(12);
    expect(loadActiveJob(12, 2000)).toBeNull();
  });
});
