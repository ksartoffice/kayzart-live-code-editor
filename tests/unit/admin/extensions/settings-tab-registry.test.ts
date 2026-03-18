import { beforeEach, describe, expect, it, vi } from 'vitest';

const clearGlobals = () => {
  delete (window as any).KAYZART_EXTENSION_API;
  delete (window as any).KAYZART_SETTINGS_TAB_QUEUE;
};

const loadRegistry = async () =>
  import('../../../../src/admin/extensions/settings-tab-registry');

describe('settings tab registry', () => {
  beforeEach(() => {
    clearGlobals();
    vi.resetModules();
    vi.restoreAllMocks();
  });

  it('flushes queued tabs when the API initializes', async () => {
    const mount = vi.fn();
    (window as any).KAYZART_SETTINGS_TAB_QUEUE = [
      {
        id: 'ai',
        label: 'AI Editing',
        order: 20,
        mount,
      },
    ];

    const registry = await loadRegistry();
    const tabs = registry.getExternalSettingsTabs();

    expect(tabs.map((tab) => tab.id)).toEqual(['ai']);
    expect(tabs[0].order).toBe(20);
    expect(Array.isArray((window as any).KAYZART_SETTINGS_TAB_QUEUE)).toBe(true);
    expect((window as any).KAYZART_SETTINGS_TAB_QUEUE).toHaveLength(0);

    const host = document.createElement('div');
    tabs[0].mount(host);
    expect(mount).toHaveBeenCalledWith(host);
  });

  it('registers and unregisters tabs at runtime', async () => {
    const registry = await loadRegistry();
    const api = (window as any).KAYZART_EXTENSION_API;
    const listener = vi.fn();
    const unsubscribe = registry.subscribeExternalSettingsTabs(listener);

    const unregisterB = api.registerSettingsTab({
      id: 'tab-b',
      label: 'Tab B',
      order: 30,
      mount: () => {},
    });
    const unregisterA = api.registerSettingsTab({
      id: 'tab-a',
      label: 'Tab A',
      order: 10,
      mount: () => {},
    });

    expect(registry.getExternalSettingsTabs().map((tab) => tab.id)).toEqual([
      'tab-a',
      'tab-b',
    ]);
    expect(listener).toHaveBeenCalledTimes(2);

    unregisterA();
    expect(registry.getExternalSettingsTabs().map((tab) => tab.id)).toEqual(['tab-b']);
    expect(listener).toHaveBeenCalledTimes(3);

    unsubscribe();
    unregisterB();
    expect(listener).toHaveBeenCalledTimes(3);
  });

  it('rejects reserved, invalid, and duplicate tab registrations', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const registry = await loadRegistry();
    const api = (window as any).KAYZART_EXTENSION_API;

    api.registerSettingsTab({
      id: 'settings',
      label: 'Reserved',
      mount: () => {},
    });
    api.registerSettingsTab({
      id: 'ai',
      label: 'AI Editing',
      mount: () => {},
    });
    api.registerSettingsTab({
      id: 'ai',
      label: 'Duplicate',
      mount: () => {},
    });
    api.registerSettingsTab({
      id: '',
      label: 'Invalid',
      mount: () => {},
    });

    expect(registry.getExternalSettingsTabs().map((tab) => tab.id)).toEqual(['ai']);
    expect(warnSpy).toHaveBeenCalled();
  });

  it('exposes context snapshot API and allows provider updates', async () => {
    const registry = await loadRegistry();
    const api = (window as any).KAYZART_EXTENSION_API;

    expect(api.getContextSnapshot()).toEqual({});

    registry.setContextSnapshotProvider((includeKeys?: string[]) => {
      if (includeKeys?.includes('document_html')) {
        return {
          document: {
            html: '<main>Hello</main>',
          },
        };
      }
      return {};
    });

    expect(api.getContextSnapshot(['document_html'])).toEqual({
      document: {
        html: '<main>Hello</main>',
      },
    });
  });
});
