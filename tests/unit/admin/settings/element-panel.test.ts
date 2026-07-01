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

  const mountPanel = async (initialText = 'Original') => {
    const { createRoot } = await import('react-dom/client');
    const React = await import('react');
    const { ElementPanel } = await import('../../../../src/admin/settings/element-panel');
    const container = document.createElement('div');
    let selectionListener: ((lcId: string | null) => void) | null = null;
    let contentListener: (() => void) | null = null;
    let text = initialText;
    const api: ElementPanelApi = {
      subscribeSelection: (listener) => {
        selectionListener = listener;
        return () => {};
      },
      subscribeContentChange: (listener) => {
        contentListener = listener;
        return () => {};
      },
      getElementText: vi.fn(() => text),
      updateElementText: vi.fn((_lcId, nextText) => {
        text = nextText;
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

    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;
    return {
      api,
      container,
      contentListener: () => contentListener?.(),
      getText: () => text,
      setText: (nextText: string) => {
        text = nextText;
      },
      textarea,
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

  it('keeps unsafe inner HTML local until the draft becomes safe', async () => {
    const { api, textarea } = await mountPanel();

    await inputText(textarea, '<span class="');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(textarea.value).toBe('<span class="');
    expect(api.updateElementText).not.toHaveBeenCalled();

    await inputText(textarea, '<span class="text-gradient">inside</span>');
    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api.updateElementText).toHaveBeenCalledWith(
      'heading-1',
      '<span class="text-gradient">inside</span>'
    );
  });

  it('does not overwrite a focused dirty draft on content refresh', async () => {
    const { container, contentListener, setText, textarea } = await mountPanel();

    await act(async () => {
      textarea.focus();
    });
    await inputText(textarea, 'Draft value');

    setText('External value');
    await act(async () => {
      contentListener();
    });

    expect((container.querySelector('textarea') as HTMLTextAreaElement).value).toBe(
      'Draft value'
    );
  });
});
