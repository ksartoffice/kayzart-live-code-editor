import { describe, expect, it } from 'vitest';
import type { AiTimelineItem } from '../../../src/editor-ai/contract';
import {
  actionableProposals, importProposal, proposalHash, proposalPollDelay, proposalView,
} from '../../../src/editor-ai/proposals';

function proposal(id: number, chain: string, before: string, after: string, overrides: Partial<AiTimelineItem> = {}) {
  return {
    id, activityId: `activity-${id}`, type: 'agent_edit', jobId: null, requestId: null, prompt: null, contexts: [],
    executionStatus: 'completed', applicationStatus: 'pending', changedTargets: ['html'], changeStats: null,
    durationSeconds: null, timeoutMs: null, model: null, inputTokens: null, outputTokens: null,
    beforeHash: before, afterHash: after, beforeJsMode: 'classic', afterJsMode: 'classic', revisionId: null,
    sourceActivityId: null, sourcePrompt: null, restoreTarget: null, detailsAvailable: true, canPoll: false,
    revisionAvailable: false, author: { id: 1, name: 'Editor' }, createdAt: '', updatedAt: '',
    agentClient: 'Claude Code', agentAction: 'update', proposal: { agent: 'a', chain, beforeHash: before, afterHash: after }, ...overrides,
  } as AiTimelineItem & { proposal: { agent: string; chain: string } };
}

describe('agent proposals', () => {
  const first = proposal(10, 'c1', 'h0', 'h1');
  const second = proposal(11, 'c1', 'h1', 'h2');
  const other = proposal(12, 'c2', 'h0', 'h9', { proposal: { agent: 'b', chain: 'c2' } });
  const saved = proposal(9, 'c0', 'hx', 'h0', { applicationStatus: 'applied' });
  const direct = { ...proposal(8, 'none', '', 'hx'), proposal: null, agentAction: 'create' } as AiTimelineItem;
  const items = [direct, saved, first, second, other];

  it('offers the newest pending proposal of each chain', () => {
    expect(actionableProposals(items).map((item) => item.id)).toEqual([11, 12]);
  });

  it('describes each proposal for its card', () => {
    expect(proposalView(first, items, 'h0')).toBe('included');
    expect(proposalView(second, items, 'h1')).toBe('pending');
    expect(proposalView(second, items, 'h2')).toBe('merged');
    expect(proposalView(second, items, null)).toBe('pending');
    expect(proposalView(saved, items, 'h0')).toBe('saved');
    expect(proposalView(proposal(13, 'c3', 'a', 'b', { applicationStatus: 'discarded' }), items, 'b')).toBe('discarded');
  });

  it('checks often only while an agent has written recently', () => {
    expect(proposalPollDelay(0)).toBe(5000);
    expect(proposalPollDelay(599)).toBe(5000);
    expect(proposalPollDelay(600)).toBe(60000);
    expect(proposalPollDelay(null)).toBe(60000);
    expect(proposalPollDelay(undefined)).toBe(60000);
  });

  it('compares proposal sources independently of JavaScript and recalculates the imported hash', () => {
    const proposed = { html: '<p>Hero 🐈</p>', css: 'p { color: red; }', customHead: '<meta name="description" content="LP">', js: 'old();', jsMode: 'classic' as const, baseHash: 'old-hash' };
    const current = { ...proposed, html: '<p>Original</p>', js: 'newer();', jsMode: 'module' as const };
    const imported = importProposal(proposed, current);
    expect(imported).toMatchObject({ html: proposed.html, css: proposed.css, customHead: proposed.customHead, js: current.js, jsMode: current.jsMode });
    expect(imported.baseHash).not.toBe(proposed.baseHash);
    expect(proposalHash(imported)).toBe(proposalHash(proposed));
    expect(proposalHash({ ...imported, css: 'p { color: blue; }' })).not.toBe(proposalHash(proposed));
  });
});
