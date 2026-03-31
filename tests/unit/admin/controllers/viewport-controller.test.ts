import { describe, expect, it, vi } from 'vitest';
import { createViewportController } from '../../../../src/admin/controllers/viewport-controller';

type RectSize = {
  width: number;
  height?: number;
};

const setRect = (element: HTMLElement, size: RectSize) => {
  Object.defineProperty(element, 'getBoundingClientRect', {
    configurable: true,
    value: () => ({
      x: 0,
      y: 0,
      top: 0,
      left: 0,
      right: size.width,
      bottom: size.height ?? 0,
      width: size.width,
      height: size.height ?? 0,
      toJSON: () => ({}),
    }),
  });
};

const dispatchPointer = (
  target: EventTarget,
  type: string,
  clientX: number
) => {
  const event = new Event(type, { bubbles: true, cancelable: true }) as Event & {
    clientX: number;
    pointerId: number;
  };
  event.clientX = clientX;
  event.pointerId = 1;
  target.dispatchEvent(event);
};

function createUi() {
  const app = document.createElement('div');
  const main = document.createElement('div');
  const left = document.createElement('div');
  const right = document.createElement('div');
  const settings = document.createElement('aside');
  const resizer = document.createElement('div');
  const settingsResizer = document.createElement('div');
  const editorResizer = document.createElement('div');
  const htmlPane = document.createElement('div');
  const cssPane = document.createElement('div');
  const iframe = document.createElement('iframe');
  const previewBadge = document.createElement('div');

  setRect(main, { width: 1400, height: 800 });
  setRect(left, { width: 700, height: 800 });
  setRect(right, { width: 370, height: 800 });
  setRect(settings, { width: 320, height: 800 });
  setRect(resizer, { width: 6, height: 800 });
  setRect(settingsResizer, { width: 4, height: 800 });
  setRect(editorResizer, { width: 0, height: 8 });
  setRect(htmlPane, { width: 700, height: 320 });
  setRect(cssPane, { width: 700, height: 400 });
  setRect(iframe, { width: 370, height: 800 });
  setRect(previewBadge, { width: 0, height: 0 });

  (resizer as any).setPointerCapture = vi.fn();
  (resizer as any).releasePointerCapture = vi.fn();
  (editorResizer as any).setPointerCapture = vi.fn();
  (editorResizer as any).releasePointerCapture = vi.fn();
  (settingsResizer as any).setPointerCapture = vi.fn();
  (settingsResizer as any).releasePointerCapture = vi.fn();

  return {
    app,
    main,
    left,
    right,
    settings,
    resizer,
    settingsResizer,
    editorResizer,
    htmlPane,
    cssPane,
    iframe,
    previewBadge,
  } as any;
}

describe('viewport controller settings width persistence hooks', () => {
  it('applies initial settings width with current clamp range', () => {
    const ui = createUi();

    createViewportController({
      ui,
      compactDesktopViewportWidth: 1280,
      viewportPresetWidths: { mobile: 375, tablet: 768 },
      previewBadgeHideMs: 2200,
      previewBadgeTransitionMs: 320,
      minLeftWidth: 320,
      minRightWidth: 360,
      desktopMinPreviewWidth: 1024,
      minEditorPaneHeight: 160,
      minSettingsWidth: 260,
      initialSettingsWidth: 900,
      getCompactEditorMode: () => false,
    });

    expect(ui.app.style.getPropertyValue('--kayzart-settings-width')).toBe('330px');
  });

  it('commits settings width on resize end', () => {
    const ui = createUi();
    const onSettingsWidthCommit = vi.fn();

    createViewportController({
      ui,
      compactDesktopViewportWidth: 1280,
      viewportPresetWidths: { mobile: 375, tablet: 768 },
      previewBadgeHideMs: 2200,
      previewBadgeTransitionMs: 320,
      minLeftWidth: 320,
      minRightWidth: 360,
      desktopMinPreviewWidth: 1024,
      minEditorPaneHeight: 160,
      minSettingsWidth: 260,
      initialSettingsWidth: 300,
      onSettingsWidthCommit,
      getCompactEditorMode: () => false,
    });

    dispatchPointer(ui.settingsResizer, 'pointerdown', 1000);
    dispatchPointer(window, 'pointermove', 500);
    dispatchPointer(window, 'pointerup', 500);

    expect(onSettingsWidthCommit).toHaveBeenCalledWith(330);
    expect(ui.app.style.getPropertyValue('--kayzart-settings-width')).toBe('330px');
  });
});

