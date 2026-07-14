import { describe, expect, it } from 'vitest';
import { resolveWorkspaceMode } from '../../../src/admin/workspace-mode';

describe('resolveWorkspaceMode', () => {
  it('returns the supplied mode when valid', () => {
    expect(resolveWorkspaceMode('creator')).toBe('creator');
    expect(resolveWorkspaceMode('client')).toBe('client');
  });

  it('defaults to creator for missing or invalid values', () => {
    expect(resolveWorkspaceMode(undefined)).toBe('creator');
    expect(resolveWorkspaceMode(null)).toBe('creator');
    expect(resolveWorkspaceMode('invalid')).toBe('creator');
    expect(resolveWorkspaceMode(42)).toBe('creator');
  });
});
