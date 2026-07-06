import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act } from 'react';
import type { ElementPanelApi } from '../../../../src/admin/settings/element-panel';

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

  const mountPanel = async (
    initialSegments = [{ id: 'text-1', text: 'Original', labelHint: 'Heading' }]
  ) => {
    const { createRoot } = await import('react-dom/client');
    const React = await import('react');
    const { ElementPanel } = await import('../../../../src/admin/settings/element-panel');
    const container = document.createElement('div');
    let selectionListener: ((lcId: string | null) => void) | null = null;
    let contentListener: (() => void) | null = null;
    let segments = initialSegments;
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
});
