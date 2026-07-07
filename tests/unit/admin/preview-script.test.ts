import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
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

const setupPreviewDocument = () => {
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
    labels: {
      shortcodeLabel: 'Shortcode',
      shortcodeUnavailable: 'Preview only placeholder.',
    },
  };
};

let activeWindowTimers = new Set<number>();
let nativeWindowSetTimeout: typeof window.setTimeout;
let nativeWindowClearTimeout: typeof window.clearTimeout;

beforeEach(() => {
  activeWindowTimers = new Set();
  nativeWindowSetTimeout = window.setTimeout.bind(window);
  nativeWindowClearTimeout = window.clearTimeout.bind(window);
  Object.defineProperty(window, 'setTimeout', {
    configurable: true,
    value: ((handler: TimerHandler, timeout?: number, ...args: any[]) => {
      const timer = nativeWindowSetTimeout(() => {
        activeWindowTimers.delete(timer);
        if (typeof handler === 'function') {
          handler(...args);
          return;
        }
        window.eval(String(handler));
      }, timeout);
      activeWindowTimers.add(timer);
      return timer;
    }) as typeof window.setTimeout,
  });
  Object.defineProperty(window, 'clearTimeout', {
    configurable: true,
    value: ((timer?: number) => {
      if (typeof timer === 'number') {
        activeWindowTimers.delete(timer);
      }
      nativeWindowClearTimeout(timer);
    }) as typeof window.clearTimeout,
  });
  Object.defineProperty(window, 'scrollTo', {
    configurable: true,
    value: vi.fn(),
  });
});

afterEach(() => {
  activeWindowTimers.forEach((timer) => nativeWindowClearTimeout(timer));
  activeWindowTimers.clear();
  if (nativeWindowSetTimeout) {
    Object.defineProperty(window, 'setTimeout', {
      configurable: true,
      value: nativeWindowSetTimeout,
    });
  }
  if (nativeWindowClearTimeout) {
    Object.defineProperty(window, 'clearTimeout', {
      configurable: true,
      value: nativeWindowClearTimeout,
    });
  }
});

describe('preview shortcode placeholders', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete (window as any).KAYZART_PREVIEW;
  });

  it('visualizes shortcode text without changing surrounding html', async () => {
    setupPreviewDocument();
    vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);

    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML:
        '<section><p>Before [contact-form-7 id="123"] after</p><div>[ez-toc]</div></section>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const placeholders = document.querySelectorAll('.kayzart-shortcode-placeholder');
    expect(placeholders).toHaveLength(2);
    expect(placeholders[0]?.textContent).toContain('Shortcode: contact-form-7');
    expect(placeholders[0]?.textContent).toContain('Preview only placeholder.');
    expect(placeholders[0]?.getAttribute('title')).toBe('[contact-form-7 id="123"]');
    expect(placeholders[1]?.textContent).toContain('Shortcode: ez-toc');
    expect(document.querySelector('section p')?.textContent).toContain('Before ');
    expect(document.querySelector('section p')?.textContent).toContain(' after');
  });

  it('visualizes enclosing shortcode text as one placeholder', async () => {
    setupPreviewDocument();
    vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);

    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML: '<p>Before [caption]A caption[/CAPTION] after</p>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const placeholders = document.querySelectorAll('.kayzart-shortcode-placeholder');
    expect(placeholders).toHaveLength(1);
    expect(placeholders[0]?.textContent).toContain('Shortcode: caption');
    expect(placeholders[0]?.getAttribute('title')).toBe('[caption]A caption[/CAPTION]');
    expect(document.querySelector('p')?.textContent).toContain('Before ');
    expect(document.querySelector('p')?.textContent).toContain(' after');
    expect(document.querySelector('p')?.textContent).not.toContain('A caption');
    expect(document.querySelector('p')?.textContent).not.toContain('[/CAPTION]');
  });

  it('visualizes enclosing shortcode markup as one placeholder', async () => {
    setupPreviewDocument();
    vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);

    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML: '<p>Before [caption]<img src="x.jpg">キャプション[/caption] after</p>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    const placeholders = document.querySelectorAll('.kayzart-shortcode-placeholder');
    expect(placeholders).toHaveLength(1);
    expect(placeholders[0]?.textContent).toContain('Shortcode: caption');
    expect(placeholders[0]?.getAttribute('title')).toBe(
      '[caption]<img src="x.jpg">キャプション[/caption]'
    );
    expect(document.querySelector('img')).toBeNull();
    expect(document.querySelector('p')?.textContent).toContain('Before ');
    expect(document.querySelector('p')?.textContent).toContain(' after');
    expect(document.querySelector('p')?.textContent).not.toContain('キャプション');
    expect(document.querySelector('p')?.textContent).not.toContain('[/caption]');
  });

  it('does not visualize escaped or code-like shortcode text', async () => {
    setupPreviewDocument();
    vi.spyOn(window, 'postMessage').mockImplementation(() => undefined);

    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML:
        '<p>[[gallery]]</p><pre>[gallery]</pre><code>[gallery]</code><textarea>[gallery]</textarea>',
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
    await flushAsync();

    expect(document.querySelector('.kayzart-shortcode-placeholder')).toBeNull();
    expect(document.querySelector('p')?.textContent).toBe('[[gallery]]');
    expect(document.querySelector('pre')?.textContent).toBe('[gallery]');
    expect(document.querySelector('code')?.textContent).toBe('[gallery]');
    expect(document.querySelector('textarea')?.textContent).toBe('[gallery]');
  });
});

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

  it('does not expose replace image from the preview selection menu', async () => {
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
    expect(replaceImageMenuItem).toBeNull();

    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    const image = document.querySelector('[data-kayzart-id="image-1"]');
    image?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    menuButton?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(document.getElementById('kayzart-select-parent-menu-item')).toBeTruthy();
    expect(document.getElementById('kayzart-select-copy-html-menu-item')).toBeTruthy();
    expect(document.getElementById('kayzart-select-delete-menu-item')).toBeTruthy();
    expect(document.getElementById('kayzart-select-replace-image-menu-item')).toBeNull();

    postMessage.mockClear();
    expect(postMessage).not.toHaveBeenCalledWith(
      expect.objectContaining({ type: 'KAYZART_REPLACE_IMAGE' }),
      window.location.origin
    );
  });
});

