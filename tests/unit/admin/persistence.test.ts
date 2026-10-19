import { describe, expect, it, vi } from 'vitest';
import {
  compileTailwindSnapshot,
  createTailwindCompiler,
  saveKayzArt,
} from '../../../src/admin/persistence';

const createSaveParams = (overrides: Record<string, unknown> = {}) => ({
  apiFetch: vi.fn().mockResolvedValue({ ok: true }),
  restUrl: '/save',
  postId: 1,
  html: '<p>Hello</p>',
  customHead: '<script>alert(1)</script>',
  css: '.hello { color: red; }',
  tailwindEnabled: false,
  editorMode: 'normal' as const,
  cssByMode: {
    normal: '.hello { color: red; }',
    tailwind: null,
  },
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
          editorMode: 'normal',
          cssByMode: {
            normal: '.hello { color: red; }',
            tailwind: null,
          },
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

  it('includes compact Tailwind candidates only for Tailwind saves', async () => {
    const params = createSaveParams({
      tailwindEnabled: true,
      html: '<div class="flex text-sm"><span className="flex md:grid"></span></div>',
    });

    await saveKayzArt(params);

    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          tailwindCandidates: ['flex', 'text-sm', 'md:grid'],
        }),
      })
    );
  });
  it('sends the base version and force flag and returns the saved version', async () => {
    const params = createSaveParams({
      apiFetch: vi.fn().mockResolvedValue({ ok: true, version: 'v2' }),
      baseVersion: 'v1',
      force: true,
    });

    const result = await saveKayzArt(params);

    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ baseVersion: 'v1', force: true }) })
    );
    expect(result).toMatchObject({ ok: true, version: 'v2' });
  });

  it('reports a conflict when the page changed outside the editor', async () => {
    const params = createSaveParams({
      apiFetch: vi.fn().mockRejectedValue({
        ok: false, code: 'kayzart_page_changed', error: 'Changed elsewhere.', version: 'v5', settings: { title: 'Other' },
      }),
      baseVersion: 'v1',
    });

    const result = await saveKayzArt(params);

    expect(result).toMatchObject({
      ok: false, conflict: true, error: 'Changed elsewhere.', conflictVersion: 'v5', conflictSettings: { title: 'Other' },
    });
  });

  it('does not report a conflict for other save errors', async () => {
    const params = createSaveParams({
      apiFetch: vi.fn().mockRejectedValue({ ok: false, error: 'Busy.' }),
    });

    const result = await saveKayzArt(params);

    expect(result).toMatchObject({ ok: false, conflict: false, error: 'Busy.' });
    expect(params.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.not.objectContaining({ baseVersion: expect.anything() }) })
    );
  });


  it('sends settings base values only together with settings updates', async () => {
    const withSettings = createSaveParams({
      settingsUpdates: { templateMode: 'theme' },
      settingsBase: { templateMode: 'standalone' },
    });
    const withoutSettings = createSaveParams({ settingsBase: { templateMode: 'standalone' } });

    await saveKayzArt(withSettings);
    await saveKayzArt(withoutSettings);

    expect(withSettings.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: expect.objectContaining({
          settingsUpdates: { templateMode: 'theme' },
          settingsBase: { templateMode: 'standalone' },
        }),
      })
    );
    expect(withoutSettings.apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.not.objectContaining({ settingsBase: expect.anything() }) })
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
        candidates: ['fresh'],
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

describe('createTailwindCompiler', () => {
  it('skips recompilation when HTML text changes without changing candidates', async () => {
    const apiFetch = vi.fn().mockResolvedValue({ ok: true, css: '.text-sm{}' });
    let html = '<div class="text-sm">First</div>';
    const compiler = createTailwindCompiler({
      apiFetch,
      restCompileUrl: '/compile-tailwind',
      postId: 7,
      getHtml: () => html,
      getCss: () => '@import "tailwindcss";',
      isTailwindEnabled: () => true,
      onCssCompiled: vi.fn(),
      onStatus: vi.fn(),
      onStatusClear: vi.fn(),
    });

    await compiler.compile();
    html = '<div class="text-sm">Second</div>';
    await compiler.compile();

    expect(apiFetch).toHaveBeenCalledTimes(1);
    expect(apiFetch).toHaveBeenCalledWith(
      expect.objectContaining({
        data: {
          post_id: 7,
          candidates: ['text-sm'],
          css: '@import "tailwindcss";',
        },
      })
    );
  });

  it('recompiles when the candidate set changes', async () => {
    const apiFetch = vi.fn().mockResolvedValue({ ok: true, css: '.generated{}' });
    let html = '<div class="text-sm">First</div>';
    const compiler = createTailwindCompiler({
      apiFetch,
      restCompileUrl: '/compile-tailwind',
      postId: 7,
      getHtml: () => html,
      getCss: () => '@import "tailwindcss";',
      isTailwindEnabled: () => true,
      onCssCompiled: vi.fn(),
      onStatus: vi.fn(),
      onStatusClear: vi.fn(),
    });

    await compiler.compile();
    html = '<div class="text-lg">Second</div>';
    await compiler.compile();

    expect(apiFetch).toHaveBeenCalledTimes(2);
  });
});
