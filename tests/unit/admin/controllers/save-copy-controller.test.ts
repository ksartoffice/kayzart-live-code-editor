import { describe, expect, it, vi, beforeEach } from 'vitest';
import {
  computeUnsavedChangeLines,
  createSaveCopyController,
} from '../../../../src/admin/controllers/save-copy-controller';
import { saveKayzArt } from '../../../../src/admin/persistence';

vi.mock('../../../../src/admin/persistence', async () => {
  const actual = await vi.importActual('../../../../src/admin/persistence');
  return {
    ...actual,
    saveKayzArt: vi.fn(),
  };
});

describe('save copy controller', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  const createController = (overrides: Record<string, unknown> = {}) => {
    const createElement = () => document.createElement('div');
    const htmlModel = {
      getValue: () => '<p>hello</p>',
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const customHeadModel = {
      getValue: () => '<meta property="og:title" content="hello">',
      getPositionAt: () => ({ lineNumber: 1, column: 1 }),
      pushEditOperations: vi.fn(),
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const cssModel = {
      getValue: () => '.hello { color: red; }',
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const jsModel = {
      getValue: () => 'console.log("hello");',
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const createSnackbar = vi.fn();

    const controller = createSaveCopyController({
      apiFetch: vi.fn() as any,
      restUrl: '/save',
      postId: 1,
      canEditJs: true,
      getHtmlModel: () => htmlModel,
      getCustomHeadModel: () => customHeadModel,
      getCssModel: () => cssModel,
      getJsModel: () => jsModel,
      getJsMode: () => 'module',
      getTailwindEnabled: () => false,
      getPendingSettingsState: () => ({
        pendingSettingsUpdates: {},
        hasUnsavedSettings: false,
        hasSettingsValidationErrors: false,
      }),
      clearPendingSettingsState: () => {},
      applySavedSettings: () => {},
      applySettingsToSidebar: () => {},
      createSnackbar,
      noticeIds: { save: 'save' },
      noticeSuccessMs: 3000,
      noticeErrorMs: 5000,
      uiDirtyTargets: {
        htmlTitle: createElement(),
        htmlTab: createElement(),
        customHeadTab: createElement(),
        cssTab: createElement(),
        jsTab: createElement(),
        compactHtmlTab: createElement(),
        compactCustomHeadTab: createElement(),
        compactCssTab: createElement(),
        compactJsTab: createElement(),
      },
      onUnsavedChange: () => {},
      ...overrides,
    });

    return { controller, createSnackbar, htmlModel, customHeadModel, cssModel, jsModel };
  };

  it('computes current-side unsaved change lines', () => {
    expect(computeUnsavedChangeLines('a\nb\nc', 'a\nB\nc')).toEqual([2]);
    expect(computeUnsavedChangeLines('a\nb\nc', 'a\nb\nnew\nc')).toEqual([3]);
    expect(computeUnsavedChangeLines('a\nb\nc', 'a\nb\nc')).toEqual([]);
    expect(computeUnsavedChangeLines('a\nb\nc', 'a\nc')).toEqual([]);
  });

  it('marks only the inserted line when an auto-indented blank line is added', () => {
    // Enter をオートインデント付きで押すと新しい行に次の行と同じインデントが入り、
    // presentableDiff は挿入を "\n  " と報告する。追加した行のみをマークすること。
    const saved = '<section>\n  <div>\n    <p>x</p>';
    expect(
      computeUnsavedChangeLines(saved, '<section>\n  \n  <div>\n    <p>x</p>')
    ).toEqual([2]);
    expect(
      computeUnsavedChangeLines('  <div>\n    <p>x</p>', '  <div>\n    \n    <p>x</p>')
    ).toEqual([2]);
    // 改行なしの空行挿入や、行に文字を打った後も追加行のみ。
    expect(
      computeUnsavedChangeLines(saved, '<section>\n\n  <div>\n    <p>x</p>')
    ).toEqual([2]);
    expect(
      computeUnsavedChangeLines(saved, '<section>\n  ああ\n  <div>\n    <p>x</p>')
    ).toEqual([2]);
  });

  it('clears unsaved change gutter markers when marking the current state as saved', () => {
    const { controller, htmlModel, customHeadModel, cssModel, jsModel } = createController();

    controller.markSavedState();

    expect(htmlModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([]);
    expect(customHeadModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([]);
    expect(cssModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([]);
    expect(jsModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([]);
  });

  it('syncs unsaved change gutter markers for each editable model', () => {
    const values = {
      html: '<p>hello</p>',
      customHead: '<meta property="og:title" content="hello">',
      css: '.hello {\n  color: red;\n}',
      js: 'console.log("hello");',
    };
    const htmlModel = {
      getValue: () => values.html,
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const customHeadModel = {
      getValue: () => values.customHead,
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const cssModel = {
      getValue: () => values.css,
      setUnsavedChangeLines: vi.fn(),
    } as any;
    const jsModel = {
      getValue: () => values.js,
      setUnsavedChangeLines: vi.fn(),
    } as any;

    const { controller } = createController({
      getHtmlModel: () => htmlModel,
      getCustomHeadModel: () => customHeadModel,
      getCssModel: () => cssModel,
      getJsModel: () => jsModel,
    });

    controller.markSavedState();
    values.html = '<p>HELLO</p>';
    values.customHead = '<meta property="og:title" content="HELLO">';
    values.css = '.hello {\n  color: blue;\n}';
    values.js = 'console.log("HELLO");';
    controller.syncUnsavedUi();

    expect(htmlModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([1]);
    expect(customHeadModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([1]);
    expect(cssModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([2]);
    expect(jsModel.setUnsavedChangeLines).toHaveBeenLastCalledWith([1]);
  });

  it('calls onSaveSuccess when save succeeds', async () => {
    vi.mocked(saveKayzArt).mockResolvedValue({ ok: true });

    const onSaveSuccess = vi.fn();
    const { controller } = createController({ onSaveSuccess, getJsMode: () => 'classic' });

    const result = await controller.handleSave();

    expect(result.ok).toBe(true);
    expect(onSaveSuccess).toHaveBeenCalledTimes(1);
    expect(saveKayzArt).toHaveBeenCalledWith(
      expect.objectContaining({ jsMode: 'classic', tailwindEnabled: false })
    );
  });

  it('removes unsupported custom head tags before saving', async () => {
    vi.mocked(saveKayzArt).mockResolvedValue({ ok: true, customHead: '<meta property="og:title" content="hello">' });
    const customHeadModel = {
      value: '<title>Nope</title><meta property="og:title" content="hello"><base href="/">',
      getValue() {
        return this.value;
      },
      getPositionAt: () => ({ lineNumber: 1, column: 1 }),
      pushEditOperations: vi.fn(function (_before, operations) {
        customHeadModel.value = operations[0].text;
      }),
    } as any;

    const { controller, createSnackbar } = createController({
      getCustomHeadModel: () => customHeadModel,
    });

    await controller.handleSave();

    expect(saveKayzArt).toHaveBeenCalledWith(
      expect.objectContaining({ customHead: '<meta property="og:title" content="hello">' })
    );
    expect(createSnackbar).toHaveBeenCalledWith(
      'warning',
      expect.stringContaining('title'),
      'save',
      5000
    );
  });
});