describe('preview lazy media reveal', () => {
  let nextPostId = 50;
  let currentPostId = 50;

  const setupPreview = () => {
    currentPostId = nextPostId;
    nextPostId += 1;
    document.body.innerHTML = [
      `<span data-kayzart-marker="start" data-kayzart-post-id="${currentPostId}" hidden></span>`,
      `<span data-kayzart-marker="end" data-kayzart-post-id="${currentPostId}" hidden></span>`,
    ].join('');
    (window as any).KAYZART_PREVIEW = {
      allowedOrigin: window.location.origin,
      post_id: currentPostId,
      liveHighlightEnabled: true,
      markers: {
        attr: 'data-kayzart-marker',
        postAttr: 'data-kayzart-post-id',
        start: 'start',
        end: 'end',
      },
    };
    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
  };

  const dispatchRender = (canonicalHTML: string) => {
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML,
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
  };

  afterEach(() => {
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete (window as any).KAYZART_PREVIEW;
  });

  it('copies lazy media attributes into preview display attributes', async () => {
    setupPreview();

    dispatchRender([
      '<img data-kayzart-id="lozad-image" class="lozad" data-src="image.webp" alt="">',
      '<picture data-kayzart-id="picture-1">',
      '<source data-kayzart-id="source-1" data-srcset="wide.webp 1200w, narrow.webp 600w">',
      '<img data-kayzart-id="srcset-image" data-srcset="fallback.webp 800w" alt="">',
      '</picture>',
      '<iframe data-kayzart-id="frame-1" data-src="frame.html"></iframe>',
      '<div data-kayzart-id="bg-1" data-bg="background.jpg"></div>',
      '<div data-kayzart-id="bg-2" style="background-image: url();" data-background-image="section.jpg"></div>',
    ].join(''));
    await flushAsync();

    expect(document.querySelector('[data-kayzart-id="lozad-image"]')?.getAttribute('src')).toBe(
      'image.webp'
    );
    expect(document.querySelector('[data-kayzart-id="source-1"]')?.getAttribute('srcset')).toBe(
      'wide.webp 1200w, narrow.webp 600w'
    );
    expect(document.querySelector('[data-kayzart-id="srcset-image"]')?.getAttribute('srcset')).toBe(
      'fallback.webp 800w'
    );
    expect(document.querySelector('[data-kayzart-id="frame-1"]')?.getAttribute('src')).toBe(
      'frame.html'
    );
    expect((document.querySelector('[data-kayzart-id="bg-1"]') as HTMLElement)?.style.backgroundImage).toBe(
      'url("background.jpg")'
    );
    expect((document.querySelector('[data-kayzart-id="bg-2"]') as HTMLElement)?.style.backgroundImage).toBe(
      'url("section.jpg")'
    );
  });

  it('does not overwrite existing preview display attributes', async () => {
    setupPreview();

    dispatchRender([
      '<img data-kayzart-id="image-1" src="existing.jpg" data-src="lazy.jpg" alt="">',
      '<source data-kayzart-id="source-1" srcset="existing.webp 1x" data-srcset="lazy.webp 1x">',
      '<div data-kayzart-id="bg-1" style="background-image: url(existing.jpg)" data-bg="lazy.jpg"></div>',
    ].join(''));
    await flushAsync();

    expect(document.querySelector('[data-kayzart-id="image-1"]')?.getAttribute('src')).toBe(
      'existing.jpg'
    );
    expect(document.querySelector('[data-kayzart-id="source-1"]')?.getAttribute('srcset')).toBe(
      'existing.webp 1x'
    );
    expect((document.querySelector('[data-kayzart-id="bg-1"]') as HTMLElement)?.style.backgroundImage).toBe(
      'url("existing.jpg")'
    );
  });
});

