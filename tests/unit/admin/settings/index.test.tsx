import { afterEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@wordpress/i18n', () => ({ __: (text: string) => text }));

vi.mock('@wordpress/element', async () => {
  const React = await vi.importActual<typeof import('react')>('react');
  const ReactDom = await vi.importActual<typeof import('react-dom')>('react-dom');
  const ReactDomClient = await vi.importActual<typeof import('react-dom/client')>(
    'react-dom/client'
  );
  return {
    createElement: React.createElement,
    Fragment: React.Fragment,
    createPortal: ReactDom.createPortal,
    createRoot: ReactDomClient.createRoot,
    render: vi.fn(),
    useEffect: React.useEffect,
    useMemo: React.useMemo,
    useRef: React.useRef,
    useState: React.useState,
  };
});

vi.mock('../../../../src/admin/settings/settings-panel', async () => {
  const React = await import('react');
  return { SettingsPanel: () => React.createElement('div', null, 'Settings content') };
});
vi.mock('../../../../src/admin/settings/element-panel', async () => {
  const React = await import('react');
  return {
    ElementPanel: ({ mode }: { mode: string }) =>
      React.createElement('div', null, `Elements content: ${mode}`),
  };
});
vi.mock('../../../../src/admin/settings/history-panel', async () => {
  const React = await import('react');
  return { HistoryPanel: () => React.createElement('div', null, 'History content') };
});

describe('settings workspace tabs', () => {
  afterEach(() => {
    document.body.replaceChildren();
    vi.clearAllMocks();
  });

  it('shows creator tabs, then switches to an Elements-only client panel', async () => {
    const { initSettings } = await import('../../../../src/admin/settings');
    const container = document.createElement('div');
    const header = document.createElement('div');
    document.body.append(header, container);
    let api: ReturnType<typeof initSettings> | null = null;

    await act(async () => {
      api = initSettings({
        container,
        header,
        data: {
          title: 'Page',
          slug: 'page',
          status: 'draft',
          liveHighlightEnabled: true,
          canEditJs: true,
        },
        postId: 1,
        apiFetch: vi.fn(),
        revisionsRestUrl: '/revisions',
        revisionsSupported: true,
        wpVersion: '6.9',
        canUpdateCore: false,
        updateCoreUrl: '',
        hasUnsavedChanges: () => false,
        onLoadSnapshot: () => true,
        workspaceMode: 'creator',
      });
    });

    expect(header.textContent).toContain('Settings');
    expect(header.textContent).toContain('History');
    expect(header.textContent).not.toContain('Elements');

    await act(async () => api?.setWorkspaceMode('client'));

    expect(header.textContent).toBe('Elements');
    expect(header.querySelector('[role="tablist"]')).toBeNull();
    expect(header.querySelector('[aria-label="Close settings panel"]')).toBeNull();
    expect(container.textContent).toContain('Elements content: client');
    expect(container.textContent).not.toContain('Settings content');
  });
});
