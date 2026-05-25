import { describe, expect, it, vi } from 'vitest';

const elementMocks = vi.hoisted(() => ({
  render: vi.fn(),
}));

vi.mock('@wordpress/i18n', () => ({
  __: (text: string) => text,
}));

vi.mock('@wordpress/element', async () => {
  const React = await vi.importActual<typeof import('react')>('react');

  return {
    createElement: React.createElement,
    Fragment: React.Fragment,
    useState: React.useState,
    createRoot: undefined,
    render: elementMocks.render,
  };
});

describe('setup wizard legacy render fallback', () => {
  it('renders into the temporary host so completion can remove the modal', async () => {
    const { runSetupWizard } = await import('../../../src/admin/setup-wizard');
    const container = document.createElement('div');

    void runSetupWizard({
      container,
      postId: 123,
      restUrl: '/wp-json/kayzart/v1/setup',
      apiFetch: vi.fn(),
    });

    expect(elementMocks.render).toHaveBeenCalledTimes(1);
    expect(elementMocks.render.mock.calls[0]?.[1]).toBe(container.querySelector('.kayzart-setupHost'));
  });
});
