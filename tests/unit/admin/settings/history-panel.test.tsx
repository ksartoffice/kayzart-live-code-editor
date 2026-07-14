import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HistoryPanel } from '../../../../src/admin/settings/history-panel';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

describe('HistoryPanel', () => {
  const roots: Array<ReturnType<typeof createRoot>> = [];

  afterEach(async () => {
    await act(async () => roots.splice(0).forEach((root) => root.unmount()));
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  const renderPanel = async (overrides: Record<string, unknown> = {}) => {
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    roots.push(root);
    const props = {
      postId: 12,
      restUrl: 'https://example.com/wp-json/kayzart/v1/revisions',
      apiFetch: vi.fn().mockResolvedValue({
        ok: true,
        supported: true,
        minVersion: '6.4',
        currentVersion: '6.4',
        revisionsEnabled: true,
        canLoad: true,
        revisions: [],
        pagination: { page: 1, perPage: 20, total: 0, totalPages: 0 },
      }),
      supported: true,
      currentVersion: '6.4',
      canUpdateCore: false,
      updateCoreUrl: '',
      refreshToken: 0,
      hasUnsavedChanges: vi.fn().mockReturnValue(false),
      onLoadSnapshot: vi.fn().mockReturnValue(true),
      ...overrides,
    };
    await act(async () => root.render(<HistoryPanel {...(props as any)} />));
    return { container, props };
  };

  it('shows the WordPress requirement without calling the API', async () => {
    const apiFetch = vi.fn();
    const { container } = await renderPanel({ supported: false, currentVersion: '6.3', apiFetch });
    expect(container.textContent).toContain('Full-page revisions require WordPress 6.4 or later.');
    expect(container.textContent).toContain('This site is running WordPress 6.3.');
    expect(apiFetch).not.toHaveBeenCalled();
  });

  it('shows the core update link only to users allowed to update WordPress', async () => {
    const { container } = await renderPanel({
      supported: false,
      currentVersion: '6.3',
      canUpdateCore: true,
      updateCoreUrl: 'https://example.com/wp-admin/update-core.php',
    });
    const link = container.querySelector('a') as HTMLAnchorElement;
    expect(link.textContent).toContain('Update WordPress');
    expect(link.href).toBe('https://example.com/wp-admin/update-core.php');
    expect(container.textContent).not.toContain('Contact a site administrator');
  });

  it('renders an empty history state', async () => {
    const { container } = await renderPanel();
    expect(container.textContent).toContain('No full-page revisions yet.');
  });

  it('shows existing snapshots alongside the disabled revisions warning', async () => {
    const apiFetch = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        supported: true,
        minVersion: '6.4',
        currentVersion: '6.4',
        revisionsEnabled: false,
        canLoad: true,
        revisions: [{ id: 9, date: '2026-07-14T10:00:00Z', dateGmt: '2026-07-14T10:00:00Z', author: { id: 1, name: 'Admin' }, changedSections: ['css'], isFirst: false }],
        pagination: { page: 1, perPage: 20, total: 1, totalPages: 1 },
      })
      .mockResolvedValueOnce({
        ok: true,
        revision: { id: 9, snapshot: { html: '<main>Saved</main>', customHead: '', css: '.saved{}', js: '', jsMode: 'classic', baseHash: 'saved' } },
      });
    const onLoadSnapshot = vi.fn();
    const { container } = await renderPanel({ apiFetch, onLoadSnapshot });

    expect(container.textContent).toContain('Revisions are disabled for this site.');
    expect(container.textContent).toContain('Admin');
    const button = container.querySelector('.kayzart-historyLoad') as HTMLButtonElement;
    expect(button.disabled).toBe(false);
    await act(async () => button.click());
    expect(onLoadSnapshot).toHaveBeenCalledOnce();
  });

  it('shows only the disabled revisions message when no snapshots exist', async () => {
    const apiFetch = vi.fn().mockResolvedValue({
      ok: true,
      supported: true,
      minVersion: '6.4',
      currentVersion: '6.4',
      revisionsEnabled: false,
      canLoad: true,
      revisions: [],
      pagination: { page: 1, perPage: 20, total: 0, totalPages: 0 },
    });
    const { container } = await renderPanel({ apiFetch });
    expect(container.querySelector('[data-kayzart-history="disabled"]')).not.toBeNull();
    expect(container.querySelector('[data-kayzart-history="list"]')).toBeNull();
    expect(container.textContent).not.toContain('No full-page revisions yet.');
  });

  it('loads a selected revision into the editor', async () => {
    const snapshot = { html: '<main>Old</main>', customHead: '', css: '.old{}', js: '', jsMode: 'classic', baseHash: 'x' };
    const apiFetch = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        supported: true,
        minVersion: '6.4',
        currentVersion: '6.4',
        revisionsEnabled: true,
        canLoad: true,
        revisions: [{ id: 7, date: '2026-07-14T10:00:00Z', dateGmt: '2026-07-14T10:00:00Z', author: { id: 1, name: 'Admin' }, changedSections: ['html'], isFirst: true }],
        pagination: { page: 1, perPage: 20, total: 1, totalPages: 1 },
      })
      .mockResolvedValueOnce({ ok: true, revision: { id: 7, snapshot } });
    const onLoadSnapshot = vi.fn();
    const { container } = await renderPanel({ apiFetch, onLoadSnapshot });
    const button = container.querySelector('.kayzart-historyLoad') as HTMLButtonElement;
    await act(async () => button.click());
    expect(onLoadSnapshot).toHaveBeenCalledWith(snapshot);
  });

  it('does not request revision details when discarding unsaved changes is declined', async () => {
    const apiFetch = vi.fn().mockResolvedValueOnce({
      ok: true,
      supported: true,
      minVersion: '6.4',
      currentVersion: '6.4',
      revisionsEnabled: true,
      canLoad: true,
      revisions: [{ id: 7, date: '2026-07-14T10:00:00Z', dateGmt: '2026-07-14T10:00:00Z', author: { id: 1, name: 'Admin' }, changedSections: ['html'], isFirst: true }],
      pagination: { page: 1, perPage: 20, total: 1, totalPages: 1 },
    });
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const { container } = await renderPanel({
      apiFetch,
      hasUnsavedChanges: vi.fn().mockReturnValue(true),
    });
    await act(async () => (container.querySelector('.kayzart-historyLoad') as HTMLButtonElement).click());
    expect(window.confirm).toHaveBeenCalledOnce();
    expect(apiFetch).toHaveBeenCalledOnce();
  });

  it('disables revision loading without unfiltered HTML permission', async () => {
    const apiFetch = vi.fn().mockResolvedValue({
      ok: true,
      supported: true,
      minVersion: '6.4',
      currentVersion: '6.4',
      revisionsEnabled: true,
      canLoad: false,
      revisions: [{ id: 8, date: '2026-07-14T10:00:00Z', dateGmt: '2026-07-14T10:00:00Z', author: { id: 1, name: 'Author' }, changedSections: [], isFirst: true }],
      pagination: { page: 1, perPage: 20, total: 1, totalPages: 1 },
    });
    const { container } = await renderPanel({ apiFetch });
    expect((container.querySelector('.kayzart-historyLoad') as HTMLButtonElement).disabled).toBe(true);
    expect(container.textContent).toContain('requires unfiltered HTML permission');
  });
});
