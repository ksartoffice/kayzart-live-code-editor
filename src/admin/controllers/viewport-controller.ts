import type { EditorShellRefs } from '../editor-shell';
import type { ViewportMode } from '../toolbar';

type ViewportControllerDeps = {
  ui: EditorShellRefs;
  compactDesktopViewportWidth: number;
  viewportPresetWidths: {
    mobile: number;
    tablet: number;
  };
  previewBadgeHideMs: number;
  previewBadgeTransitionMs: number;
  minLeftWidth: number;
  minRightWidth: number;
  desktopMinPreviewWidth: number;
  minEditorPaneHeight: number;
  minSettingsWidth: number;
  initialSettingsWidth?: number;
  onSettingsWidthCommit?: (width: number) => void;
  getCompactEditorMode: () => boolean;
  onViewportModeChange?: (mode: ViewportMode) => void;
  onEditorCollapsedChange?: (collapsed: boolean) => void;
};

export function createViewportController(deps: ViewportControllerDeps) {
  let editorCollapsed = false;
  let viewportMode: ViewportMode = 'desktop';
  let previewBadgeTimer: number | undefined;
  let previewBadgeRaf = 0;
  let isResizing = false;
  let isEditorResizing = false;
  let isSettingsResizing = false;
  let startX = 0;
  let startY = 0;
  let startSettingsWidth = 0;
  let startWidth = 0;
  let startHeight = 0;
  let lastLeftWidth = deps.ui.left.getBoundingClientRect().width || deps.minLeftWidth;
  let lastHtmlHeight = 0;
  let editorSplitActive = false;

  const getMainAvailableWidth = () => {
    const mainRect = deps.ui.main.getBoundingClientRect();
    const settingsWidth = deps.ui.settings.getBoundingClientRect().width;
    const resizerWidth = deps.ui.resizer.getBoundingClientRect().width;
    const settingsResizerWidth = deps.ui.settingsResizer.getBoundingClientRect().width;
    return Math.max(0, mainRect.width - settingsWidth - settingsResizerWidth - resizerWidth);
  };

  const getPreviewAreaWidth = () => {
    return Math.max(0, deps.ui.right.getBoundingClientRect().width);
  };

  const isStackedLayout = () => {
    return window.getComputedStyle(deps.ui.main).flexDirection === 'column';
  };

  const setLeftWidth = (width: number) => {
    const clamped = Math.max(deps.minLeftWidth, width);
    lastLeftWidth = clamped;
    deps.ui.left.style.flex = `0 0 ${clamped}px`;
    deps.ui.left.style.width = `${clamped}px`;
  };

  const clearLeftWidth = () => {
    deps.ui.left.style.flex = '';
    deps.ui.left.style.width = '';
  };

  const getMaxSettingsWidth = () => {
    const mainRect = deps.ui.main.getBoundingClientRect();
    const leftWidth = deps.ui.left.getBoundingClientRect().width;
    const leftResizerWidth = deps.ui.resizer.getBoundingClientRect().width;
    const settingsResizerWidth = deps.ui.settingsResizer.getBoundingClientRect().width;
    return Math.max(
      deps.minSettingsWidth,
      mainRect.width - leftWidth - leftResizerWidth - settingsResizerWidth - deps.minRightWidth
    );
  };

  const clampSettingsWidth = (width: number) => {
    return Math.min(getMaxSettingsWidth(), Math.max(deps.minSettingsWidth, Math.round(width)));
  };

  const getCurrentSettingsWidth = () => {
    const rawValue = window.getComputedStyle(deps.ui.app).getPropertyValue('--kayzart-settings-width');
    const parsed = Number.parseFloat(rawValue);
    if (Number.isFinite(parsed) && parsed > 0) {
      return parsed;
    }
    const measured = deps.ui.settings.getBoundingClientRect().width;
    if (Number.isFinite(measured) && measured > 0) {
      return measured;
    }
    return deps.minSettingsWidth;
  };

  const setSettingsWidth = (width: number) => {
    const clamped = clampSettingsWidth(width);
    deps.ui.app.style.setProperty('--kayzart-settings-width', `${clamped}px`);
    return clamped;
  };

  if (typeof deps.initialSettingsWidth === 'number' && Number.isFinite(deps.initialSettingsWidth)) {
    setSettingsWidth(deps.initialSettingsWidth);
  }

  const updatePreviewBadge = () => {
    const compactEditorMode = deps.getCompactEditorMode();
    const width = compactEditorMode
      ? viewportMode === 'desktop'
        ? deps.compactDesktopViewportWidth
        : deps.viewportPresetWidths[viewportMode]
      : viewportMode === 'desktop'
        ? Math.round(deps.ui.iframe.getBoundingClientRect().width)
        : Math.round(
            Math.min(
              deps.viewportPresetWidths[viewportMode],
              Math.max(0, deps.ui.right.getBoundingClientRect().width) ||
                deps.viewportPresetWidths[viewportMode]
            )
          );
    if (width > 0) {
      deps.ui.previewBadge.textContent = `${width}px`;
    }
  };

  const showPreviewBadge = () => {
    updatePreviewBadge();
    deps.ui.previewBadge.classList.add('is-visible');
    window.clearTimeout(previewBadgeTimer);
    previewBadgeTimer = window.setTimeout(() => {
      deps.ui.previewBadge.classList.remove('is-visible');
    }, deps.previewBadgeHideMs);
  };

  const showPreviewBadgeAfterLayout = () => {
    if (isStackedLayout()) {
      applyViewportLayout();
      showPreviewBadge();
      return;
    }
    let done = false;
    const finalize = () => {
      if (done) return;
      done = true;
      deps.ui.left.removeEventListener('transitionend', onTransitionEnd);
      applyViewportLayout();
      showPreviewBadge();
    };
    const onTransitionEnd = (event: TransitionEvent) => {
      if (event.propertyName === 'width' || event.propertyName === 'flex-basis') {
        finalize();
      }
    };
    deps.ui.left.addEventListener('transitionend', onTransitionEnd, { once: true });
    window.setTimeout(finalize, deps.previewBadgeTransitionMs);
  };

  const schedulePreviewBadge = () => {
    if (previewBadgeRaf) {
      return;
    }
    previewBadgeRaf = window.requestAnimationFrame(() => {
      previewBadgeRaf = 0;
      showPreviewBadge();
    });
  };

  const ensurePreviewWidth = (minWidth: number) => {
    if (editorCollapsed || isStackedLayout()) {
      return;
    }
    const available = getMainAvailableWidth();
    const minPreviewWidth = Math.max(deps.minRightWidth, minWidth);
    const maxLeftWidth = Math.max(deps.minLeftWidth, available - minPreviewWidth);
    const currentLeft = deps.ui.left.getBoundingClientRect().width;
    const nextLeft = Math.min(currentLeft, maxLeftWidth);
    if (Math.abs(currentLeft - nextLeft) > 0.5) {
      setLeftWidth(nextLeft);
    }
  };

  function applyViewportLayout(forceFit = false) {
    const compactEditorMode = deps.getCompactEditorMode();
    const clearScaledViewportStyles = () => {
      deps.ui.iframe.style.transform = '';
      deps.ui.iframe.style.transformOrigin = '';
      deps.ui.iframe.style.height = '100%';
      deps.ui.iframe.style.maxWidth = '';
    };

    if (compactEditorMode) {
      const presetWidth =
        viewportMode === 'desktop'
          ? deps.compactDesktopViewportWidth
          : deps.viewportPresetWidths[viewportMode];
      const safePresetWidth = Math.max(1, presetWidth);
      const previewAreaWidth = getPreviewAreaWidth();
      const scale = previewAreaWidth > 0 ? Math.min(1, previewAreaWidth / safePresetWidth) : 1;

      deps.ui.iframe.style.width = `${safePresetWidth}px`;
      deps.ui.iframe.style.margin = '0 auto';
      deps.ui.iframe.style.maxWidth = 'none';
      deps.ui.iframe.style.transformOrigin = 'left top';
      if (scale < 0.999) {
        deps.ui.iframe.style.transform = `scale(${scale})`;
        deps.ui.iframe.style.height = `calc(100% / ${scale})`;
      } else {
        deps.ui.iframe.style.transform = '';
        deps.ui.iframe.style.height = '100%';
      }
      return;
    }

    clearScaledViewportStyles();

    if (viewportMode === 'desktop') {
      deps.ui.iframe.style.width = '100%';
      deps.ui.iframe.style.margin = '0';
      if (forceFit) {
        ensurePreviewWidth(deps.desktopMinPreviewWidth);
      }
      return;
    }

    const presetWidth = deps.viewportPresetWidths[viewportMode];
    const previewAreaWidth = getPreviewAreaWidth();
    const targetWidth = Math.min(presetWidth, previewAreaWidth || presetWidth);
    deps.ui.iframe.style.width = `${targetWidth}px`;
    deps.ui.iframe.style.margin = '0 auto';
    if (forceFit) {
      ensurePreviewWidth(presetWidth);
    }
  }

  function setViewportMode(mode: ViewportMode) {
    const isSameMode = viewportMode === mode;
    viewportMode = mode;
    if (!isSameMode) {
      deps.onViewportModeChange?.(viewportMode);
    }
    applyViewportLayout(true);
    showPreviewBadgeAfterLayout();
  }

  const setEditorSplitHeight = (height: number) => {
    const leftRect = deps.ui.left.getBoundingClientRect();
    const resizerHeight = deps.ui.editorResizer.getBoundingClientRect().height;
    const available = Math.max(0, leftRect.height - resizerHeight);
    if (available <= 0) return;
    const maxHtmlHeight = Math.max(0, available - deps.minEditorPaneHeight);
    const minHtmlHeight = Math.min(deps.minEditorPaneHeight, maxHtmlHeight);
    const clamped = Math.min(maxHtmlHeight, Math.max(minHtmlHeight, height));
    lastHtmlHeight = clamped;
    editorSplitActive = true;
    deps.ui.htmlPane.style.flex = `0 0 ${clamped}px`;
    deps.ui.htmlPane.style.height = `${clamped}px`;
    deps.ui.cssPane.style.flex = '1 1 auto';
    deps.ui.cssPane.style.height = '';
  };

  const setEditorCollapsed = (collapsed: boolean) => {
    editorCollapsed = collapsed;
    deps.ui.app.classList.toggle('is-editor-collapsed', collapsed);
    deps.onEditorCollapsedChange?.(collapsed);
    if (collapsed) {
      const currentWidth = deps.ui.left.getBoundingClientRect().width;
      if (currentWidth > 0) {
        lastLeftWidth = currentWidth;
      }
      deps.ui.left.style.width = `${currentWidth}px`;
      deps.ui.left.style.flex = `0 0 ${currentWidth}px`;
      deps.ui.left.getBoundingClientRect();
      deps.ui.left.style.width = '0px';
      deps.ui.left.style.flex = '0 0 0';
    } else {
      clearLeftWidth();
      setLeftWidth(lastLeftWidth || deps.minLeftWidth);
    }
    applyViewportLayout();
    showPreviewBadgeAfterLayout();
  };

  const onPointerMove = (event: PointerEvent) => {
    if (!isResizing) return;
    const mainRect = deps.ui.main.getBoundingClientRect();
    const settingsWidth = deps.ui.settings.getBoundingClientRect().width;
    const resizerWidth = deps.ui.resizer.getBoundingClientRect().width;
    const settingsResizerWidth = deps.ui.settingsResizer.getBoundingClientRect().width;
    const available = mainRect.width - settingsWidth - settingsResizerWidth - resizerWidth;
    const maxLeftWidth = Math.max(deps.minLeftWidth, available - deps.minRightWidth);
    const nextWidth = Math.min(maxLeftWidth, Math.max(deps.minLeftWidth, startWidth + event.clientX - startX));
    setLeftWidth(nextWidth);
    if (viewportMode !== 'desktop') {
      applyViewportLayout();
    }
    schedulePreviewBadge();
  };

  const stopResizing = (event?: PointerEvent) => {
    if (!isResizing) return;
    isResizing = false;
    deps.ui.app.classList.remove('is-resizing');
    if (event) {
      try {
        deps.ui.resizer.releasePointerCapture(event.pointerId);
      } catch {
        // Ignore if pointer capture isn't active.
      }
    }
  };

  const onEditorPointerMove = (event: PointerEvent) => {
    if (!isEditorResizing) return;
    const nextHeight = startHeight + event.clientY - startY;
    setEditorSplitHeight(nextHeight);
  };

  const onSettingsPointerMove = (event: PointerEvent) => {
    if (!isSettingsResizing) return;
    const nextWidth = startSettingsWidth + (startX - event.clientX);
    setSettingsWidth(nextWidth);
    if (viewportMode !== 'desktop') {
      applyViewportLayout();
    }
    schedulePreviewBadge();
  };

  const stopEditorResizing = (event?: PointerEvent) => {
    if (!isEditorResizing) return;
    isEditorResizing = false;
    deps.ui.app.classList.remove('is-resizing');
    if (event) {
      try {
        deps.ui.editorResizer.releasePointerCapture(event.pointerId);
      } catch {
        // Ignore if pointer capture isn't active.
      }
    }
  };

  const stopSettingsResizing = (event?: PointerEvent) => {
    if (!isSettingsResizing) return;
    isSettingsResizing = false;
    deps.ui.app.classList.remove('is-resizing');
    deps.onSettingsWidthCommit?.(clampSettingsWidth(getCurrentSettingsWidth()));
    if (event) {
      try {
        deps.ui.settingsResizer.releasePointerCapture(event.pointerId);
      } catch {
        // Ignore if pointer capture isn't active.
      }
    }
  };

  deps.ui.resizer.addEventListener('pointerdown', (event) => {
    if (editorCollapsed) {
      return;
    }
    isResizing = true;
    startX = event.clientX;
    startWidth = deps.ui.left.getBoundingClientRect().width;
    deps.ui.app.classList.add('is-resizing');
    deps.ui.resizer.setPointerCapture(event.pointerId);
    showPreviewBadge();
  });

  window.addEventListener('pointermove', onPointerMove);
  window.addEventListener('pointerup', stopResizing);
  deps.ui.resizer.addEventListener('pointerup', stopResizing);
  deps.ui.resizer.addEventListener('pointercancel', stopResizing);

  deps.ui.editorResizer.addEventListener('pointerdown', (event) => {
    if (editorCollapsed) {
      return;
    }
    isEditorResizing = true;
    startY = event.clientY;
    startHeight = deps.ui.htmlPane.getBoundingClientRect().height;
    deps.ui.app.classList.add('is-resizing');
    deps.ui.editorResizer.setPointerCapture(event.pointerId);
  });

  window.addEventListener('pointermove', onEditorPointerMove);
  window.addEventListener('pointerup', stopEditorResizing);
  deps.ui.editorResizer.addEventListener('pointerup', stopEditorResizing);
  deps.ui.editorResizer.addEventListener('pointercancel', stopEditorResizing);

  deps.ui.settingsResizer.addEventListener('pointerdown', (event) => {
    if (editorCollapsed || isStackedLayout()) {
      return;
    }
    if (deps.ui.settings.getBoundingClientRect().width < 1) {
      return;
    }
    isSettingsResizing = true;
    startX = event.clientX;
    startSettingsWidth = deps.ui.settings.getBoundingClientRect().width;
    deps.ui.app.classList.add('is-resizing');
    deps.ui.settingsResizer.setPointerCapture(event.pointerId);
    showPreviewBadge();
  });

  window.addEventListener('pointermove', onSettingsPointerMove);
  window.addEventListener('pointerup', stopSettingsResizing);
  deps.ui.settingsResizer.addEventListener('pointerup', stopSettingsResizing);
  deps.ui.settingsResizer.addEventListener('pointercancel', stopSettingsResizing);

  return {
    applyViewportLayout,
    setViewportMode,
    getViewportMode: () => viewportMode,
    setEditorCollapsed,
    isEditorCollapsed: () => editorCollapsed,
    setEditorSplitHeight,
    getEditorSplitState: () => ({ active: editorSplitActive, lastHtmlHeight }),
  };
}


