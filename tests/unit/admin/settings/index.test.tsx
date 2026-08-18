import { act } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { initSettings } from '../../../../src/admin/settings';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

describe('initSettings', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('replays editor state updates received before the React API is ready', async () => {
    const container = document.createElement('div');
    document.body.append(container);

    await act(async () => {
      const settings = initSettings({
        container,
        data: {
          title: 'Page',
          slug: 'page',
          status: 'draft',
          liveHighlightEnabled: true,
          canEditJs: true,
        },
        postId: 7,
        apiFetch: vi.fn(),
        revisionsRestUrl: '/revisions',
        revisionsSupported: true,
        wpVersion: '6.9',
        canUpdateCore: false,
        updateCoreUrl: '',
        hasUnsavedChanges: () => false,
        onLoadSnapshot: () => true,
        editorMode: 'normal',
        aiEnabled: false,
        initialTab: 'settings',
      });

      settings.setEditorModeState('tailwind', true);
      settings.setMutationLocked(true);
    });

    const cssMode = container.querySelector<HTMLSelectElement>(
      'select[aria-label="CSS mode"]'
    );
    const templateMode = container.querySelector<HTMLSelectElement>(
      'select[aria-label="Template mode"]'
    );
    expect(cssMode?.value).toBe('tailwind');
    expect(cssMode?.disabled).toBe(true);
    expect(templateMode?.disabled).toBe(true);
  });
});
