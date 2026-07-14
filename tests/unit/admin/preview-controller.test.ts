import { describe, expect, it, vi } from 'vitest';
import { createPreviewController } from '../../../src/admin/preview';

const flushAsync = async () => {
  await new Promise((resolve) => setTimeout(resolve, 0));
};

function createModel(value: string) {
  return {
    getValue: () => value,
    deltaDecorations: () => [],
    getPositionAt: () => ({ lineNumber: 1, column: 1 }),
  };
}

function createMutableModel(value: string) {
  return {
    value,
    getValue() {
      return this.value;
    },
    deltaDecorations: () => [],
    getPositionAt: () => ({ lineNumber: 1, column: 1 }),
  };
}

function mockObjectUrls() {
  class MockBlob {
    parts: string[];
    type: string;

    constructor(parts: string[], options?: { type?: string }) {
      this.parts = parts;
      this.type = options?.type ?? '';
    }

    text() {
      return Promise.resolve(this.parts.join(''));
    }
  }

  vi.stubGlobal('Blob', MockBlob);
  let lastBlob: MockBlob | null = null;
  const createObjectURL = vi.fn((blob: MockBlob) => {
    lastBlob = blob;
    return 'blob:preview';
  });
  const revokeObjectURL = vi.fn();
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: createObjectURL,
  });
  Object.defineProperty(URL, 'revokeObjectURL', {
    configurable: true,
    value: revokeObjectURL,
  });
  return {
    createObjectURL,
    revokeObjectURL,
    getLastBlob: () => lastBlob,
  };
}

function createIframe(contentWindow: Window, initialSrc = 'https://example.com/preview') {
  let src = initialSrc;
  const setSrc = vi.fn((next: string) => {
    src = next;
  });
  return {
    contentWindow,
    get src() {
      return src;
    },
    set src(next: string) {
      setSrc(next);
    },
    getAttribute: (name: string) => (name === 'src' ? src : null),
    setSrc,
  } as unknown as HTMLIFrameElement & { setSrc: ReturnType<typeof vi.fn> };
}

