export type WorkspaceMode = 'creator' | 'client';

/**
 * Resolve the initial workspace mode from the site-wide default supplied by the
 * server. The mode is session-only: switching in the toolbar is not persisted,
 * so every fresh load starts from this default.
 */
export function resolveWorkspaceMode(value: unknown): WorkspaceMode {
  return value === 'client' || value === 'creator' ? value : 'creator';
}