describe('preview scroll restoration', () => {
  let nextPostId = 100;
  let currentPostId = 100;
  const rects = new Map<string, Partial<DOMRect>>();
  const rectSequences = new Map<string, Partial<DOMRect>[]>();

  const makeRect = (rect: Partial<DOMRect>): DOMRect => {
    const top = rect.top ?? 0;
    const left = rect.left ?? 0;
    const width = rect.width ?? 0;
    const height = rect.height ?? 0;
    return {
      x: left,
      y: top,
      top,
      left,
      width,
      height,
      right: rect.right ?? left + width,
      bottom: rect.bottom ?? top + height,
      toJSON: () => ({}),
    } as DOMRect;
  };

  const setElementRect = (lcId: string, rect: Partial<DOMRect>, textContent = '') => {
    rects.set(textContent ? `${lcId}:${textContent}` : lcId, rect);
  };

  const setElementRectSequence = (
    lcId: string,
    sequence: Partial<DOMRect>[],
    textContent = ''
  ) => {
    rectSequences.set(textContent ? `${lcId}:${textContent}` : lcId, sequence);
  };

  const setScrollValues = (x: number, y: number) => {
    Object.defineProperty(window, 'scrollX', { configurable: true, value: x });
    Object.defineProperty(window, 'pageXOffset', { configurable: true, value: x });
    Object.defineProperty(window, 'scrollY', { configurable: true, value: y });
    Object.defineProperty(window, 'pageYOffset', { configurable: true, value: y });
  };

  const setScrollBounds = (scrollHeight: number, viewportHeight: number) => {
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: viewportHeight });
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1024 });
    Object.defineProperty(document.documentElement, 'scrollHeight', {
      configurable: true,
      value: scrollHeight,
    });
    Object.defineProperty(document.body, 'scrollHeight', {
      configurable: true,
      value: scrollHeight,
    });
    Object.defineProperty(document.documentElement, 'scrollWidth', {
      configurable: true,
      value: 1024,
    });
    Object.defineProperty(document.body, 'scrollWidth', {
      configurable: true,
      value: 1024,
    });
  };

  const setupPreview = (initialHtml = '') => {
    currentPostId = nextPostId;
    nextPostId += 1;
    document.body.innerHTML = [
      `<span data-kayzart-marker="start" data-kayzart-post-id="${currentPostId}" hidden></span>`,
      initialHtml,
      `<span data-kayzart-marker="end" data-kayzart-post-id="${currentPostId}" hidden></span>`,
    ].join('');
    (window as any).KAYZART_PREVIEW = {
      allowedOrigin: window.location.origin,
      post_id: currentPostId,
      liveHighlightEnabled: true,
      markers: {
        attr: 'data-kayzart-marker',
        postAttr: 'data-kayzart-post-id',
        start: 'start',
        end: 'end',
      },
    };
    window.eval(previewScript);
    dispatchPreviewMessage({ type: 'KAYZART_INIT' });
  };

  const dispatchRender = (
    canonicalHTML = '<main data-kayzart-id="main-1" style="height: 2400px">Content</main>'
  ) => {
    dispatchPreviewMessage({
      type: 'KAYZART_RENDER',
      canonicalHTML,
      cssText: '',
      bodyAttrs: {},
      hasBody: false,
      templateMode: 'standalone',
    });
  };

  beforeEach(() => {
    rects.clear();
    rectSequences.clear();
    setScrollValues(0, 0);
    setScrollBounds(3000, 600);
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function () {
      const lcId = this.getAttribute('data-kayzart-id');
      const textKey = lcId ? `${lcId}:${this.textContent || ''}` : '';
      const sequence = (textKey && rectSequences.get(textKey)) || (lcId && rectSequences.get(lcId));
      if (sequence && sequence.length) {
        return makeRect(sequence.length > 1 ? sequence.shift() || {} : sequence[0]);
      }
      return makeRect((textKey && rects.get(textKey)) || (lcId && rects.get(lcId)) || {});
    });
    Object.defineProperty(window, 'scrollTo', {
      configurable: true,
      value: vi.fn((x: number, y: number) => {
        setScrollValues(Number(x), Number(y));
      }),
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete (window as any).KAYZART_PREVIEW;
  });

  it('restores the previous anchor offset after rendering', () => {
    setScrollValues(0, 900);
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');
    setElementRect('main-1', { top: 260, left: 0, width: 800, height: 1200 }, 'Content');

    dispatchRender();

    expect(window.scrollTo).toHaveBeenCalledWith(0, 1010);
  });

  it('prefers a smaller visible child over a large page wrapper as the scroll anchor', () => {
    setScrollValues(0, 900);
    setElementRectSequence('root-1', [
      { top: -700, left: 0, width: 800, height: 3000 },
      { top: -500, left: 0, width: 800, height: 3000 },
    ]);
    setElementRectSequence('child-1', [
      { top: 150, left: 0, width: 500, height: 80 },
      { top: 260, left: 0, width: 500, height: 80 },
    ]);
    setupPreview(
      '<main data-kayzart-id="root-1"><section data-kayzart-id="child-1">Old</section></main>'
    );

    dispatchRender(
      '<main data-kayzart-id="root-1"><section data-kayzart-id="child-1">Content</section></main>'
    );

    expect(window.scrollTo).toHaveBeenCalledWith(0, 1010);
  });

  it('falls back to the clamped scroll position when the anchor is removed', () => {
    vi.useFakeTimers();
    setScrollValues(0, 1200);
    setScrollBounds(1000, 600);
    setElementRect('old-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setupPreview('<main data-kayzart-id="old-1" style="height: 2400px">Old</main>');

    dispatchRender();

    expect(window.scrollTo).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1420);

    expect(window.scrollTo).toHaveBeenCalledWith(0, 400);
  });

  it('falls back to the clamped scroll position when no visible anchor exists', () => {
    vi.useFakeTimers();
    setScrollValues(0, 1200);
    setScrollBounds(1000, 600);
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');

    dispatchRender();

    expect(window.scrollTo).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1420);

    expect(window.scrollTo).toHaveBeenCalledWith(0, 400);
  });

  it('restores to the same anchor even when the anchor is currently outside the viewport', () => {
    setScrollValues(0, 900);
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');
    setElementRect('main-1', { top: 900, left: 0, width: 800, height: 1200 }, 'Content');

    dispatchRender();

    expect(window.scrollTo).toHaveBeenCalledWith(0, 1650);
  });

  it('re-applies anchor restoration after layout shifts', () => {
    vi.useFakeTimers();
    Object.defineProperty(window, 'requestAnimationFrame', {
      configurable: true,
      value: undefined,
    });
    setScrollValues(0, 900);
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');
    setElementRect('main-1', { top: 250, left: 0, width: 800, height: 1200 }, 'Content');

    dispatchRender();
    expect(window.scrollTo).toHaveBeenLastCalledWith(0, 1000);

    vi.advanceTimersByTime(0);
    setElementRect('main-1', { top: 190, left: 0, width: 800, height: 1200 }, 'Content');
    vi.advanceTimersByTime(60);

    expect(window.scrollTo).toHaveBeenLastCalledWith(0, 1040);
  });

  it('restores the anchor offset after CSS-only updates', () => {
    setScrollValues(0, 900);
    setElementRectSequence('main-1', [
      { top: 150, left: 0, width: 800, height: 1200 },
      { top: 230, left: 0, width: 800, height: 1200 },
    ], 'Old');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');

    dispatchPreviewMessage({
      type: 'KAYZART_SET_CSS',
      cssText: '.example { color: red; }',
    });

    expect(window.scrollTo).toHaveBeenCalledWith(0, 980);
  });

  it('stops pending restoration when the user indicates scroll intent', () => {
    vi.useFakeTimers();
    setScrollValues(0, 900);
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Content');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');

    dispatchRender();
    expect(window.scrollTo).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(0);
    window.dispatchEvent(new WheelEvent('wheel'));
    vi.advanceTimersByTime(500);

    expect(window.scrollTo).toHaveBeenCalledTimes(1);
  });

  it('ignores later captured restore requests after user scroll intent', () => {
    vi.useFakeTimers();
    setScrollValues(0, 900);
    setElementRect('main-1', { top: 150, left: 0, width: 800, height: 1200 }, 'Old');
    setupPreview('<main data-kayzart-id="main-1" style="height: 2400px">Old</main>');
    dispatchPreviewMessage({ type: 'KAYZART_CAPTURE_SCROLL_SNAPSHOT' });
    setElementRect('main-1', { top: 260, left: 0, width: 800, height: 1200 }, 'Old');

    dispatchPreviewMessage({ type: 'KAYZART_RESTORE_CAPTURED_SCROLL' });
    expect(window.scrollTo).toHaveBeenCalled();
    vi.mocked(window.scrollTo).mockClear();

    window.dispatchEvent(new WheelEvent('wheel'));
    dispatchPreviewMessage({ type: 'KAYZART_RESTORE_CAPTURED_SCROLL' });

    expect(window.scrollTo).not.toHaveBeenCalled();
  });
});
