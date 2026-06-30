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

  it('selects the nearest Kayzart parent from the context menu action', async () => {
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

    expect(document.getElementById('kayzart-select-parent-action')).toBeNull();

    const menuButton = document.getElementById('kayzart-select-menu-action');
    expect(menuButton).toBeTruthy();
    expect(menuButton?.style.display).toBe('flex');
    expect(menuButton?.getAttribute('aria-haspopup')).toBe('menu');
    expect(menuButton?.getAttribute('aria-expanded')).toBe('false');

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    const menu = document.getElementById('kayzart-select-context-menu');
    const parentMenuItem = document.getElementById('kayzart-select-parent-menu-item');
    expect(menu).toBeTruthy();
    expect(menu?.style.display).toBe('block');
    expect(parentMenuItem).toBeTruthy();
    expect(parentMenuItem?.getAttribute('aria-disabled')).toBe('false');

    parentMenuItem?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_SELECT', lcId: 'parent-1' }),
      window.location.origin
    );
    expect(menu?.style.display).toBe('none');

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(menu?.style.display).toBe('block');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    expect(menu?.style.display).toBe('none');

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(menu?.style.display).toBe('block');
    document.body.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(menu?.style.display).toBe('none');
  });

  it('keeps the context menu button visible and disables parent navigation without a parent', async () => {
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
      canonicalHTML: '<section data-kayzart-id="root-1">Root</section>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const root = document.querySelector('[data-kayzart-id="root-1"]');
    expect(root).toBeTruthy();
    root?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_SELECT', lcId: 'root-1' }),
      window.location.origin
    );

    const menuButton = document.getElementById('kayzart-select-menu-action');
    expect(menuButton).toBeTruthy();
    expect(menuButton?.style.display).toBe('flex');

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    const parentMenuItem = document.getElementById('kayzart-select-parent-menu-item');
    expect(parentMenuItem).toBeTruthy();
    expect(parentMenuItem?.getAttribute('aria-disabled')).toBe('true');
    expect(parentMenuItem?.style.color).toBe('rgb(156, 163, 175)');

    postMessage.mockClear();
    parentMenuItem?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    expect(postMessage).not.toHaveBeenCalled();
  });

  it('shows replace image only for image selections and posts the selected id', async () => {
    document.body.innerHTML = [
      '<span data-kayzart-marker="start" data-kayzart-post-id="1" hidden></span>',
      '<span data-kayzart-marker="end" data-kayzart-post-id="1" hidden></span>',
    ].join('');
    (window as any).KAYZART_PREVIEW = {
      allowedOrigin: window.location.origin,
      post_id: 1,
      liveHighlightEnabled: true,
      labels: {
        replaceImage: 'Replace image',
      },
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
        '<section data-kayzart-id="section-1">Text</section><img data-kayzart-id="image-1" src="old.jpg">',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const section = document.querySelector('[data-kayzart-id="section-1"]');
    section?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    const menuButton = document.getElementById('kayzart-select-menu-action');
    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    const replaceImageMenuItem = document.getElementById('kayzart-select-replace-image-menu-item');
    expect(replaceImageMenuItem).toBeTruthy();
    expect(replaceImageMenuItem?.textContent).toBe('Replace image');
    expect(replaceImageMenuItem?.style.display).toBe('none');

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    const image = document.querySelector('[data-kayzart-id="image-1"]');
    image?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(replaceImageMenuItem?.style.display).toBe('block');

    postMessage.mockClear();
    replaceImageMenuItem?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_REPLACE_IMAGE', lcId: 'image-1' }),
      window.location.origin
    );
  });
});
