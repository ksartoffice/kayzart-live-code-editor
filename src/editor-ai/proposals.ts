import type { AiTimelineItem, EditorSnapshot } from './contract';

/* The open editor checks for proposals often while an agent is at work on
   the page, and rarely otherwise. */
export const PROPOSAL_POLL_ACTIVE_MS = 5000;
export const PROPOSAL_POLL_IDLE_MS = 60000;
export const AGENT_ACTIVE_WINDOW_SECONDS = 600;

export function proposalPollDelay(agentIdleSeconds: number | null | undefined) {
  return typeof agentIdleSeconds === 'number' && agentIdleSeconds < AGENT_ACTIVE_WINDOW_SECONDS
    ? PROPOSAL_POLL_ACTIVE_MS
    : PROPOSAL_POLL_IDLE_MS;
}

/*
 * An external agent never writes the page. Its updates arrive as proposals in
 * the page's history, and a person merges them into the editor and saves.
 * Proposals saved to one working copy form a chain where each includes the
 * ones before it, so merging the newest merges the whole chain.
 */

export type ProposalView =
  /** The editor holds it, not saved yet. */
  | 'merged'
  /** Waiting for the person to merge or discard it. */
  | 'pending'
  /** A later proposal on the same chain includes it. */
  | 'included'
  | 'saved'
  | 'discarded';

type Proposal = AiTimelineItem & { proposal: { agent: string; chain: string } };

export function isProposal(item: AiTimelineItem): item is Proposal {
  return item.type === 'agent_edit' && Boolean(item.proposal);
}

function pendingOnChain(items: AiTimelineItem[], chain: string) {
  return items
    .filter((item): item is Proposal => isProposal(item) && item.applicationStatus === 'pending' && item.proposal.chain === chain)
    .sort((left, right) => left.id - right.id);
}

/** The newest pending proposal of each chain, oldest chain first. */
export function actionableProposals(items: AiTimelineItem[]): Proposal[] {
  const newest = new Map<string, Proposal>();
  items.forEach((item) => {
    if (!isProposal(item) || item.applicationStatus !== 'pending') return;
    const current = newest.get(item.proposal.chain);
    if (!current || item.id > current.id) newest.set(item.proposal.chain, item);
  });
  return [...newest.values()].sort((left, right) => left.id - right.id);
}

/** The pending proposals merging this one takes in: it and the earlier ones on its chain. */
export function includedProposalIds(item: Proposal, items: AiTimelineItem[]) {
  return pendingOnChain(items, item.proposal.chain).filter((candidate) => candidate.id <= item.id).map((candidate) => candidate.id);
}

/*
 * Where a proposal stands is read from the editor's content, never from a
 * record of what was merged, so it cannot drift from what the editor holds:
 * a pending proposal is in the editor exactly when the editor holds its
 * result. The server settles proposals the same way when the page is saved.
 */
export function proposalView(item: Proposal, items: AiTimelineItem[], editorHash: string | null): ProposalView {
  if (item.applicationStatus === 'discarded') return 'discarded';
  if (item.applicationStatus !== 'pending') return 'saved';
  if (pendingOnChain(items, item.proposal.chain).some((candidate) => candidate.id > item.id)) return 'included';
  return editorHash !== null && editorHash === item.afterHash ? 'merged' : 'pending';
}

/**
 * Whether a newly arrived proposal can go straight into the editor as unsaved
 * changes: the editor must hold exactly what the agent started from, so
 * nothing the person has not seen is replaced. The new proposals on a chain
 * build on each other, so the earliest one's starting point is what counts.
 */
export function canMergeAutomatically(
  arrived: Proposal[],
  editor: Pick<EditorSnapshot, 'baseHash' | 'jsMode'> | null,
) {
  if (!editor || !arrived.length) return false;
  const first = [...arrived].sort((left, right) => left.id - right.id)[0];
  return Boolean(first.beforeHash)
    && editor.baseHash === first.beforeHash
    && (!first.beforeJsMode || editor.jsMode === first.beforeJsMode);
}

/**
 * Proposals that arrived since the ones already handled, grouped by chain
 * with the newest of each chain to merge.
 */
export function arrivedProposals(items: AiTimelineItem[], handled: ReadonlySet<number>) {
  return actionableProposals(items)
    .map((newest) => ({
      newest,
      arrived: pendingOnChain(items, newest.proposal.chain).filter((item) => !handled.has(item.id)),
    }))
    .filter((group) => group.arrived.length > 0);
}
