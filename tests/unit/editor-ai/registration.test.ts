import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, createElement } from 'react';
import { createRoot } from 'react-dom/client';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

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

  it('opens AI Edit without locking the editor when an agent proposal is waiting', async () => {
    const openSettingsTab = vi.fn();
    const setEditorLock = vi.fn();
    (window as any).KAYZART.ai.timelineUrl = '/timeline';
    (window as any).KAYZART_EXTENSION_API = { registerSettingsTab: vi.fn(() => vi.fn()), registerToolbarAction: vi.fn(() => vi.fn()), openSettingsTab, setEditorLock };
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => new Response(JSON.stringify({
      ok: true, hasMore: false, nextCursor: null,
      items: [{ id: 3, type: 'agent_edit', applicationStatus: 'pending', proposal: { agent: 'a', chain: 'c' } }],
    }), { headers: { 'Content-Type': 'application/json' } }));
    const { initAiEditorIntegration } = await import('../../../src/editor-ai/main');

    initAiEditorIntegration();

    await vi.waitFor(() => expect(openSettingsTab).toHaveBeenCalledWith('kayzart-ai'));
    expect(setEditorLock).not.toHaveBeenCalled();
    vi.restoreAllMocks();
  });

  it('focuses the prompt when the toolbar action is used after the panel mounts', async () => {
    let toolbarAction: { onClick: () => void } | undefined;
    (window as any).KAYZART_EXTENSION_API = {
      registerSettingsTab: vi.fn(() => vi.fn()),
      registerToolbarAction: vi.fn((action) => {
        toolbarAction = action;
        return vi.fn();
      }),
      openSettingsTab: vi.fn(),
    };
    const { AiEditorPanel, initAiEditorIntegration } = await import('../../../src/editor-ai/main');
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    await act(async () => root.render(createElement(AiEditorPanel)));
    initAiEditorIntegration();

    await act(async () => toolbarAction?.onClick());
    const textarea = container.querySelector('textarea');
    await vi.waitFor(() => expect(document.activeElement).toBe(textarea));

    await act(async () => root.unmount());
  });
});
