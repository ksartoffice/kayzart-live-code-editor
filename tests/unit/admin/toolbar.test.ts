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

  const mount = async (stateOverrides: Record<string, unknown> = {}) => {
    const { mountToolbar } = await import('../../../src/admin/toolbar');
    const container = document.createElement('div');
    const handlers = {
      onUndo: vi.fn(),
      onRedo: vi.fn(),
      onToggleEditor: vi.fn(),
      onRefreshPreview: vi.fn(),
      onSave: vi.fn(async () => ({ ok: true })),
      onImportFullHtml: vi.fn(),
      onCopyFullHtml: vi.fn(async () => {}),
      onDownloadFullHtml: vi.fn(async () => {}),
      onToggleSettings: vi.fn(),
      onViewportChange: vi.fn(),
      onUpdatePostIdentity: vi.fn(async () => ({ ok: true })),
      onUpdateStatus: vi.fn(async () => ({ ok: true })),
    };
    document.body.append(container);

    await act(async () => {
      const toolbar = mountToolbar(
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
          aiActivity: 'idle',
          tailwindEnabled: false,
          viewportMode: 'desktop',
          hasUnsavedChanges: false,
          saveDisabled: false,
          viewPostUrl: '',
          postStatus: 'draft',
          postTitle: 'Draft page',
          postSlug: 'draft-page',
          ...stateOverrides,
        },
        handlers
      );
    });

    return { container, handlers, toolbar };
  };

  it('renders the import and export button', async () => {
    const { container } = await mount();

    expect(container.querySelector('[aria-label="Import / Export"]')).not.toBeNull();
  });

  it('shows full HTML import and export actions when opened', async () => {
    const { container } = await mount();
    const button = container.querySelector('[aria-label="Import / Export"]') as HTMLButtonElement;

    await act(async () => {
      button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    expect(container.textContent).toContain('Import full HTML');
    expect(container.textContent).toContain('Copy full HTML');
    expect(container.textContent).toContain('Download full HTML');
  });

  it('runs full HTML import from the import and export menu', async () => {
    const { container, handlers } = await mount();
    const button = container.querySelector('[aria-label="Import / Export"]') as HTMLButtonElement;

    await act(async () => {
      button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    const menuItems = Array.from(container.querySelectorAll('[role="menuitem"]'));
    const importItem = menuItems.find((item) => item.textContent === 'Import full HTML') as HTMLButtonElement;

    await act(async () => {
      importItem.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    });

    expect(handlers.onImportFullHtml).toHaveBeenCalledTimes(1);
    expect(container.textContent).not.toContain('Import full HTML');
  });

  it('disables save controls while saving is unavailable', async () => {
    const { container } = await mount({ saveDisabled: true });

    expect(
      (container.querySelector('.kayzart-splitButton-main') as HTMLButtonElement).disabled
    ).toBe(true);
    expect(
      (container.querySelector('.kayzart-splitButton-toggle') as HTMLButtonElement).disabled
    ).toBe(true);
  });

  it('exposes code and tools panel state to assistive technology', async () => {
    const { container } = await mount({ editorCollapsed: true, settingsOpen: true });
    const codeToggle = container.querySelector('[aria-controls="kayzart-code-editors"]');
    const toolsToggle = container.querySelector('#kayzart-settings-toggle');

    expect(codeToggle?.getAttribute('aria-expanded')).toBe('false');
    expect(toolsToggle?.getAttribute('aria-controls')).toBe('kayzart-settings');
    expect(toolsToggle?.getAttribute('aria-expanded')).toBe('true');
  });

  it('shows AI progress on the tools toggle without opening the panel', async () => {
    const { container } = await mount({ aiActivity: 'complete', settingsOpen: false });
    const toolsToggle = container.querySelector('#kayzart-settings-toggle');

    expect(toolsToggle?.getAttribute('aria-expanded')).toBe('false');
    expect(toolsToggle?.getAttribute('aria-label')).toContain('AI edit complete');
    expect(toolsToggle?.classList.contains('is-ai-complete')).toBe(true);
  });
});
