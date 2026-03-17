import { describe, expect, it } from 'vitest';
import { buildEditorShell } from '../../../src/admin/editor-shell';

describe('editor shell iframe security attributes', () => {
  it('applies strict referrer policy and sandbox flags', () => {
    const root = document.createElement('div');
    const ui = buildEditorShell(root);
    const sandbox = ui.iframe.getAttribute('sandbox') || '';

    expect(ui.iframe.referrerPolicy).toBe('strict-origin-when-cross-origin');
    expect(sandbox).toContain('allow-scripts');
    expect(sandbox).toContain('allow-same-origin');
    expect(sandbox).toContain('allow-forms');
    expect(sandbox).toContain('allow-modals');
    expect(sandbox).toContain('allow-popups');
    expect(sandbox).toContain('allow-downloads');
    expect(sandbox).toContain('allow-popups-to-escape-sandbox');
    expect(sandbox).not.toContain('allow-top-navigation');
    expect(sandbox).not.toContain('allow-top-navigation-by-user-activation');
  });
});
