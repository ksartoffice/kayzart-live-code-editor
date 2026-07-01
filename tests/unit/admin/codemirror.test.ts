import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  decodeKeybinding,
  EditorRange,
  initCodeMirrorEditors,
} from '../../../src/admin/codemirror';
import { EditorView } from '@codemirror/view';

describe('codemirror keybinding decoding', () => {
  it('decodes Shift + Alt + F for HTML formatting', () => {
    const keyModAlt = 1 << 9;
    const keyModShift = 1 << 10;
    const keyCodeF = 3;

    expect(decodeKeybinding(keyModShift | keyModAlt | keyCodeF)).toBe('Shift-Alt-f');
  });
});

describe('codemirror editor selection highlight', () => {
  beforeEach(() => {
    Object.defineProperty(Range.prototype, 'getClientRects', {
      configurable: true,
      value: () => [],
    });
    Object.defineProperty(Range.prototype, 'getBoundingClientRect', {
      configurable: true,
      value: () => new DOMRect(0, 0, 0, 0),
    });
  });

  afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  const createContainer = () => {
    const container = document.createElement('div');
    document.body.appendChild(container);
    return container;
  };

  const createEditors = (onBeforeHtmlUserInteraction = vi.fn()) =>
    initCodeMirrorEditors({
      initialHtml: '<img class="sec7_ttlL" alt="osechi ayakasane" src="image.webp">',
      initialCustomHead: '',
      initialCss: '',
      initialJs: '',
      htmlWordWrap: 'off',
      tailwindEnabled: false,
      useTailwindDefault: false,
      canEditJs: true,
      htmlContainer: createContainer(),
      customHeadContainer: createContainer(),
      cssContainer: createContainer(),
      jsContainer: createContainer(),
      onBeforeHtmlUserInteraction,
    });

  it('clears active HTML decorations in the same user edit transaction', async () => {
    const onBeforeHtmlUserInteraction = vi.fn();
    const { htmlModel } = await createEditors(onBeforeHtmlUserInteraction);
    const original = htmlModel.getValue();
    const highlightIds = htmlModel.deltaDecorations([], [
      {
        range: new EditorRange(1, 1, 1, original.length + 1),
        options: {
          className: 'kayzart-highlight-line',
          inlineClassName: 'kayzart-highlight-inline',
        },
      },
    ]);

    expect(highlightIds).toHaveLength(1);
    expect(document.querySelector('.kayzart-highlight-inline')).toBeTruthy();

    const view = EditorView.findFromDOM(document.querySelector('.cm-editor') as HTMLElement);
    const start = original.indexOf('osechi');
    const end = start + 'osechi'.length;
    expect(view).toBeTruthy();
    view?.dispatch({
      changes: {
        from: start,
        to: end,
        insert: 'a',
      },
      userEvent: 'input.type',
    });

    expect(htmlModel.getValue()).toContain('alt="a ayakasane"');
    expect(onBeforeHtmlUserInteraction).toHaveBeenCalledTimes(1);
    expect(document.querySelector('.kayzart-highlight-inline')).toBeFalsy();

    const nextIds = htmlModel.deltaDecorations(highlightIds, []);
    expect(nextIds).toEqual([]);
    expect(document.querySelector('.kayzart-highlight-inline')).toBeFalsy();
  });

  it('clears active HTML decorations when the user starts selecting text', async () => {
    const onBeforeHtmlUserInteraction = vi.fn();
    const { htmlModel } = await createEditors(onBeforeHtmlUserInteraction);
    const original = htmlModel.getValue();
    htmlModel.deltaDecorations([], [
      {
        range: new EditorRange(1, 1, 1, original.length + 1),
        options: {
          className: 'kayzart-highlight-line',
          inlineClassName: 'kayzart-highlight-inline',
        },
      },
    ]);

    expect(document.querySelector('.kayzart-highlight-inline')).toBeTruthy();

    const view = EditorView.findFromDOM(document.querySelector('.cm-editor') as HTMLElement);
    const start = original.indexOf('osechi');
    const end = start + 'osechi'.length;
    expect(view).toBeTruthy();
    view?.dispatch({
      selection: {
        anchor: start,
        head: end,
      },
      userEvent: 'select.pointer',
    });

    expect(onBeforeHtmlUserInteraction).toHaveBeenCalledTimes(1);
    expect(document.querySelector('.kayzart-highlight-inline')).toBeFalsy();
  });

  it('keeps decorations for programmatic edits without user events', async () => {
    const onBeforeHtmlUserInteraction = vi.fn();
    const { codemirror, htmlModel } = await createEditors(onBeforeHtmlUserInteraction);
    const original = htmlModel.getValue();

    htmlModel.deltaDecorations([], [
      {
        range: new codemirror.Range(1, 1, 1, original.length + 1),
        options: {
          className: 'kayzart-highlight-line',
          inlineClassName: 'kayzart-highlight-inline',
        },
      },
    ]);

    htmlModel.pushEditOperations(
      [],
      [
        {
          range: new codemirror.Range(1, original.length + 1, 1, original.length + 1),
          text: '\n',
        },
      ]
    );

    expect(onBeforeHtmlUserInteraction).not.toHaveBeenCalled();
    expect(document.querySelector('.kayzart-highlight-inline')).toBeTruthy();
  });
});
