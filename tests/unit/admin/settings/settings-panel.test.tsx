// @vitest-environment jsdom
import { createElement, createRoot } from '@wordpress/element';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { SettingsPanel } from '../../../../src/admin/settings/settings-panel';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

describe('SettingsPanel CSS mode', () => {
  it('shows the current mode and requests a mode change', async () => {
    const container = document.createElement('div');
    const root = createRoot(container);
    const onChangeEditorMode = vi.fn();

    await act(async () => {
      root.render(
        <SettingsPanel
          canEditJs={true}
          templateMode="standalone"
          defaultTemplateMode="standalone"
          onChangeTemplateMode={() => {}}
          liveHighlightEnabled={true}
          onToggleLiveHighlight={() => {}}
          editorMode="normal"
          onChangeEditorMode={onChangeEditorMode}
        />
      );
    });

    const select = container.querySelector(
      'select[aria-label="CSS mode"]'
    ) as HTMLSelectElement;
    expect(select.value).toBe('normal');

    await act(async () => {
      select.value = 'tailwind';
      select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    expect(onChangeEditorMode).toHaveBeenCalledWith('tailwind');

    await act(async () => root.unmount());
  });

  it('disables mode changes while a conversion or save is in progress', async () => {
    const container = document.createElement('div');
    const root = createRoot(container);

    await act(async () => {
      root.render(
        <SettingsPanel
          canEditJs={true}
          templateMode="standalone"
          defaultTemplateMode="standalone"
          onChangeTemplateMode={() => {}}
          liveHighlightEnabled={true}
          onToggleLiveHighlight={() => {}}
          editorMode="tailwind"
          onChangeEditorMode={() => {}}
          editorModeDisabled={true}
        />
      );
    });

    expect(
      (container.querySelector('select[aria-label="CSS mode"]') as HTMLSelectElement)
        .disabled
    ).toBe(true);

    await act(async () => root.unmount());
  });
});
