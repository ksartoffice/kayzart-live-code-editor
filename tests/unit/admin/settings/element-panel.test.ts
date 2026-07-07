import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import type {
  ElementPanelActionInfo,
  ElementPanelApi,
  ElementPanelImageInfo,
} from '../../../../src/admin/settings/element-panel';

(globalThis as any).IS_REACT_ACT_ENVIRONMENT = true;

vi.mock('@wordpress/i18n', () => ({
  __: (text: string) => text,
}));

vi.mock('@wordpress/element', async () => {
  const React = await vi.importActual<typeof import('react')>('react');
  const ReactDomClient = await vi.importActual<typeof import('react-dom/client')>(
    'react-dom/client'
  );

  return {
    createElement: React.createElement,
    Fragment: React.Fragment,
    useCallback: React.useCallback,
    useEffect: React.useEffect,
    useMemo: React.useMemo,
    useRef: React.useRef,
    useState: React.useState,
    createRoot: ReactDomClient.createRoot,
    render: vi.fn(),
  };
});

describe('ElementPanel', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.replaceChildren();
    vi.resetModules();
  });

  const createImageInfo = (
    overrides: Partial<ElementPanelImageInfo> = {}
  ): ElementPanelImageInfo => ({
    imageLcId: 'image-1',
    tagName: 'img',
    src: 'old.jpg',
    alt: 'Sample image',
    title: '',
    hasSrcset: false,
    hasDataSrc: false,
    hasDataSrcset: false,
    hasPictureSources: false,
    ...overrides,
  });

  const mountPanel = async (
    initialSegments = [{ id: 'text-1', text: 'Original', labelHint: 'Heading' }],
    initialActionInfo: ElementPanelActionInfo | null = null,
    initialImageInfo: ElementPanelImageInfo | null = null
  ) => {
    const { createRoot } = await import('react-dom/client');
    const React = await import('react');
    const { ElementPanel } = await import('../../../../src/admin/settings/element-panel');
    const container = document.createElement('div');
    let selectionListener: ((lcId: string | null) => void) | null = null;
    let contentListener: (() => void) | null = null;
    let segments = initialSegments;
    let actionInfo = initialActionInfo;
    let imageInfo = initialImageInfo;
    const api: ElementPanelApi = {
      subscribeSelection: (listener) => {
        selectionListener = listener;
        return () => {};
      },
      subscribeContentChange: (listener) => {
        contentListener = listener;
        return () => {};
      },
      getTextSegments: vi.fn(() => segments),
      getElementActionInfo: vi.fn(() => actionInfo),
      getElementImageInfo: vi.fn(() => imageInfo),
      updateElementActionInfo: vi.fn((_lcId, action) => {
        if (!actionInfo) {
          return false;
        }
        actionInfo = { ...actionInfo, ...action };
        return true;
      }),
      updateElementImageInfo: vi.fn((_lcId, image) => {
        if (!imageInfo) {
          return false;
        }
        imageInfo = { ...imageInfo, ...image };
        return true;
      }),
      replaceElementImage: vi.fn(() => true),
      updateTextSegment: vi.fn((_lcId, segmentId, nextText) => {
        segments = segments.map((segment) =>
          segment.id === segmentId ? { ...segment, text: nextText } : segment
        );
        return true;
      }),
      getElementAttributes: vi.fn(() => []),
      updateElementAttributes: vi.fn(() => true),
    };

    document.body.append(container);
    const root = createRoot(container);
    await act(async () => {
      root.render(React.createElement(ElementPanel, { api }));
    });
    await act(async () => {
      selectionListener?.('heading-1');
    });

    return {
      api,
      container,
      contentListener: () => contentListener?.(),
      getSegments: () => segments,
      setSegments: (nextSegments: typeof segments) => {
        segments = nextSegments;
      },
      setActionInfo: (nextActionInfo: typeof actionInfo) => {
        actionInfo = nextActionInfo;
      },
      setImageInfo: (nextImageInfo: typeof imageInfo) => {
        imageInfo = nextImageInfo;
      },
      textareas: () => Array.from(container.querySelectorAll('textarea')) as HTMLTextAreaElement[],
    };
  };

  const inputText = async (textarea: HTMLTextAreaElement, value: string) => {
    await act(async () => {
      const valueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLTextAreaElement.prototype,
        'value'
      )?.set;
      valueSetter?.call(textarea, value);
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
  };

  const inputValue = async (input: HTMLInputElement, value: string) => {
    await act(async () => {
      const valueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype,
        'value'
      )?.set;
      valueSetter?.call(input, value);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
  };

  const toggleCheckbox = async (input: HTMLInputElement) => {
    await act(async () => {
      input.click();
    });
  };

  it('renders multiple text segments and updates a changed segment', async () => {
    const { api, textareas } = await mountPanel([
      { id: 'text-1', text: 'Keep your AI-made landing pages', labelHint: 'Heading' },
      { id: 'text-2', text: 'inside WordPress.', labelHint: 'Text' },
    ]);

    expect(textareas()).toHaveLength(2);
    expect(textareas()[0].value).toBe('Keep your AI-made landing pages');
    expect(textareas()[1].value).toBe('inside WordPress.');

    await inputText(textareas()[1], 'inside your WordPress site.');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateTextSegment).toHaveBeenCalledWith(
      'heading-1',
      'text-2',
      'inside your WordPress site.'
    );
  });

  it('does not overwrite a focused dirty draft on content refresh', async () => {
    const { container, contentListener, setSegments, textareas } = await mountPanel();

    await act(async () => {
      textareas()[0].focus();
    });
    await inputText(textareas()[0], 'Draft value');

    setSegments([{ id: 'text-1', text: 'External value', labelHint: 'Heading' }]);
    await act(async () => {
      contentListener();
    });

    expect((container.querySelector('textarea') as HTMLTextAreaElement).value).toBe(
      'Draft value'
    );
  });

  it('shows advanced settings instead of an Attributes heading', async () => {
    const { container } = await mountPanel([]);

    expect(container.textContent).toContain('Advanced settings');
    expect(container.textContent).not.toContain('Attributes');
    expect(container.querySelector('details')).not.toBeNull();
  });

  it('shows link controls and updates the destination', async () => {
    const { api, container } = await mountPanel(
      [{ id: 'text-1', text: 'Contact', labelHint: 'Link text' }],
      {
        kind: 'link',
        tagName: 'a',
        href: '/contact',
        targetBlank: false,
        rel: '',
        disabled: false,
      }
    );

    expect(container.textContent).toContain('Link destination');
    expect(container.textContent).toContain('Open in new tab');
    const input = container.querySelector('#kayzart-elements-link-destination') as HTMLInputElement;
    expect(input.value).toBe('/contact');

    await inputValue(input, '/booking');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementActionInfo).toHaveBeenCalledWith('heading-1', {
      href: '/booking',
    });
  });

  it('shows button link controls for cta links and toggles target blank', async () => {
    const { api, container } = await mountPanel(
      [{ id: 'text-1', text: 'Download', labelHint: 'Button text' }],
      {
        kind: 'button',
        tagName: 'a',
        href: '/download',
        targetBlank: false,
        rel: '',
        disabled: false,
      }
    );

    expect(container.textContent).toContain('Button');
    expect(container.textContent).toContain('Link destination');
    const checkbox = Array.from(container.querySelectorAll('input')).find(
      (input) => input.type === 'checkbox'
    ) as HTMLInputElement;

    await toggleCheckbox(checkbox);
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementActionInfo).toHaveBeenCalledWith('heading-1', {
      targetBlank: true,
    });
  });

  it('shows disabled control for button elements without link destination', async () => {
    const { api, container } = await mountPanel(
      [{ id: 'text-1', text: 'Submit', labelHint: 'Button text' }],
      {
        kind: 'button',
        tagName: 'button',
        href: '',
        targetBlank: false,
        rel: '',
        disabled: false,
      }
    );

    expect(container.textContent).toContain('Disabled');
    expect(container.textContent).not.toContain('Link destination');
    const checkbox = container.querySelector('input[type="checkbox"]') as HTMLInputElement;

    await toggleCheckbox(checkbox);
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementActionInfo).toHaveBeenCalledWith('heading-1', {
      disabled: true,
    });
  });

  it('shows image controls and updates the image url', async () => {
    const { api, container } = await mountPanel([], null, createImageInfo());

    expect(container.textContent).toContain('Image');
    expect(container.textContent).toContain('Replace image');
    expect(container.textContent).toContain('Image URL');
    expect(container.textContent).toContain('Alt text');
    expect(container.querySelector('.kayzart-elementsImagePreview img')).not.toBeNull();

    const input = container.querySelector('#kayzart-elements-image-url') as HTMLInputElement;
    await inputValue(input, 'new.jpg');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementImageInfo).toHaveBeenCalledWith('heading-1', {
      src: 'new.jpg',
    });
  });

  it('shows the fallback image source in the url field and preview', async () => {
    const { container } = await mountPanel(
      [],
      null,
      createImageInfo({
        src: 'lazy.jpg',
        hasDataSrc: true,
      })
    );

    const input = container.querySelector('#kayzart-elements-image-url') as HTMLInputElement;
    const preview = container.querySelector(
      '.kayzart-elementsImagePreview img'
    ) as HTMLImageElement;

    expect(input.value).toBe('lazy.jpg');
    expect(preview.getAttribute('src')).toBe('lazy.jpg');
  });

  it('updates image alt text', async () => {
    const { api, container } = await mountPanel([], null, createImageInfo());

    const input = container.querySelector('#kayzart-elements-image-alt') as HTMLInputElement;
    await inputValue(input, 'Updated description');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementImageInfo).toHaveBeenCalledWith('heading-1', {
      alt: 'Updated description',
    });
  });

  it('calls replace image from the image section', async () => {
    const { api, container } = await mountPanel([], null, createImageInfo());

    const button = Array.from(container.querySelectorAll('button')).find(
      (entry) => entry.textContent === 'Replace image'
    ) as HTMLButtonElement;
    await act(async () => {
      button.click();
    });

    expect(api.replaceElementImage).toHaveBeenCalledWith('heading-1');
  });

  it('shows a responsive source notice only when image sources are present', async () => {
    const notice =
      'This image has responsive sources. Updating the URL will use the new image for all sources.';
    const normal = await mountPanel([], null, createImageInfo());

    expect(normal.container.textContent).not.toContain(notice);
    normal.container.remove();

    const responsive = await mountPanel(
      [],
      null,
      createImageInfo({
        hasSrcset: true,
        hasPictureSources: true,
      })
    );

    expect(responsive.container.textContent).toContain(notice);
  });

  it('can show link and image sections together', async () => {
    const { container } = await mountPanel(
      [],
      {
        kind: 'link',
        tagName: 'a',
        href: '/gallery',
        targetBlank: false,
        rel: '',
        disabled: false,
      },
      createImageInfo()
    );

    expect(container.textContent).toContain('Link destination');
    expect(container.textContent).toContain('Image URL');
    expect(container.textContent).toContain('Advanced settings');
  });
});
