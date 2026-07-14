export type WorkspaceMode = 'creator' | 'client';

export const WORKSPACE_MODE_STORAGE_KEY = 'kayzart.editor.workspaceMode.v1';

type WorkspaceModeStorage = Pick<Storage, 'getItem' | 'setItem'>;

export function readWorkspaceMode(storage?: WorkspaceModeStorage): WorkspaceMode {
  try {
    const value = (storage ?? window.localStorage).getItem(WORKSPACE_MODE_STORAGE_KEY);
    return value === 'client' || value === 'creator' ? value : 'creator';
  } catch {
    return 'creator';
  }
}

export function saveWorkspaceMode(mode: WorkspaceMode, storage?: WorkspaceModeStorage): void {
  try {
    (storage ?? window.localStorage).setItem(WORKSPACE_MODE_STORAGE_KEY, mode);
  } catch {
    // Storage is optional. Keep the active in-memory mode when it is unavailable.
  }
}