describe('preview shortcode handling', () => {
  it('sends shortcode text as-is to the preview iframe', async () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const htmlModel = createModel('<div>[ez-toc]</div>');
    const cssModel = createModel('');
    const jsModel = createModel('');

    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: htmlModel as any,
      cssModel: cssModel as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);
    await flushAsync();
    postMessage.mockClear();

    controller.sendRender();

    const renderCall = postMessage.mock.calls.find((entry) => entry?.[0]?.type === 'KAYZART_RENDER');
    expect(renderCall).toBeTruthy();
    const payload = renderCall?.[0] as { canonicalHTML?: string };
    expect(payload.canonicalHTML).toContain('[ez-toc]');
  });

  it('sends body inner html and body attrs separately to the preview iframe', async () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const htmlModel = createModel('<body class="lp" data-page="x"><main>Body content</main></body>');
    const cssModel = createModel('');
    const jsModel = createModel('');

    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: htmlModel as any,
      cssModel: cssModel as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'theme',
    });

    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);
    await flushAsync();
    postMessage.mockClear();

    controller.sendRender();

    const renderCall = postMessage.mock.calls.find((entry) => entry?.[0]?.type === 'KAYZART_RENDER');
    expect(renderCall).toBeTruthy();
    const payload = renderCall?.[0] as {
      canonicalHTML?: string;
      bodyAttrs?: Record<string, string>;
      hasBody?: boolean;
      templateMode?: string;
    };
    expect(payload.canonicalHTML).toBe('<main data-kayzart-id="kayzart-1">Body content</main>');
    expect(payload.bodyAttrs).toEqual({ class: 'lp', 'data-page': 'x' });
    expect(payload.hasBody).toBe(true);
    expect(payload.templateMode).toBe('theme');
  });

  it('ignores messages from non-iframe windows even with same origin', () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const attackerWindow = {} as Window;
    const htmlModel = createModel('<div>hello</div>');
    const cssModel = createModel('');
    const jsModel = createModel('');

    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: htmlModel as any,
      cssModel: cssModel as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.handleMessage({
      origin: 'https://example.com',
      source: attackerWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);

    controller.sendRender();
    const renderCall = postMessage.mock.calls.find((entry) => entry?.[0]?.type === 'KAYZART_RENDER');
    expect(renderCall).toBeFalsy();
  });

  it('forwards overlay action events from preview iframe', () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const htmlModel = createModel('<div>hello</div>');
    const cssModel = createModel('');
    const jsModel = createModel('');
    const onOverlayAction = vi.fn();

    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: htmlModel as any,
      cssModel: cssModel as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
      onOverlayAction,
    });

    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_OVERLAY_ACTION', actionId: 'test-action' },
    } as MessageEvent);

    expect(onOverlayAction).toHaveBeenCalledTimes(1);
    expect(onOverlayAction).toHaveBeenCalledWith('test-action');
  });

  it('does not reload the iframe when only custom head changes before sendRender', async () => {
    const objectUrls = mockObjectUrls();
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const iframe = createIframe(contentWindow);
    const htmlModel = createModel('<div>hello</div>');
    const customHeadModel = createMutableModel('<meta name="a" content="1">');
    const cssModel = createModel('');
    const jsModel = createModel('');

    const controller = createPreviewController({
      iframe,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: htmlModel as any,
      customHeadModel: customHeadModel as any,
      cssModel: cssModel as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => customHeadModel.getValue(),
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);
    await flushAsync();
    postMessage.mockClear();
    customHeadModel.value = '<meta name="a" content="2">';

    controller.sendRender();

    expect(objectUrls.createObjectURL).not.toHaveBeenCalled();
    expect(iframe.setSrc).not.toHaveBeenCalled();
    const renderCall = postMessage.mock.calls.find((entry) => entry?.[0]?.type === 'KAYZART_RENDER');
    expect(renderCall?.[0]).toMatchObject({
      customHead: '<meta name="a" content="1">',
    });
    vi.unstubAllGlobals();
  });

  it('reloads with unsaved custom head injected into blob html', async () => {
    const objectUrls = mockObjectUrls();
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const iframe = createIframe(contentWindow);
    const customHeadModel = createMutableModel('<meta name="draft" content="head">');
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve({
          text: () => Promise.resolve('<!doctype html><html><head></head><body></body></html>'),
        })
      )
    );

    const controller = createPreviewController({
      iframe,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: createModel('<div>hello</div>') as any,
      customHeadModel: customHeadModel as any,
      cssModel: createModel('') as any,
      jsModel: createModel('') as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => customHeadModel.getValue(),
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.handleIframeLoad();
    await flushAsync();
    postMessage.mockClear();
    controller.requestReloadPreview();

    const html = await objectUrls.getLastBlob()?.text();
    expect(html).toContain('<base href="https://example.com/">');
    expect(html).toContain('<meta name="draft" content="head">');
    expect(postMessage).toHaveBeenCalledWith(
      { type: 'KAYZART_SAVE_SCROLL' },
      'https://example.com'
    );
    expect(iframe.setSrc).toHaveBeenCalledWith('blob:preview');
    vi.unstubAllGlobals();
  });

  it('runs current JavaScript after requestReloadPreview finishes rendering', async () => {
    mockObjectUrls();
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const iframe = createIframe(contentWindow);
    const jsModel = createMutableModel('console.log("draft js");');
    vi.stubGlobal(
      'fetch',
      vi.fn(() =>
        Promise.resolve({
          text: () => Promise.resolve('<!doctype html><html><head></head><body></body></html>'),
        })
      )
    );

    const controller = createPreviewController({
      iframe,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: createModel('<div>hello</div>') as any,
      customHeadModel: createModel('') as any,
      cssModel: createModel('') as any,
      jsModel: jsModel as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => true,
      getJsMode: () => 'module',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.handleIframeLoad();
    await flushAsync();
    controller.requestReloadPreview();
    controller.handleIframeLoad();
    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);
    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_RENDERED' },
    } as MessageEvent);

    const runCall = postMessage.mock.calls.find((entry) => entry?.[0]?.type === 'KAYZART_RUN_JS');
    expect(runCall?.[0]).toMatchObject({
      jsText: 'console.log("draft js");',
      jsMode: 'module',
    });
    vi.unstubAllGlobals();
  });

  it('requests saved scroll restoration in the preview iframe', () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: createModel('<div>hello</div>') as any,
      customHeadModel: createModel('') as any,
      cssModel: createModel('') as any,
      jsModel: createModel('') as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.restoreSavedScrollPosition();

    expect(postMessage).toHaveBeenCalledWith(
      { type: 'KAYZART_RESTORE_SAVED_SCROLL' },
      'https://example.com'
    );
  });

  it('requests captured scroll restoration in the preview iframe', () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: createModel('<div>hello</div>') as any,
      customHeadModel: createModel('') as any,
      cssModel: createModel('') as any,
      jsModel: createModel('') as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.captureScrollSnapshot();
    controller.restoreCapturedScrollPosition();

    expect(postMessage).toHaveBeenCalledWith(
      { type: 'KAYZART_CAPTURE_SCROLL_SNAPSHOT' },
      'https://example.com'
    );
    expect(postMessage).toHaveBeenCalledWith(
      { type: 'KAYZART_RESTORE_CAPTURED_SCROLL' },
      'https://example.com'
    );
  });

  it('notifies the preview iframe about client mode after it is ready', () => {
    const postMessage = vi.fn();
    const contentWindow = { postMessage } as unknown as Window;
    const controller = createPreviewController({
      iframe: { contentWindow } as unknown as HTMLIFrameElement,
      postId: 1,
      targetOrigin: 'https://example.com',
      htmlModel: createModel('<div>hello</div>') as any,
      customHeadModel: createModel('') as any,
      cssModel: createModel('') as any,
      jsModel: createModel('') as any,
      htmlEditor: { revealRangeInCenter: () => {}, focus: () => {} } as any,
      cssEditor: { revealRangeInCenter: () => {} } as any,
      focusHtmlEditor: () => {},
      getPreviewCss: () => '',
      getCustomHead: () => '',
      getLiveHighlightEnabled: () => true,
      getJsEnabled: () => false,
      getJsMode: () => 'classic',
      getResolvedTemplateMode: () => 'standalone',
    });

    controller.sendWorkspaceMode('client');
    controller.handleMessage({
      origin: 'https://example.com',
      source: contentWindow,
      data: { type: 'KAYZART_READY' },
    } as MessageEvent);

    expect(postMessage).toHaveBeenCalledWith(
      { type: 'KAYZART_SET_WORKSPACE_MODE', mode: 'client' },
      'https://example.com'
    );
  });
});

