import type { EditorShellRefs } from '../editor-shell';
import type { CodeEditorInstance } from '../codemirror';

type EditorInstance = CodeEditorInstance;
type HtmlTab = 'html' | 'customHead';
type CssTab = 'css' | 'js';
type CompactEditorTab = 'html' | 'customHead' | 'css' | 'js';

type EditorUiControllerDeps = {
  ui: EditorShellRefs;
  canEditJs: boolean;
  htmlEditor: EditorInstance;
  customHeadEditor: EditorInstance;
  cssEditor: EditorInstance;
  jsEditor: EditorInstance;
  compactEditorBreakpoint: number;
  getViewportWidth: () => number;
  getJsEnabled: () => boolean;
  onActiveEditorChange?: (editor: EditorInstance) => void;
  onEditorViewChange?: () => void;
  onCompactEditorModeChange?: (isCompact: boolean) => void;
  onOpenMedia: () => void;
  onFormatHtml: () => void;
};

export function createEditorUiController(deps: EditorUiControllerDeps) {
  let activeEditor: EditorInstance | null = null;
  let activeHtmlTab: HtmlTab = 'html';
  let activeCssTab: CssTab = 'css';
  let compactEditorMode = false;
  let compactEditorTab: CompactEditorTab = 'html';
  let editorsReady = false;

  const canEditCustomHead = () => deps.canEditJs;

  const syncCompactEditorUi = () => {
    if (!canEditCustomHead() && compactEditorTab === 'customHead') {
      compactEditorTab = 'html';
    }
    const isHtmlTab = compactEditorTab === 'html';
    const isHtmlLikeTab = compactEditorTab === 'html' || compactEditorTab === 'customHead';
    const isJsTab = compactEditorTab === 'js';
    deps.ui.compactHtmlTab.classList.toggle('is-active', compactEditorTab === 'html');
    deps.ui.compactCustomHeadTab.classList.toggle('is-active', compactEditorTab === 'customHead');
    deps.ui.compactCssTab.classList.toggle('is-active', compactEditorTab === 'css');
    deps.ui.compactJsTab.classList.toggle('is-active', compactEditorTab === 'js');
    deps.ui.htmlPane.classList.toggle(
      'is-compact-visible',
      compactEditorTab === 'html' || compactEditorTab === 'customHead'
    );
    deps.ui.cssPane.classList.toggle(
      'is-compact-visible',
      compactEditorTab !== 'html' && compactEditorTab !== 'customHead'
    );
    deps.ui.compactAddMediaButton.style.display = isHtmlTab ? '' : 'none';
    deps.ui.compactFormatButton.style.display = isHtmlLikeTab ? '' : 'none';
    deps.ui.compactJsModeSelect.style.display = isJsTab && deps.canEditJs ? '' : 'none';
    deps.onEditorViewChange?.();
  };

  const updatePermissionGatedUi = () => {
    if (!canEditCustomHead() && activeHtmlTab === 'customHead') {
      setHtmlTab('html', { focus: false });
    }
    const isJsTab = activeCssTab === 'js';
    const isCompactJsTab = compactEditorTab === 'js';
    const isCompactHtmlTab = compactEditorTab === 'html';
    const isCompactHtmlLikeTab = compactEditorTab === 'html' || compactEditorTab === 'customHead';
    deps.ui.customHeadTab.style.display = canEditCustomHead() ? '' : 'none';
    deps.ui.customHeadTab.disabled = !canEditCustomHead();
    deps.ui.compactCustomHeadTab.style.display = canEditCustomHead() ? '' : 'none';
    deps.ui.compactCustomHeadTab.disabled = !canEditCustomHead();
    if (!canEditCustomHead()) {
      deps.ui.customHeadEditorDiv.parentElement?.classList.remove('is-active');
    }
    deps.ui.jsTab.style.display = deps.canEditJs ? '' : 'none';
    deps.ui.jsTab.disabled = !deps.canEditJs;
    deps.ui.jsModeSelect.style.display = deps.canEditJs && isJsTab ? '' : 'none';
    deps.ui.jsModeSelect.disabled = !deps.canEditJs || !isJsTab;
    deps.ui.compactJsTab.style.display = deps.canEditJs ? '' : 'none';
    deps.ui.compactJsTab.disabled = !deps.canEditJs;
    deps.ui.compactJsModeSelect.style.display = isCompactJsTab && deps.canEditJs ? '' : 'none';
    deps.ui.compactJsModeSelect.disabled = !deps.canEditJs;
    deps.ui.jsControls.style.display = deps.canEditJs && isJsTab ? '' : 'none';
    deps.ui.compactAddMediaButton.style.display = isCompactHtmlTab ? '' : 'none';
    deps.ui.compactFormatButton.style.display = isCompactHtmlLikeTab ? '' : 'none';
    deps.onEditorViewChange?.();
  };

  const setActiveEditor = (editorInstance: EditorInstance, pane: HTMLElement) => {
    activeEditor = editorInstance;
    deps.ui.htmlPane.classList.toggle('is-active', pane === deps.ui.htmlPane);
    deps.ui.cssPane.classList.toggle('is-active', pane === deps.ui.cssPane);
    if (compactEditorMode) {
      compactEditorTab = pane === deps.ui.htmlPane ? activeHtmlTab : activeCssTab === 'js' ? 'js' : 'css';
      syncCompactEditorUi();
    }
    deps.onActiveEditorChange?.(editorInstance);
  };

  const setHtmlTab = (
    tab: HtmlTab,
    options: { focus?: boolean; syncCompactTab?: boolean } = {}
  ) => {
    const nextTab: HtmlTab = tab === 'customHead' && !canEditCustomHead() ? 'html' : tab;
    activeHtmlTab = nextTab;
    deps.ui.htmlTab.classList.toggle('is-active', nextTab === 'html');
    deps.ui.customHeadTab.classList.toggle('is-active', nextTab === 'customHead');
    deps.ui.htmlEditorDiv.classList.toggle('is-active', nextTab === 'html');
    deps.ui.customHeadEditorDiv.parentElement?.classList.toggle('is-active', nextTab === 'customHead');
    deps.ui.addMediaButton.style.display = nextTab === 'html' ? '' : 'none';
    deps.ui.htmlFormatButton.style.display = '';
    deps.ui.htmlWordWrapButton.style.display = nextTab === 'html' ? '' : 'none';
    if (compactEditorMode && options.syncCompactTab !== false) {
      compactEditorTab = nextTab;
      syncCompactEditorUi();
    }
    if (!editorsReady) {
      return;
    }
    if (nextTab === 'customHead') {
      setActiveEditor(deps.customHeadEditor, deps.ui.htmlPane);
      if (options.focus !== false) {
        deps.customHeadEditor.focus();
      }
      return;
    }
    setActiveEditor(deps.htmlEditor, deps.ui.htmlPane);
    if (options.focus !== false) {
      deps.htmlEditor.focus();
    }
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
    updatePermissionGatedUi();
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
    const nextTab: CompactEditorTab =
      tab === 'js' && !deps.canEditJs
        ? 'css'
        : tab === 'customHead' && !canEditCustomHead()
          ? 'html'
          : tab;
    compactEditorTab = nextTab;
    syncCompactEditorUi();
    if (!editorsReady) {
      return;
    }
    if (nextTab === 'html') {
      setHtmlTab('html', { focus: options.focus, syncCompactTab: false });
      return;
    }
    if (nextTab === 'customHead') {
      setHtmlTab('customHead', { focus: options.focus, syncCompactTab: false });
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
        activeEditor === deps.htmlEditor || activeEditor === deps.customHeadEditor
          ? activeHtmlTab
          : activeCssTab === 'js'
            ? 'js'
            : 'css';
      setCompactEditorTab(nextTab, { focus: false });
      return;
    }
    deps.ui.htmlPane.classList.remove('is-compact-visible');
    deps.ui.cssPane.classList.remove('is-compact-visible');
    if (activeEditor === deps.htmlEditor) {
      setHtmlTab('html', { focus: false, syncCompactTab: false });
      return;
    }
    if (activeEditor === deps.customHeadEditor) {
      setHtmlTab('customHead', { focus: false, syncCompactTab: false });
      return;
    }
    setCssTab(activeCssTab, { focus: false, syncCompactTab: false });
  };

  const focusHtmlEditor = () => {
    if (compactEditorMode) {
      setCompactEditorTab('html', { focus: false });
    }
    setHtmlTab('html', { focus: false });
    deps.htmlEditor.focus();
  };

  const syncJsState = () => {
    if ((!deps.getJsEnabled() || !deps.canEditJs) && activeCssTab === 'js') {
      setCssTab('css', { focus: false });
      return;
    }
    updatePermissionGatedUi();
  };

  const syncTailwindState = () => {
    updatePermissionGatedUi();
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
    setHtmlTab('html', { focus: false });
    deps.ui.htmlPane.addEventListener('click', (event) => {
      if (isEditorWidgetClick(event) || isInteractiveControlClick(event)) {
        return;
      }
      if (activeHtmlTab === 'customHead') {
        deps.customHeadEditor.focus();
      } else {
        deps.htmlEditor.focus();
      }
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
      setHtmlTab('html', { focus: false });
    });
    deps.customHeadEditor.onDidFocusEditorText(() => {
      if (!canEditCustomHead()) {
        setHtmlTab('html', { focus: false });
        return;
      }
      if (compactEditorMode) {
        setCompactEditorTab('customHead', { focus: false });
        return;
      }
      setHtmlTab('customHead', { focus: false });
    });
    deps.cssEditor.onDidFocusEditorText(() => setCssTab('css', { focus: false }));
    deps.jsEditor.onDidFocusEditorText(() => setCssTab('js', { focus: false }));
    deps.ui.addMediaButton.addEventListener('click', deps.onOpenMedia);
    deps.ui.compactAddMediaButton.addEventListener('click', deps.onOpenMedia);
    deps.ui.htmlFormatButton.addEventListener('click', deps.onFormatHtml);
    deps.ui.compactFormatButton.addEventListener('click', deps.onFormatHtml);
    deps.ui.htmlTab.addEventListener('click', () => setHtmlTab('html', { focus: true }));
    deps.ui.customHeadTab.addEventListener('click', () => setHtmlTab('customHead', { focus: true }));
    deps.ui.cssTab.addEventListener('click', () => setCssTab('css', { focus: true }));
    deps.ui.jsTab.addEventListener('click', () => setCssTab('js', { focus: true }));
    deps.ui.compactHtmlTab.addEventListener('click', () => setCompactEditorTab('html', { focus: true }));
    deps.ui.compactCustomHeadTab.addEventListener('click', () => setCompactEditorTab('customHead', { focus: true }));
    deps.ui.compactCssTab.addEventListener('click', () => setCompactEditorTab('css', { focus: true }));
    deps.ui.compactJsTab.addEventListener('click', () => setCompactEditorTab('js', { focus: true }));
    editorsReady = true;
    updatePermissionGatedUi();
    updateCompactEditorMode();
  };

  return {
    initialize,
    getActiveEditor: () => activeEditor,
    getActiveHtmlTab: () => activeHtmlTab,
    getActiveCssTab: () => activeCssTab,
    getCompactEditorTab: () => compactEditorTab,
    isCompactEditorMode: () => compactEditorMode,
    setHtmlTab,
    setCssTab,
    setCompactEditorTab,
    updateCompactEditorMode,
    focusHtmlEditor,
    syncJsState,
    syncTailwindState,
  };
}



