import { describe, expect, it, vi } from 'vitest';
import { createEditorUiController } from '../../../../src/admin/controllers/editor-ui-controller';

function createEditor() {
  return {
    focus: vi.fn(),
    onDidFocusEditorText: vi.fn(),
  } as any;
}

function createUi() {
  const app = document.createElement('div');
  const htmlPane = document.createElement('div');
  const cssPane = document.createElement('div');
  const htmlEditorDiv = document.createElement('div');
  const customHeadEditorDiv = document.createElement('div');
  const customHeadPanel = document.createElement('div');
  const jsModeSelect = document.createElement('select');
  const compactJsModeSelect = document.createElement('select');

  customHeadPanel.appendChild(customHeadEditorDiv);
  cssPane.appendChild(jsModeSelect);

  return {
    app,
    htmlPane,
    cssPane,
    htmlTab: document.createElement('button'),
    customHeadTab: document.createElement('button'),
    cssTab: document.createElement('button'),
    jsTab: document.createElement('button'),
    htmlEditorDiv,
    customHeadEditorDiv,
    cssEditorDiv: document.createElement('div'),
    jsEditorDiv: document.createElement('div'),
    jsModeSelect,
    compactJsModeSelect,
    compactHtmlTab: document.createElement('button'),
    compactCustomHeadTab: document.createElement('button'),
    compactCssTab: document.createElement('button'),
    compactJsTab: document.createElement('button'),
    addMediaButton: document.createElement('button'),
    htmlWordWrapButton: document.createElement('button'),
    compactAddMediaButton: document.createElement('button'),
    jsControls: document.createElement('div'),
    jsPendingNotice: document.createElement('span'),
    compactReloadPendingNotice: document.createElement('span'),
  } as any;
}

describe('editor ui controller', () => {
  it('does not steal focus from JavaScript mode select', () => {
    const ui = createUi();
    const htmlEditor = createEditor();
    const customHeadEditor = createEditor();
    const cssEditor = createEditor();
    const jsEditor = createEditor();

    const controller = createEditorUiController({
      ui,
      canEditJs: true,
      htmlEditor,
      customHeadEditor,
      cssEditor,
      jsEditor,
      compactEditorBreakpoint: 900,
      getViewportWidth: () => 1200,
      getJsEnabled: () => true,
      onOpenMedia: () => {},
    });

    controller.initialize();
    cssEditor.focus.mockClear();
    jsEditor.focus.mockClear();

    ui.jsModeSelect.dispatchEvent(new MouseEvent('click', { bubbles: true }));

    expect(cssEditor.focus).not.toHaveBeenCalled();
    expect(jsEditor.focus).not.toHaveBeenCalled();
  });

  it('shows mode selector only on JavaScript tab', () => {
    const ui = createUi();
    const htmlEditor = createEditor();
    const customHeadEditor = createEditor();
    const cssEditor = createEditor();
    const jsEditor = createEditor();

    const controller = createEditorUiController({
      ui,
      canEditJs: true,
      htmlEditor,
      customHeadEditor,
      cssEditor,
      jsEditor,
      compactEditorBreakpoint: 900,
      getViewportWidth: () => 1200,
      getJsEnabled: () => true,
      onOpenMedia: () => {},
    });

    controller.initialize();
    expect(ui.jsModeSelect.style.display).toBe('none');

    ui.jsTab.click();
    expect(ui.jsModeSelect.style.display).toBe('');

    ui.cssTab.click();
    expect(ui.jsModeSelect.style.display).toBe('none');
  });

  it('hides custom head tabs and falls back to HTML when unfiltered HTML is unavailable', () => {
    const ui = createUi();
    const htmlEditor = createEditor();
    const customHeadEditor = createEditor();
    const cssEditor = createEditor();
    const jsEditor = createEditor();

    const controller = createEditorUiController({
      ui,
      canEditJs: false,
      htmlEditor,
      customHeadEditor,
      cssEditor,
      jsEditor,
      compactEditorBreakpoint: 900,
      getViewportWidth: () => 1200,
      getJsEnabled: () => true,
      onOpenMedia: () => {},
    });

    controller.initialize();
    controller.setHtmlTab('customHead', { focus: true });

    expect(ui.customHeadTab.style.display).toBe('none');
    expect(ui.customHeadTab.disabled).toBe(true);
    expect(ui.compactCustomHeadTab.style.display).toBe('none');
    expect(ui.compactCustomHeadTab.disabled).toBe(true);
    expect(controller.getActiveHtmlTab()).toBe('html');
    expect(htmlEditor.focus).toHaveBeenCalled();
    expect(customHeadEditor.focus).not.toHaveBeenCalled();
  });
});
