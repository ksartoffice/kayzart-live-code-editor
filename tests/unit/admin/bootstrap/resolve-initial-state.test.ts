import { describe, expect, it } from 'vitest';
import { resolveInitialState } from '../../../../src/admin/bootstrap/resolve-initial-state';

const config = (overrides: Record<string, unknown> = {}) =>
  ({
    initialHtml: '',
    initialCss: '.active {}',
    initialJs: '',
    initialJsMode: 'classic',
    tailwindEnabled: false,
    settingsData: {},
    ...overrides,
  }) as any;

describe('resolveInitialState CSS mode compatibility', () => {
  it('lazily assigns legacy active CSS to the stored mode', () => {
    expect(resolveInitialState(config()).initialCssByMode).toEqual({
      normal: '.active {}',
      tailwind: null,
    });
    expect(
      resolveInitialState(config({ tailwindEnabled: true })).initialCssByMode
    ).toEqual({
      normal: null,
      tailwind: '.active {}',
    });
  });

  it('preserves both persisted mode buffers', () => {
    const state = resolveInitialState(
      config({
        initialEditorMode: 'tailwind',
        tailwindEnabled: true,
        initialCss: '@import "tailwindcss";',
        initialCssByMode: {
          normal: '.normal {}',
          tailwind: '@import "tailwindcss";',
        },
      })
    );

    expect(state.initialEditorMode).toBe('tailwind');
    expect(state.initialCssByMode).toEqual({
      normal: '.normal {}',
      tailwind: '@import "tailwindcss";',
    });
  });

  it('initializes Tailwind source when the setup wizard changes a legacy Normal page', () => {
    const state = resolveInitialState(
      config({
        initialCssByMode: {
          normal: '.legacy {}',
          tailwind: null,
        },
      }),
      true
    );

    expect(state.initialEditorMode).toBe('tailwind');
    expect(state.initialCss).toContain('@import "tailwindcss";');
    expect(state.initialCssByMode.normal).toBe('.legacy {}');
    expect(state.initialCssByMode.tailwind).toBe(state.initialCss);
  });
});
