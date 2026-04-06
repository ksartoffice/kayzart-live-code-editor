import { beforeEach, describe, expect, it, vi } from 'vitest';

const clearGlobals = () => {
  delete (window as any).KAYZART_EXTENSION_API;
  delete (window as any).KAYZART_SETTINGS_TAB_QUEUE;
  delete (window as any).KAYZART_TOOLBAR_ACTION_QUEUE;
};

const loadRegistry = async () =>
  import('../../../../src/admin/extensions/toolbar-action-registry');

describe('toolbar action registry', () => {
  beforeEach(() => {
    clearGlobals();
    vi.resetModules();
    vi.restoreAllMocks();
  });

  it('flushes queued toolbar actions when the API initializes', async () => {
    const onClick = vi.fn();
    (window as any).KAYZART_TOOLBAR_ACTION_QUEUE = [
      {
        id: 'action-addon',
        label: 'Edit with add-on',
        placement: 'before-settings',
        order: 20,
        onClick,
      },
    ];

    const registry = await loadRegistry();
    const actions = registry.getExternalToolbarActions();

    expect(actions).toHaveLength(1);
    expect(actions[0].id).toBe('action-addon');
    expect(actions[0].placement).toBe('before-settings');
    expect(actions[0].order).toBe(20);
    expect((window as any).KAYZART_TOOLBAR_ACTION_QUEUE).toEqual([]);

    actions[0].onClick();
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('registers and unregisters actions at runtime', async () => {
    const registry = await loadRegistry();
    const api = (window as any).KAYZART_EXTENSION_API;
    const listener = vi.fn();
    const unsubscribe = registry.subscribeExternalToolbarActions(listener);

    const unregisterLater = api.registerToolbarAction({
      id: 'later',
      label: 'Later',
      placement: 'before-settings',
      order: 30,
      onClick: () => {},
    });
    const unregisterSoon = api.registerToolbarAction({
      id: 'soon',
      label: 'Soon',
      placement: 'before-settings',
      order: 10,
      onClick: () => {},
    });

    expect(
      registry
        .getExternalToolbarActions()
        .filter((action: { placement: string }) => action.placement === 'before-settings')
        .map((action: { id: string }) => action.id)
    ).toEqual(['soon', 'later']);
    expect(listener).toHaveBeenCalledTimes(2);

    unregisterSoon();
    expect(
      registry
        .getExternalToolbarActions()
        .filter((action: { placement: string }) => action.placement === 'before-settings')
        .map((action: { id: string }) => action.id)
    ).toEqual(['later']);
    expect(listener).toHaveBeenCalledTimes(3);

    unsubscribe();
    unregisterLater();
    expect(listener).toHaveBeenCalledTimes(3);
  });

  it('rejects invalid and duplicate toolbar actions', async () => {
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    const registry = await loadRegistry();
    const api = (window as any).KAYZART_EXTENSION_API;

    api.registerToolbarAction({
      id: 'action-1',
      label: 'Action 1',
      onClick: () => {},
    });
    api.registerToolbarAction({
      id: 'action-1',
      label: 'Action 1 duplicate',
      onClick: () => {},
    });
    api.registerToolbarAction({
      id: '',
      label: 'Invalid',
      onClick: () => {},
    });
    api.registerToolbarAction({
      id: 'invalid-handler',
      label: 'Invalid handler',
      onClick: null,
    });

    expect(registry.getExternalToolbarActions().map((action: { id: string }) => action.id)).toEqual([
      'action-1',
    ]);
    expect(warnSpy).toHaveBeenCalled();
  });
});
