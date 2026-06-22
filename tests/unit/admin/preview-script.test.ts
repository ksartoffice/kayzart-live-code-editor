import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const previewScript = readFileSync('includes/preview.js', 'utf8');

const dispatchPreviewMessage = (data: Record<string, unknown>) => {
  window.dispatchEvent(
    new MessageEvent('message', {
      origin: window.location.origin,
      source: window.parent,
      data,
    })
  );
};

const flushAsync = async () => {
  await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('preview selector overlay', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete (window as any).KAYZART_PREVIEW;
  });

  it('selects the nearest Kayzart parent from the overlay action', async () => {
    document.body.innerHTML = [
      '<span data-kayzart-marker="start" data-kayzart-post-id="1" hidden></span>',
      '<span data-kayzart-marker="end" data-kayzart-post-id="1" hidden></span>',
    ].join('');
    (window as any).KAYZART_PREVIEW = {
      allowedOrigin: window.location.origin,
      post_id: 1,
      liveHighlightEnabled: true,
      markers: {
        attr: 'data-kayzart-marker',
        postAttr: 'data-kayzart-post-id',
        start: 'start',
        end: 'end',
      },
    };
    const postMessage = vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);

    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML:
        '<section data-kayzart-id="parent-1"><div data-kayzart-id="child-1">Child</div></section>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const child = document.querySelector('[data-kayzart-id="child-1"]');
    expect(child).toBeTruthy();
    child?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_SELECT', lcId: 'child-1' }),
      window.location.origin
    );

    const parentButton = document.getElementById('kayzart-select-parent-action');
    expect(parentButton).toBeTruthy();
    expect(parentButton?.style.display).toBe('flex');
    parentButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_SELECT', lcId: 'parent-1' }),
      window.location.origin
    );
  });
});
