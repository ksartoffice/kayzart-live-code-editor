import { describe, expect, it } from 'vitest';
import type { AiTimelineItem } from '../../../src/editor-ai/contract';
import {
  actionableProposals, arrivedProposals, canMergeAutomatically, includedProposalIds, proposalView,
} from '../../../src/editor-ai/proposals';

function proposal(id: number, chain: string, before: string, after: string, overrides: Partial<AiTimelineItem> = {}) {
  return {
    id, activityId: `activity-${id}`, type: 'agent_edit', jobId: null, requestId: null, prompt: null, contexts: [],
    executionStatus: 'completed', applicationStatus: 'pending', changedTargets: ['html'], changeStats: null,
    durationSeconds: null, timeoutMs: null, model: null, inputTokens: null, outputTokens: null,
    beforeHash: before, afterHash: after, beforeJsMode: 'classic', afterJsMode: 'classic', revisionId: null,
    sourceActivityId: null, sourcePrompt: null, restoreTarget: null, detailsAvailable: true, canPoll: false,
    revisionAvailable: false, author: { id: 1, name: 'Editor' }, createdAt: '', updatedAt: '',
    agentClient: 'Claude Code', agentAction: 'update', proposal: { agent: 'a', chain }, ...overrides,
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
    expect(includedProposalIds(second, items)).toEqual([10, 11]);
    expect(includedProposalIds(first, items)).toEqual([10]);
  });

  it('describes each proposal for its card', () => {
    expect(proposalView(first, items, new Set())).toBe('included');
    expect(proposalView(second, items, new Set())).toBe('pending');
    expect(proposalView(second, items, new Set([10, 11]))).toBe('merged');
    expect(proposalView(saved, items, new Set())).toBe('saved');
    expect(proposalView(proposal(13, 'c3', 'a', 'b', { applicationStatus: 'discarded' }), items, new Set())).toBe('discarded');
  });

  it('merges automatically only when the editor holds what the agent started from', () => {
    expect(canMergeAutomatically([first, second], { baseHash: 'h0', jsMode: 'classic' })).toBe(true);
    expect(canMergeAutomatically([second], { baseHash: 'h1', jsMode: 'classic' })).toBe(true);
    expect(canMergeAutomatically([second], { baseHash: 'h0', jsMode: 'classic' })).toBe(false);
    expect(canMergeAutomatically([first], { baseHash: 'h0', jsMode: 'module' })).toBe(false);
    expect(canMergeAutomatically([first], null)).toBe(false);
    const renamed = proposal(14, 'c1', 'h0', 'h1', { titleChange: { before: 'A', after: 'B' } });
    expect(canMergeAutomatically([renamed], { baseHash: 'h0', jsMode: 'classic' })).toBe(false);
  });

  it('groups proposals that arrived since the handled ones by chain', () => {
    const groups = arrivedProposals(items, new Set([10]));
    expect(groups.map((group) => [group.newest.id, group.arrived.map((item) => item.id)])).toEqual([[11, [11]], [12, [12]]]);
    expect(arrivedProposals(items, new Set([10, 11, 12]))).toEqual([]);
  });
});
