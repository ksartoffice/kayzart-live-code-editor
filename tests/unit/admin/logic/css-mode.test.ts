import { describe, expect, it, vi } from 'vitest';
import {
  createInitialTailwindSource,
  resolveCssModeChange,
} from '../../../../src/admin/logic/css-mode';

describe('CSS mode changes', () => {
  it('initializes Tailwind source without duplicating an existing import', () => {
    expect(createInitialTailwindSource('.card { color: red; }')).toContain(
      '@import "tailwindcss";'
    );
    const existing = '@import "tailwindcss";\n.card { color: red; }';
    expect(createInitialTailwindSource(existing)).toBe(existing);
  });

  it('compiles Tailwind output the first time Normal mode is entered', async () => {
    const compileTailwind = vi.fn().mockResolvedValue('.text-red-500 { color: red; }');
    const result = await resolveCssModeChange({
      currentMode: 'tailwind',
      nextMode: 'normal',
      currentCss: '@import "tailwindcss";',
      cssByMode: { normal: null, tailwind: '@import "tailwindcss";' },
      compileTailwind,
    });

    expect(result.css).toBe('.text-red-500 { color: red; }');
    expect(result.cssByMode.tailwind).toBe('@import "tailwindcss";');
    expect(result.cssByMode.normal).toBe('.text-red-500 { color: red; }');
  });

  it('restores each mode buffer without compiling again', async () => {
    const compileTailwind = vi.fn();
    const result = await resolveCssModeChange({
      currentMode: 'normal',
      nextMode: 'tailwind',
      currentCss: '.normal { color: blue; }',
      cssByMode: {
        normal: '.old-normal {}',
        tailwind: '@import "tailwindcss";\n@theme {}',
      },
      compileTailwind,
    });

    expect(result.css).toBe('@import "tailwindcss";\n@theme {}');
    expect(result.cssByMode.normal).toBe('.normal { color: blue; }');
    expect(compileTailwind).not.toHaveBeenCalled();
  });

  it('rejects a Tailwind to Normal switch when compilation fails', async () => {
    await expect(
      resolveCssModeChange({
        currentMode: 'tailwind',
        nextMode: 'normal',
        currentCss: '@import "tailwindcss";',
        cssByMode: { normal: null, tailwind: '@import "tailwindcss";' },
        compileTailwind: async () => null,
      })
    ).rejects.toThrow('tailwind_compile_failed');
  });
});
