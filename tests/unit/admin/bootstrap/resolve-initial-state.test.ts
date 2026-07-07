import { describe, expect, it } from 'vitest';
import { resolveInitialState } from '../../../../src/admin/bootstrap/resolve-initial-state';
import type { AppConfig } from '../../../../src/admin/types/app-config';

const baseConfig: AppConfig = {
  post_id: 123,
  initialHtml: '<p>Original</p>',
  initialCss: 'body{}',
  initialJs: '',
  canEditJs: true,
  previewUrl: '/preview',
  restUrl: '/save',
  restCompileUrl: '/compile',
  setupRestUrl: '/setup',
  settingsRestUrl: '/settings',
  settingsData: {},
  restNonce: 'nonce',
};

describe('resolveInitialState', () => {
  it('uses setup template html and css when provided', () => {
    const state = resolveInitialState(baseConfig, {
      tailwindEnabled: true,
      initialHtml: '<section>Template</section>',
      initialCss: '@import "tailwindcss";',
    });

    expect(state.tailwindEnabled).toBe(true);
    expect(state.initialHtml).toBe('<section>Template</section>');
    expect(state.initialCss).toBe('@import "tailwindcss";');
  });
});
