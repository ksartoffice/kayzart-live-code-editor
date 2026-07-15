import { describe, expect, it } from 'vitest';
import { computeChangedTargets, isTerminalStatus, positiveInteger } from '../../../src/editor-ai/polling';

const snapshot = { html: '<h1>A</h1>', customHead: '', css: '', js: '', jsMode: 'classic' as const, baseHash: 'a' };

describe('AI polling helpers', () => {
  it('recognizes every terminal status', () => {
    expect(['completed', 'error', 'canceled', 'timed_out', 'enqueue_failed'].every((status) => isTerminalStatus(status as any))).toBe(true);
    expect(isTerminalStatus('running')).toBe(false);
  });

  it('normalizes invalid intervals', () => {
    expect(positiveInteger(1250.9, 1000)).toBe(1250);
    expect(positiveInteger(0, 1000)).toBe(1000);
  });

  it('reports changed editor targets including JS mode', () => {
    expect(computeChangedTargets(snapshot, { ...snapshot, html: '<h1>B</h1>', jsMode: 'module' })).toEqual(['html', 'js']);
  });
});
