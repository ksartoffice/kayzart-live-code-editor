import { act } from 'react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';

(
  globalThis as typeof globalThis & { IS_REACT_ACT_ENVIRONMENT?: boolean }
).IS_REACT_ACT_ENVIRONMENT = true;

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

describe('setup wizard start options', () => {
  it('renders normal, tailwind, and template start choices', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        apiFetch: vi.fn(),
        onComplete: vi.fn(),
      }));
    });

    expect(container.textContent).toContain('Choose how to start');
    expect(container.textContent).toContain('Normal (HTML/CSS)');
    expect(container.textContent).toContain('TailwindCSS');
    expect(container.textContent).toContain('Templates');

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });

  it('loads the catalog REST endpoint for templates', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const apiFetch = vi.fn().mockResolvedValue({
      ok: true,
      templates: [
        {
          id: 'hero-en',
          title: 'Hero EN',
          description: 'English hero',
          category: 'landing',
          market: 'en',
          tier: 'free',
          thumbnailUrl: 'https://templates.kayzart.com/thumbs/hero-en.webp',
          requiresTailwind: true,
          available: true,
          version: '1.0.0',
        },
      ],
    });
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        templateCatalogRestUrl: '/wp-json/kayzart/v1/templates/catalog',
        apiFetch,
        onComplete: vi.fn(),
      }));
    });

    const templateRadio = container.querySelector<HTMLInputElement>('input[value="template"]');
    const continueButton = Array.from(container.querySelectorAll('button')).find(
      (button) => button.textContent === 'Continue'
    );

    expect(templateRadio).toBeTruthy();
    expect(continueButton).toBeTruthy();

    await act(async () => {
      templateRadio?.click();
    });
    await act(async () => {
      continueButton?.click();
    });

    expect(apiFetch).toHaveBeenCalledWith({
      url: '/wp-json/kayzart/v1/templates/catalog?post_id=123',
      method: 'GET',
    });
    expect(container.textContent).toContain('Choose a template');
    expect(container.textContent).toContain('Hero EN');

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });

  it('filters templates by market and marks pro templates unavailable', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const apiFetch = vi.fn().mockResolvedValue({
      ok: true,
      templates: [
        {
          id: 'hero-en',
          title: 'Hero EN',
          description: 'English hero',
          category: 'landing',
          market: 'en',
          tier: 'free',
          thumbnailUrl: 'https://templates.kayzart.com/thumbs/hero-en.webp',
          requiresTailwind: true,
          available: true,
          version: '1.0.0',
        },
        {
          id: 'pricing-jp',
          title: 'Pricing JP',
          description: 'Japanese pricing',
          category: 'pricing',
          market: 'jp',
          tier: 'pro',
          thumbnailUrl: 'https://templates.kayzart.com/thumbs/pricing-jp.webp',
          requiresTailwind: true,
          available: false,
          version: '1.0.0',
        },
      ],
    });
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        templateCatalogRestUrl: '/wp-json/kayzart/v1/templates/catalog',
        apiFetch,
        onComplete: vi.fn(),
      }));
    });

    await act(async () => {
      container.querySelector<HTMLInputElement>('input[value="template"]')?.click();
    });
    await act(async () => {
      Array.from(container.querySelectorAll('button')).find((button) => button.textContent === 'Continue')?.click();
    });

    const proCard = Array.from(container.querySelectorAll<HTMLElement>('.kayzart-templateCard')).find((card) => (
      card.textContent?.includes('Pricing JP')
    ));
    expect(proCard?.getAttribute('aria-disabled')).toBe('true');
    expect(container.textContent).toContain('Pro');

    await act(async () => {
      Array.from(container.querySelectorAll('button')).find((button) => button.textContent === 'JP')?.click();
    });

    expect(container.textContent).not.toContain('Hero EN');
    expect(container.textContent).toContain('Pricing JP');

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });

  it('shows an error and retries catalog loading failures', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const apiFetch = vi.fn()
      .mockResolvedValueOnce({ ok: false, error: 'Catalog failed.' })
      .mockResolvedValueOnce({ ok: true, templates: [] });
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        templateCatalogRestUrl: '/wp-json/kayzart/v1/templates/catalog',
        apiFetch,
        onComplete: vi.fn(),
      }));
    });

    await act(async () => {
      container.querySelector<HTMLInputElement>('input[value="template"]')?.click();
    });
    await act(async () => {
      Array.from(container.querySelectorAll('button')).find((button) => button.textContent === 'Continue')?.click();
    });

    expect(container.textContent).toContain('Catalog failed.');

    await act(async () => {
      Array.from(container.querySelectorAll('button')).find((button) => button.textContent === 'Retry')?.click();
    });

    expect(apiFetch).toHaveBeenCalledTimes(2);
    expect(container.textContent).toContain('No templates found.');

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });

  it('continues to call REST for the normal choice', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const apiFetch = vi.fn().mockResolvedValue({ ok: true, tailwindEnabled: false });
    const onComplete = vi.fn();
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        apiFetch,
        onComplete,
      }));
    });

    const continueButton = Array.from(container.querySelectorAll('button')).find(
      (button) => button.textContent === 'Continue'
    );

    await act(async () => {
      continueButton?.click();
    });

    expect(apiFetch).toHaveBeenCalledWith({
      url: '/wp-json/kayzart/v1/setup',
      method: 'POST',
      data: {
        post_id: 123,
        mode: 'normal',
      },
    });
    expect(onComplete).toHaveBeenCalledWith({ tailwindEnabled: false });

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });

  it('continues to call REST for the tailwind choice', async () => {
    const { SetupWizard } = await import('../../../src/admin/setup-wizard');
    const apiFetch = vi.fn().mockResolvedValue({ ok: true, tailwindEnabled: true });
    const onComplete = vi.fn();
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);

    await act(async () => {
      root.render(createElement(SetupWizard, {
        postId: 123,
        restUrl: '/wp-json/kayzart/v1/setup',
        apiFetch,
        onComplete,
      }));
    });

    const tailwindRadio = container.querySelector<HTMLInputElement>('input[value="tailwind"]');
    const continueButton = Array.from(container.querySelectorAll('button')).find(
      (button) => button.textContent === 'Continue'
    );

    await act(async () => {
      tailwindRadio?.click();
    });
    await act(async () => {
      continueButton?.click();
    });

    expect(apiFetch).toHaveBeenCalledWith({
      url: '/wp-json/kayzart/v1/setup',
      method: 'POST',
      data: {
        post_id: 123,
        mode: 'tailwind',
      },
    });
    expect(onComplete).toHaveBeenCalledWith({ tailwindEnabled: true });

    await act(async () => {
      root.unmount();
    });
    container.remove();
  });
});
