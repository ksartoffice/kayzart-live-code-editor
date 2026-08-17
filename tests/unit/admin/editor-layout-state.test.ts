import { beforeEach, describe, expect, it } from 'vitest';
import {
  createEditorLayoutStore,
  getEditorLayoutKind,
  resolveInitialEditorLayout,
} from '../../../src/admin/editor-layout-state';

const namespace = 'kayzart.editorLayout.v1.site.4.user.12';

describe('editor layout state', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it('uses separate scoped desktop and compact states', () => {
    const desktop = createEditorLayoutStore({
      namespace,
      kind: 'desktop',
      aiEnabled: true,
      fallbackEditorCollapsed: false,
    });
    const compact = createEditorLayoutStore({
      namespace,
      kind: 'compact',
      aiEnabled: true,
      fallbackEditorCollapsed: false,
    });

    desktop.write({
      version: 1,
      settingsOpen: true,
      settingsTab: 'history',
      editorCollapsed: true,
      settingsWidth: 410,
    });

    expect(desktop.read()).toMatchObject({ settingsOpen: true, settingsTab: 'history' });
    expect(compact.read()).toEqual({
      version: 1,
      settingsOpen: false,
      settingsTab: 'elements',
      editorCollapsed: false,
    });
    expect(desktop.key).not.toBe(compact.key);
  });

  it('migrates the old unscoped panel state once into desktop only', () => {
    localStorage.setItem(
      'kayzart.settingsPanelState',
      JSON.stringify({ open: true, tab: 'settings' })
    );
    localStorage.setItem('kayzart.settingsPanelWidth', '376.4');

    const state = createEditorLayoutStore({
      namespace,
      kind: 'desktop',
      aiEnabled: true,
      fallbackEditorCollapsed: true,
    }).read();

    expect(state).toEqual({
      version: 1,
      settingsOpen: true,
      settingsTab: 'settings',
      editorCollapsed: true,
      settingsWidth: 376,
    });
    expect(localStorage.getItem('kayzart.settingsPanelState')).toBeNull();
    expect(localStorage.getItem('kayzart.settingsPanelWidth')).toBeNull();
  });

  it('falls back safely for corrupt state and an unavailable AI tab', () => {
    const store = createEditorLayoutStore({
      namespace,
      kind: 'desktop',
      aiEnabled: false,
      fallbackEditorCollapsed: true,
    });
    localStorage.setItem(store.key, '{bad json');
    expect(store.read()).toMatchObject({
      settingsOpen: false,
      settingsTab: 'elements',
      editorCollapsed: true,
    });

    localStorage.setItem(
      store.key,
      JSON.stringify({
        version: 1,
        settingsOpen: true,
        settingsTab: 'kayzart-ai',
        editorCollapsed: false,
        settingsWidth: -20,
      })
    );
    expect(store.read()).toEqual({
      version: 1,
      settingsOpen: true,
      settingsTab: 'elements',
      editorCollapsed: false,
      settingsWidth: undefined,
    });
  });

  it('classifies the responsive storage bucket at the editor breakpoint', () => {
    expect(getEditorLayoutKind(899)).toBe('compact');
    expect(getEditorLayoutKind(900)).toBe('desktop');
  });

  it('applies entry-specific layout without overwriting the saved preference', () => {
    const store = createEditorLayoutStore({
      namespace,
      kind: 'desktop',
      aiEnabled: true,
      fallbackEditorCollapsed: false,
    });
    const saved = store.write({
      version: 1,
      settingsOpen: false,
      settingsTab: 'history',
      editorCollapsed: false,
      settingsWidth: 420,
    });

    expect(resolveInitialEditorLayout(saved, 'ai')).toMatchObject({
      settingsOpen: true,
      settingsTab: 'kayzart-ai',
      editorCollapsed: true,
    });
    expect(resolveInitialEditorLayout(saved, 'blank')).toMatchObject({
      settingsOpen: false,
      settingsTab: 'history',
      editorCollapsed: false,
    });
    expect(store.read()).toEqual(saved);
  });
});
