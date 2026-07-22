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
        schedulerPresent: true, canEdit: true, jobsUrl: '/jobs', jobsBaseUrl: '/jobs/',
        connectorsUrl: '/connectors', canManageConnectors: true,
      },
    };
  });

  it('registers one core tab and toolbar action', async () => {
    const registerSettingsTab = vi.fn(() => vi.fn());
    const registerToolbarAction = vi.fn(() => vi.fn());
    (window as any).KAYZART_EXTENSION_API = { registerSettingsTab, registerToolbarAction };
    await import('../../../src/editor-ai/main');
    expect(registerSettingsTab.mock.calls[0][0]).toMatchObject({ id: 'kayzart-ai', label: 'AI Edit' });
    expect(registerToolbarAction.mock.calls[0][0]).toMatchObject({ id: 'kayzart-toolbar-ai-edit' });
  });
});
