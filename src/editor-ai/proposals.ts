import type { AiTimelineItem, EditorSnapshot } from './contract';

/*
 * An external agent never writes the page. Its updates arrive as proposals in
 * the page's history, and a person merges them into the editor and saves.
 * Proposals saved to one working copy form a chain where each includes the
 * ones before it, so merging the newest merges the whole chain.
 */

export type ProposalView =
  /** Merged into the editor in this session and not saved yet. */
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

export function proposalView(item: Proposal, items: AiTimelineItem[], merged: ReadonlySet<number>): ProposalView {
  if (item.applicationStatus === 'discarded') return 'discarded';
  if (item.applicationStatus !== 'pending') return 'saved';
  if (pendingOnChain(items, item.proposal.chain).some((candidate) => candidate.id > item.id)) return 'included';
  return merged.has(item.id) ? 'merged' : 'pending';
}

/**
 * Whether a newly arrived proposal can go straight into the editor as unsaved
 * changes: the editor must hold exactly what the agent started from, so
 * nothing the person has not seen is replaced. The new proposals on a chain
 * build on each other, so the earliest one's starting point is what counts.
 * A title change waits for the person, since titles are saved at once.
 */
export function canMergeAutomatically(
  arrived: Proposal[],
  editor: Pick<EditorSnapshot, 'baseHash' | 'jsMode'> | null,
) {
  if (!editor || !arrived.length || arrived.some((item) => item.titleChange)) return false;
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
