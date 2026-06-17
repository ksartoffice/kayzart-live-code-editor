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

  it('renders JavaScript mode selectors with expected options', () => {
    const root = document.createElement('div');
    const ui = buildEditorShell(root);
    const values = Array.from(ui.jsModeSelect.options).map((option) => option.value);
    const compactValues = Array.from(ui.compactJsModeSelect.options).map((option) => option.value);

    expect(values).toEqual(['classic', 'module']);
    expect(compactValues).toEqual(['classic', 'module']);
  });

  it('places JavaScript mode selectors in action areas with expected order', () => {
    const root = document.createElement('div');
    const ui = buildEditorShell(root);

    expect(ui.jsModeSelect.parentElement).toBe(ui.jsControls);
    expect(Array.from(ui.jsControls.children)).toEqual([
      ui.jsPendingNotice,
      ui.jsModeSelect,
    ]);
    expect(Array.from(ui.compactEditorActions.children)).toEqual([
      ui.compactFullHtmlImportButton,
      ui.compactAddMediaButton,
      ui.compactJsModeSelect,
      ui.compactReloadPendingNotice,
    ]);
  });

  it('places full HTML import buttons next to media buttons', () => {
    const root = document.createElement('div');
    const ui = buildEditorShell(root);

    expect(ui.fullHtmlImportButton.textContent).toBe('Import full HTML');
    expect(ui.compactFullHtmlImportButton.textContent).toBe('Import full HTML');
    expect(ui.fullHtmlImportButton.nextElementSibling).toBe(ui.addMediaButton);
    expect(ui.compactFullHtmlImportButton.nextElementSibling).toBe(ui.compactAddMediaButton);
  });

  it('renders custom head tabs and help text', () => {
    const root = document.createElement('div');
    const ui = buildEditorShell(root);

    expect(ui.htmlTab.textContent).toBe('HTML');
    expect(ui.customHeadTab.textContent).toBe('head');
    expect(ui.compactCustomHeadTab.textContent).toBe('head');
    expect(ui.customHeadHelp.textContent).toContain('head');
    expect(ui.customHeadHelp.textContent).toContain('<title>');
  });
});
