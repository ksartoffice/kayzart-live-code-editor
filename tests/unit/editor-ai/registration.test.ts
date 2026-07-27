import { beforeEach, describe, expect, it, vi } from 'vitest';

describe('free AI editor registration', () => {
  beforeEach(() => {
    vi.resetModules();
    sessionStorage.clear();
    document.body.innerHTML = '';
    (window as any).KAYZART = {
      post_id: 5,
      restNonce: 'nonce',
      ai: {
        available: true, featureEnabled: true, sdkPresent: true, providerConfigured: true,
        schedulerPresent: true, mbstringPresent: true, domPresent: true, canEdit: true, jobsUrl: '/jobs', jobsBaseUrl: '/jobs/',
        connectorsUrl: '/connectors', canManageConnectors: true,
      },
    };
  });

  it('registers only the toolbar action because the AI tab is built in', async () => {
    const registerSettingsTab = vi.fn(() => vi.fn());
    const registerToolbarAction = vi.fn(() => vi.fn());
    (window as any).KAYZART_EXTENSION_API = { registerSettingsTab, registerToolbarAction };
    const { initAiEditorIntegration } = await import('../../../src/editor-ai/main');
    initAiEditorIntegration();
    expect(registerSettingsTab).not.toHaveBeenCalled();
    expect(registerToolbarAction.mock.calls[0][0]).toMatchObject({ id: 'kayzart-toolbar-ai-edit' });
  });
});
