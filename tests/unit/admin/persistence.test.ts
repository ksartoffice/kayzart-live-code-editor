import { describe, expect, it, vi } from 'vitest';
import { compileTailwindSnapshot, saveKayzArt } from '../../../src/admin/persistence';

const createSaveParams = (overrides: Record<string, unknown> = {}) => ({
  apiFetch: vi.fn().mockResolvedValue({ ok: true }),
  restUrl: '/save',
  postId: 1,
  html: '<p>Hello</p>',
  customHead: '<script>alert(1)</script>',
  css: '.hello { color: red; }',
  tailwindEnabled: false,
  canEditJs: true,
  js: 'console.log("hello");',
  jsMode: 'module' as const,
  ...overrides,
});

describe('saveKayzArt', () => {
  it('omits custom head and JavaScript fields when the user cannot edit unfiltered HTML', async () => {
    const params = createSaveParams({ canEditJs: false });

    await saveKayzArt(params);

    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.not.objectContaining({
          customHead: expect.anything(),
          js: expect.anything(),
          jsMode: expect.anything(),
        }),
      })
    );
    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          post_id: 1,
          html: '<p>Hello</p>',
          css: '.hello { color: red; }',
          tailwindEnabled: false,
        }),
      })
    );
  });

  it('includes custom head and JavaScript fields when the user can edit unfiltered HTML', async () => {
    const params = createSaveParams({ canEditJs: true });

    await saveKayzArt(params);

    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          customHead: '<script>alert(1)</script>',
          js: 'console.log("hello");',
          jsMode: 'module',
        }),
      })
    );
  });
});

describe('compileTailwindSnapshot', () => {
  it('compiles the provided editor snapshot and returns the response CSS', async () => {
    const apiFetch = vi.fn().mockResolvedValue({
      ok: true,
      css: '.fresh { color: red; }',
    });

    const css = await compileTailwindSnapshot({
      apiFetch,
      restCompileUrl: '/compile-tailwind',
      postId: 7,
      html: '<div class="fresh">Fresh</div>',
      css: '@import "tailwindcss";',
    });

    expect(css).toBe('.fresh { color: red; }');
    expect(apiFetch).toHaveBeenCalledWith({
      url: '/compile-tailwind',
      method: 'POST',
      data: {
        post_id: 7,
        html: '<div class="fresh">Fresh</div>',
        css: '@import "tailwindcss";',
      },
    });
  });

  it('returns null when the compile response has no CSS', async () => {
    const apiFetch = vi.fn().mockResolvedValue({ ok: false });

    const css = await compileTailwindSnapshot({
      apiFetch,
      restCompileUrl: '/compile-tailwind',
      postId: 7,
      html: '<div></div>',
      css: '@import "tailwindcss";',
    });

    expect(css).toBeNull();
  });
});
