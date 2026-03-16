export type EditablePostStatus = 'draft' | 'pending' | 'private' | 'publish';

type StatusUpdates =
  | { status: 'private'; visibility: 'private' }
  | { status: Exclude<EditablePostStatus, 'private'> };

export function buildStatusUpdates(nextStatus: EditablePostStatus): StatusUpdates {
  if (nextStatus === 'private') {
    return { status: 'private', visibility: 'private' };
  }

  return { status: nextStatus };
}
