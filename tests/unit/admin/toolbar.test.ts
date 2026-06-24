import { afterEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@wordpress/i18n', () => ({
  __: (text: string) => text,
  sprintf: (format: string, value: string) => format.replace('%s', value),
}));

vi.mock('@wordpress/element', async () => {
  const React = await vi.importActual<typeof import('react')>('react');
  const ReactDomClient = await vi.importActual<typeof import('react-dom/client')>(
    'react-dom/client'
  );

  return {
    createElement: React.createElement,
    Fragment: React.Fragment,
    useEffect: React.useEffect,
    useState: React.useState,
    createRoot: ReactDomClient.createRoot,
    render: vi.fn(),
  };
});

describe('toolbar', () => {
  afterEach(() => {
    document.body.replaceChildren();
  });

  const mount = async () => {
    const { mountToolbar } = await import('../../../src/admin/toolbar');
    const container = document.createElement('div');
    document.body.append(container);

    await act(async () => {
      mountToolbar(
        container,
        {
          backUrl: '/wp-admin/',
          listUrl: '',
          listLabel: '',
          canUndo: false,
          canRedo: false,
          editorCollapsed: false,
          compactEditorMode: false,
          settingsOpen: false,
          tailwindEnabled: false,
          viewportMode: 'desktop',
          hasUnsavedChanges: false,
          viewPostUrl: '',
          postStatus: 'draft',
          postTitle: 'Draft page',
          postSlug: 'draft-page',
        },
        {
          onUndo: vi.fn(),
          onRedo: vi.fn(),
          onToggleEditor: vi.fn(),
          onRefreshPreview: vi.fn(),
          onSave: vi.fn(async () => ({ ok: true })),
          onCopyFullHtml: vi.fn(async () => {}),
          onDownloadFullHtml: vi.fn(async () => {}),
          onToggleSettings: vi.fn(),
          onViewportChange: vi.fn(),
          onUpdatePostIdentity: vi.fn(async () => ({ ok: true })),
          onUpdateStatus: vi.fn(async () => ({ ok: true })),
        }
      );
    });

    return container;
  };

  it('renders the full HTML export button', async () => {
    const container = await mount();

    expect(container.querySelector('[aria-label="Export"]')).not.toBeNull();
  });

  it('shows full HTML export actions when opened', async () => {
    const container = await mount();
    const button = container.querySelector('[aria-label="Export"]') as HTMLButtonElement;

    await act(async () => {
      button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    expect(container.textContent).toContain('Copy full HTML');
    expect(container.textContent).toContain('Download HTML');
  });
});
