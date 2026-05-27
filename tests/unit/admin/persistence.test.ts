import { describe, expect, it, vi } from 'vitest';
import { saveKayzArt } from '../../../src/admin/persistence';

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
