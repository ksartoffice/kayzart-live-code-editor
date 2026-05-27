import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { createSaveCopyController } from '../../../../src/admin/controllers/save-copy-controller';
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
    const htmlModel = { getValue: () => '<p>hello</p>' } as any;
    const customHeadModel = {
      getValue: () => '<meta property="og:title" content="hello">',
      getPositionAt: () => ({ lineNumber: 1, column: 1 }),
      pushEditOperations: vi.fn(),
    } as any;
    const cssModel = { getValue: () => '.hello { color: red; }' } as any;
    const jsModel = { getValue: () => 'console.log("hello");' } as any;
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

    return { controller, createSnackbar, customHeadModel };
  };

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
