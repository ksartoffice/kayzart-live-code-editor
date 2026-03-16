import { describe, expect, it } from 'vitest';
import { buildStatusUpdates } from '../../../../src/admin/logic/status-updates';

describe('status update payload', () => {
  it('forces private visibility when status is private', () => {
    expect(buildStatusUpdates('private')).toEqual({
      status: 'private',
      visibility: 'private',
    });
  });

  it('does not send visibility for publish status', () => {
    expect(buildStatusUpdates('publish')).toEqual({
      status: 'publish',
    });
  });

  it('does not send visibility for draft and pending status', () => {
    expect(buildStatusUpdates('draft')).toEqual({
      status: 'draft',
    });
    expect(buildStatusUpdates('pending')).toEqual({
      status: 'pending',
    });
  });
});
