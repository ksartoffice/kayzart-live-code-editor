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
  const jsModeSelect = document.createElement('select');
  const compactJsModeSelect = document.createElement('select');

  cssPane.appendChild(jsModeSelect);

  return {
    app,
    htmlPane,
    cssPane,
    cssTab: document.createElement('button'),
    jsTab: document.createElement('button'),
    cssEditorDiv: document.createElement('div'),
    jsEditorDiv: document.createElement('div'),
    jsModeSelect,
    compactJsModeSelect,
    compactHtmlTab: document.createElement('button'),
    compactCssTab: document.createElement('button'),
    compactJsTab: document.createElement('button'),
    addMediaButton: document.createElement('button'),
    compactAddMediaButton: document.createElement('button'),
    jsControls: document.createElement('div'),
    runButton: document.createElement('button'),
    compactRunButton: document.createElement('button'),
    tailwindHintButton: document.createElement('button'),
    compactTailwindHintButton: document.createElement('button'),
    shadowHintButton: document.createElement('button'),
    compactShadowHintButton: document.createElement('button'),
  } as any;
}

describe('editor ui controller', () => {
  it('does not steal focus from JavaScript mode select', () => {
    const ui = createUi();
    const htmlEditor = createEditor();
    const cssEditor = createEditor();
    const jsEditor = createEditor();

    const controller = createEditorUiController({
      ui,
      canEditJs: true,
      htmlEditor,
      cssEditor,
      jsEditor,
      compactEditorBreakpoint: 900,
      getViewportWidth: () => 1200,
      getJsEnabled: () => true,
      getShadowDomEnabled: () => true,
      getTailwindEnabled: () => false,
      onOpenMedia: () => {},
      onRunJs: () => {},
      onOpenShadowHint: () => {},
      onOpenTailwindHint: () => {},
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
    const cssEditor = createEditor();
    const jsEditor = createEditor();

    const controller = createEditorUiController({
      ui,
      canEditJs: true,
      htmlEditor,
      cssEditor,
      jsEditor,
      compactEditorBreakpoint: 900,
      getViewportWidth: () => 1200,
      getJsEnabled: () => true,
      getShadowDomEnabled: () => true,
      getTailwindEnabled: () => false,
      onOpenMedia: () => {},
      onRunJs: () => {},
      onOpenShadowHint: () => {},
      onOpenTailwindHint: () => {},
    });

    controller.initialize();
    expect(ui.jsModeSelect.style.display).toBe('none');

    ui.jsTab.click();
    expect(ui.jsModeSelect.style.display).toBe('');

    ui.cssTab.click();
    expect(ui.jsModeSelect.style.display).toBe('none');
  });
});
