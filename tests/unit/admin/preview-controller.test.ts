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
});

