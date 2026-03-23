import type { EditorShellRefs } from '../editor-shell';
import type { CodeEditorInstance } from '../codemirror';

type EditorInstance = CodeEditorInstance;
type CssTab = 'css' | 'js';
type CompactEditorTab = 'html' | 'css' | 'js';

type EditorUiControllerDeps = {
  ui: EditorShellRefs;
  canEditJs: boolean;
  htmlEditor: EditorInstance;
  cssEditor: EditorInstance;
  jsEditor: EditorInstance;
  compactEditorBreakpoint: number;
  getViewportWidth: () => number;
  getJsEnabled: () => boolean;
  getShadowDomEnabled: () => boolean;
  getTailwindEnabled: () => boolean;
  onActiveEditorChange?: (editor: EditorInstance) => void;
  onCompactEditorModeChange?: (isCompact: boolean) => void;
  onOpenMedia: () => void;
  onRunJs: () => void;
  onOpenShadowHint: () => void;
  onOpenTailwindHint: () => void;
};

export function createEditorUiController(deps: EditorUiControllerDeps) {
  let activeEditor: EditorInstance | null = null;
  let activeCssTab: CssTab = 'css';
  let compactEditorMode = false;
  let compactEditorTab: CompactEditorTab = 'html';
  let editorsReady = false;

  const syncCompactEditorUi = () => {
    const isHtmlTab = compactEditorTab === 'html';
    const isCssTab = compactEditorTab === 'css';
    const isJsTab = compactEditorTab === 'js';
    const tailwindEnabled = deps.getTailwindEnabled();
    deps.ui.compactHtmlTab.classList.toggle('is-active', compactEditorTab === 'html');
    deps.ui.compactCssTab.classList.toggle('is-active', compactEditorTab === 'css');
    deps.ui.compactJsTab.classList.toggle('is-active', compactEditorTab === 'js');
    deps.ui.htmlPane.classList.toggle('is-compact-visible', compactEditorTab === 'html');
    deps.ui.cssPane.classList.toggle('is-compact-visible', compactEditorTab !== 'html');
    deps.ui.compactAddMediaButton.style.display = isHtmlTab ? '' : 'none';
    deps.ui.compactJsModeSelect.style.display = isJsTab && deps.canEditJs ? '' : 'none';
    deps.ui.compactRunButton.style.display = isJsTab && deps.canEditJs ? '' : 'none';
    deps.ui.compactTailwindHintButton.style.display = isCssTab && tailwindEnabled ? '' : 'none';
    deps.ui.compactShadowHintButton.style.display =
      isJsTab && deps.getShadowDomEnabled() && deps.canEditJs ? '' : 'none';
  };

  const updateJsUi = () => {
    const isJsTab = activeCssTab === 'js';
    const isCssTab = activeCssTab === 'css';
    const isCompactJsTab = compactEditorTab === 'js';
    const isCompactCssTab = compactEditorTab === 'css';
    const isCompactHtmlTab = compactEditorTab === 'html';
    const jsEnabled = deps.getJsEnabled();
    const shadowDomEnabled = deps.getShadowDomEnabled();
    const tailwindEnabled = deps.getTailwindEnabled();
    const showHeaderActions = (deps.canEditJs && isJsTab) || (tailwindEnabled && isCssTab);
    deps.ui.jsTab.style.display = deps.canEditJs ? '' : 'none';
    deps.ui.jsTab.disabled = !deps.canEditJs;
    deps.ui.jsModeSelect.style.display = deps.canEditJs && isJsTab ? '' : 'none';
    deps.ui.jsModeSelect.disabled = !deps.canEditJs || !isJsTab;
    deps.ui.compactJsTab.style.display = deps.canEditJs ? '' : 'none';
    deps.ui.compactJsTab.disabled = !deps.canEditJs;
    deps.ui.compactJsModeSelect.style.display = isCompactJsTab && deps.canEditJs ? '' : 'none';
    deps.ui.compactJsModeSelect.disabled = !deps.canEditJs;
    deps.ui.jsControls.style.display = showHeaderActions ? '' : 'none';
    deps.ui.runButton.style.display = deps.canEditJs && isJsTab ? '' : 'none';
    deps.ui.runButton.disabled = !jsEnabled || !deps.canEditJs;
    deps.ui.compactAddMediaButton.style.display = isCompactHtmlTab ? '' : 'none';
    deps.ui.compactRunButton.style.display = isCompactJsTab && deps.canEditJs ? '' : 'none';
    deps.ui.compactRunButton.disabled = !jsEnabled || !deps.canEditJs;
    deps.ui.tailwindHintButton.style.display = tailwindEnabled && isCssTab ? '' : 'none';
    deps.ui.tailwindHintButton.disabled = !tailwindEnabled || !isCssTab;
    deps.ui.shadowHintButton.style.display =
      shadowDomEnabled && deps.canEditJs && isJsTab ? '' : 'none';
    deps.ui.shadowHintButton.disabled = !shadowDomEnabled || !deps.canEditJs || !isJsTab;
    deps.ui.compactTailwindHintButton.style.display =
      isCompactCssTab && tailwindEnabled ? '' : 'none';
    deps.ui.compactTailwindHintButton.disabled = !tailwindEnabled || !isCompactCssTab;
    deps.ui.compactShadowHintButton.style.display =
      isCompactJsTab && shadowDomEnabled && deps.canEditJs ? '' : 'none';
    deps.ui.compactShadowHintButton.disabled = !shadowDomEnabled || !deps.canEditJs;
  };

  const setActiveEditor = (editorInstance: EditorInstance, pane: HTMLElement) => {
    activeEditor = editorInstance;
    deps.ui.htmlPane.classList.toggle('is-active', pane === deps.ui.htmlPane);
    deps.ui.cssPane.classList.toggle('is-active', pane === deps.ui.cssPane);
    if (compactEditorMode) {
      compactEditorTab = pane === deps.ui.htmlPane ? 'html' : activeCssTab === 'js' ? 'js' : 'css';
      syncCompactEditorUi();
    }
    deps.onActiveEditorChange?.(editorInstance);
  };

  const setCssTab = (
    tab: CssTab,
    options: { focus?: boolean; syncCompactTab?: boolean } = {}
  ) => {
    const nextTab: CssTab = tab === 'js' && !deps.canEditJs ? 'css' : tab;
    activeCssTab = nextTab;
    deps.ui.cssTab.classList.toggle('is-active', nextTab === 'css');
    deps.ui.jsTab.classList.toggle('is-active', nextTab === 'js');
    deps.ui.cssEditorDiv.classList.toggle('is-active', nextTab === 'css');
    deps.ui.jsEditorDiv.classList.toggle('is-active', nextTab === 'js');
    if (compactEditorMode && options.syncCompactTab !== false) {
      compactEditorTab = nextTab;
      syncCompactEditorUi();
    }
    updateJsUi();
    if (!editorsReady) {
      return;
    }
    if (nextTab === 'js') {
      setActiveEditor(deps.jsEditor, deps.ui.cssPane);
      if (options.focus !== false) {
        deps.jsEditor.focus();
      }
    } else {
      setActiveEditor(deps.cssEditor, deps.ui.cssPane);
      if (options.focus !== false) {
        deps.cssEditor.focus();
      }
    }
  };

  const setCompactEditorTab = (
    tab: CompactEditorTab,
    options: { focus?: boolean } = {}
  ) => {
    const nextTab: CompactEditorTab = tab === 'js' && !deps.canEditJs ? 'css' : tab;
    compactEditorTab = nextTab;
    syncCompactEditorUi();
    if (!editorsReady) {
      return;
    }
    if (nextTab === 'html') {
      setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
      if (options.focus !== false) {
        deps.htmlEditor.focus();
      }
      return;
    }
    setCssTab(nextTab, { focus: options.focus, syncCompactTab: false });
  };

  const updateCompactEditorMode = () => {
    const nextCompact = deps.getViewportWidth() < deps.compactEditorBreakpoint;
    if (nextCompact === compactEditorMode) {
      if (compactEditorMode) {
        syncCompactEditorUi();
      }
      return;
    }
    compactEditorMode = nextCompact;
    deps.ui.app.classList.toggle('is-compact-editors', compactEditorMode);
    deps.onCompactEditorModeChange?.(compactEditorMode);
    if (compactEditorMode) {
      deps.ui.htmlPane.style.flex = '';
      deps.ui.htmlPane.style.height = '';
      deps.ui.cssPane.style.flex = '';
      deps.ui.cssPane.style.height = '';
      const nextTab: CompactEditorTab =
        activeEditor === deps.htmlEditor ? 'html' : activeCssTab === 'js' ? 'js' : 'css';
      setCompactEditorTab(nextTab, { focus: false });
      return;
    }
    deps.ui.htmlPane.classList.remove('is-compact-visible');
    deps.ui.cssPane.classList.remove('is-compact-visible');
    if (activeEditor === deps.htmlEditor) {
      setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
      return;
    }
    setCssTab(activeCssTab, { focus: false, syncCompactTab: false });
  };

  const focusHtmlEditor = () => {
    if (compactEditorMode) {
      setCompactEditorTab('html', { focus: false });
    }
    setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
    deps.htmlEditor.focus();
  };

  const syncJsState = () => {
    if ((!deps.getJsEnabled() || !deps.canEditJs) && activeCssTab === 'js') {
      setCssTab('css', { focus: false });
      return;
    }
    updateJsUi();
  };

  const syncShadowDomState = () => {
    updateJsUi();
  };

  const syncTailwindState = () => {
    updateJsUi();
  };

  const isEditorWidgetClick = (event: MouseEvent) => {
    const target = event.target;
    if (!(target instanceof Node)) {
      return false;
    }
    const targetElement = target instanceof Element ? target : target.parentElement;
    return Boolean(targetElement?.closest('.cm-tooltip'));
  };

  const isInteractiveControlClick = (event: MouseEvent) => {
    const target = event.target;
    if (!(target instanceof Node)) {
      return false;
    }
    const targetElement = target instanceof Element ? target : target.parentElement;
    if (!targetElement) {
      return false;
    }
    return Boolean(
      targetElement.closest(
        'select, option, input, textarea, button, a, [contenteditable="true"], [role="button"]'
      )
    );
  };

  const initialize = () => {
    setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
    deps.ui.htmlPane.addEventListener('click', (event) => {
      if (isEditorWidgetClick(event) || isInteractiveControlClick(event)) {
        return;
      }
      deps.htmlEditor.focus();
    });
    deps.ui.cssPane.addEventListener('click', (event) => {
      if (isEditorWidgetClick(event) || isInteractiveControlClick(event)) {
        return;
      }
      if (activeCssTab === 'js') {
        deps.jsEditor.focus();
      } else {
        deps.cssEditor.focus();
      }
    });
    deps.htmlEditor.onDidFocusEditorText(() => {
      if (compactEditorMode) {
        setCompactEditorTab('html', { focus: false });
        return;
      }
      setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
    });
    deps.cssEditor.onDidFocusEditorText(() => setCssTab('css', { focus: false }));
    deps.jsEditor.onDidFocusEditorText(() => setCssTab('js', { focus: false }));
    deps.ui.addMediaButton.addEventListener('click', deps.onOpenMedia);
    deps.ui.compactAddMediaButton.addEventListener('click', deps.onOpenMedia);
    deps.ui.cssTab.addEventListener('click', () => setCssTab('css', { focus: true }));
    deps.ui.jsTab.addEventListener('click', () => setCssTab('js', { focus: true }));
    deps.ui.compactHtmlTab.addEventListener('click', () => setCompactEditorTab('html', { focus: true }));
    deps.ui.compactCssTab.addEventListener('click', () => setCompactEditorTab('css', { focus: true }));
    deps.ui.compactJsTab.addEventListener('click', () => setCompactEditorTab('js', { focus: true }));
    deps.ui.runButton.addEventListener('click', () => {
      if (!deps.getJsEnabled() || !deps.canEditJs) return;
      deps.onRunJs();
    });
    deps.ui.compactRunButton.addEventListener('click', () => {
      if (!deps.getJsEnabled() || !deps.canEditJs) return;
      deps.onRunJs();
    });
    deps.ui.shadowHintButton.addEventListener('click', () => {
      if (!deps.getShadowDomEnabled()) return;
      deps.onOpenShadowHint();
    });
    deps.ui.compactShadowHintButton.addEventListener('click', () => {
      if (!deps.getShadowDomEnabled() || !deps.canEditJs) return;
      deps.onOpenShadowHint();
    });
    deps.ui.tailwindHintButton.addEventListener('click', () => {
      if (!deps.getTailwindEnabled()) return;
      deps.onOpenTailwindHint();
    });
    deps.ui.compactTailwindHintButton.addEventListener('click', () => {
      if (!deps.getTailwindEnabled()) return;
      deps.onOpenTailwindHint();
    });
    editorsReady = true;
    updateJsUi();
    updateCompactEditorMode();
  };

  return {
    initialize,
    getActiveEditor: () => activeEditor,
    getActiveCssTab: () => activeCssTab,
    isCompactEditorMode: () => compactEditorMode,
    setCssTab,
    setCompactEditorTab,
    updateCompactEditorMode,
    focusHtmlEditor,
    syncJsState,
    syncShadowDomState,
    syncTailwindState,
  };
}



