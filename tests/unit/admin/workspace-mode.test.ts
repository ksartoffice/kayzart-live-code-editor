import { describe, expect, it, vi } from 'vitest';
import {
  WORKSPACE_MODE_STORAGE_KEY,
  readWorkspaceMode,
  saveWorkspaceMode,
} from '../../../src/admin/workspace-mode';

describe('workspace mode storage', () => {
  it('defaults to creator for missing and invalid values', () => {
    expect(readWorkspaceMode({ getItem: () => null, setItem: vi.fn() })).toBe('creator');
    expect(readWorkspaceMode({ getItem: () => 'invalid', setItem: vi.fn() })).toBe('creator');
  });

  it('persists and restores client mode', () => {
    const values = new Map<string, string>();
    const storage = {
      getItem: (key: string) => values.get(key) ?? null,
      setItem: (key: string, value: string) => values.set(key, value),
    };
    saveWorkspaceMode('client', storage);
    expect(values.get(WORKSPACE_MODE_STORAGE_KEY)).toBe('client');
    expect(readWorkspaceMode(storage)).toBe('client');
  });

  it('falls back safely when storage throws', () => {
    const storage = {
      getItem: () => { throw new Error('blocked'); },
      setItem: () => { throw new Error('blocked'); },
    };
    expect(readWorkspaceMode(storage)).toBe('creator');
    expect(() => saveWorkspaceMode('client', storage)).not.toThrow();
  });
});
