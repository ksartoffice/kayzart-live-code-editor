import { describe, expect, it, vi, beforeEach } from 'vitest';
import { createSaveExportController } from '../../../../src/admin/controllers/save-export-controller';
import { saveKayzArt } from '../../../../src/admin/persistence';

vi.mock('../../../../src/admin/persistence', async () => {
  const actual = await vi.importActual('../../../../src/admin/persistence');
  return {
    ...actual,
    saveKayzArt: vi.fn(),
  };
});

describe('save export controller', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('calls onSaveSuccess when save succeeds', async () => {
    vi.mocked(saveKayzArt).mockResolvedValue({ ok: true });

    const onSaveSuccess = vi.fn();
    const createElement = () => document.createElement('div');
    const htmlModel = { getValue: () => '<p>hello</p>' } as any;
    const cssModel = { getValue: () => '' } as any;
    const jsModel = { getValue: () => '' } as any;

    const controller = createSaveExportController({
      apiFetch: vi.fn() as any,
      restUrl: '/save',
      restCompileUrl: '/compile',
      postId: 1,
      canEditJs: true,
      getHtmlModel: () => htmlModel,
      getCssModel: () => cssModel,
      getJsModel: () => jsModel,
      getJsMode: () => 'auto',
      getTailwindEnabled: () => false,
      getTailwindCss: () => '',
      getExternalScripts: () => [],
      getExternalStyles: () => [],
      getShadowDomEnabled: () => false,
      getShortcodeEnabled: () => false,
      getSinglePageEnabled: () => true,
      getLiveHighlightEnabled: () => true,
      getPendingSettingsState: () => ({
        pendingSettingsUpdates: {},
        hasUnsavedSettings: false,
        hasSettingsValidationErrors: false,
      }),
      clearPendingSettingsState: () => {},
      applySavedSettings: () => {},
      applySettingsToSidebar: () => {},
      createSnackbar: () => {},
      noticeIds: { save: 'save', export: 'export' },
      noticeSuccessMs: 3000,
      noticeErrorMs: 5000,
      uiDirtyTargets: {
        htmlTitle: createElement(),
        cssTab: createElement(),
        jsTab: createElement(),
        compactHtmlTab: createElement(),
        compactCssTab: createElement(),
        compactJsTab: createElement(),
      },
      onUnsavedChange: () => {},
      onSaveSuccess,
    });

    const result = await controller.handleSave();

    expect(result.ok).toBe(true);
    expect(onSaveSuccess).toHaveBeenCalledTimes(1);
    expect(saveKayzArt).toHaveBeenCalledWith(expect.objectContaining({ jsMode: 'auto' }));
  });
});

