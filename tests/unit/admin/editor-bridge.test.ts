import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const bridgeScript = readFileSync('assets/admin/editor-bridge.js', 'utf8');
const editPost = vi.fn();

const setupWordPress = () => {
  (window as any).wp = {
    domReady: (callback: () => void) => callback(),
    i18n: { __: (text: string) => text },
    data: {
      select: () => ({
        getCurrentPostId: () => 42,
        getEditedPostAttribute: (attribute: string) =>
          attribute === 'title' ? 'Translated page' : '',
      }),
      dispatch: () => ({ editPost }),
    },
  };
  (window as any).KAYZART_EDITOR = {
    postId: 42,
    enabled: true,
    actionUrl: 'http://localhost/wp-admin/admin.php?action=kayzart&_wpnonce=nonce',
    previewUrl:
      'http://localhost/?kayzart_preview=1&post_id=42&token=preview&kayzart_preview_context=wordpress_editor',
    viewUrl: 'http://localhost/sample/?preview=true',
    labels: {
      edit: 'Edit with Kayzart',
      eyebrow: 'Managed by Kayzart',
      description: 'Content is edited in Kayzart.',
      titleLabel: 'Page title',
      view: 'View page',
      loading: 'Loading preview…',
      loadFailed: 'Preview failed.',
      reload: 'Reload preview',
    },
  };
};

const renderBlockEditor = () => {
  document.body.className = 'block-editor-page';
  document.body.innerHTML = [
    '<div class="interface-interface-skeleton">',
    '<div class="interface-interface-skeleton__header">',
    '<div class="editor-document-tools"></div>',
    '</div>',
    '<div class="interface-interface-skeleton__content"></div>',
    '</div>',
  ].join('');
  setupWordPress();
  window.eval(bridgeScript);
};

const renderClassicEditor = () => {
  document.body.className = 'wp-admin post-php';
  document.body.innerHTML = [
    '<form id="post">',
    '<div id="post-body-content">',
    '<div id="titlediv"><input id="title" value="Translated page"></div>',
    '<div id="postdivrich"><textarea id="content">Stored Kayzart HTML</textarea></div>',
    '</div>',
    '<div id="postbox-container-1"><div id="submitdiv">Publish settings</div></div>',
    '<div id="acf-group">ACF settings</div>',
    '<input id="post_ID" value="42">',
    '</form>',
  ].join('');
  setupWordPress();
  window.eval(bridgeScript);
};

const cleanup = () => {
  window.dispatchEvent(new Event('unload'));
  vi.clearAllTimers();
  vi.useRealTimers();
  document.body.className = '';
  document.body.innerHTML = '';
  delete (window as any).KAYZART_EDITOR;
  delete (window as any).wp;
};

describe('Gutenberg editor bridge', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    editPost.mockReset();
    renderBlockEditor();
  });

  afterEach(cleanup);

  it('replaces the content canvas with a managed preview panel', () => {
    const host = document.querySelector('.interface-interface-skeleton__content')!;
    const panel = host.querySelector<HTMLElement>('.kayzart-editor-preview')!;
    const frame = panel.querySelector<HTMLIFrameElement>('.kayzart-editor-preview__frame')!;

    expect(host.classList.contains('kayzart-editor-preview-host')).toBe(true);
    expect(panel.querySelector('.kayzart-editor-preview__title')?.textContent).toBe(
      'Managed by Kayzart'
    );
    expect(panel.querySelector<HTMLInputElement>('.kayzart-editor-preview__titleInput')?.value).toBe(
      'Translated page'
    );
    expect(frame.src).toContain('kayzart_preview_context=wordpress_editor');
    expect(panel.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')?.href).toContain(
      'post_id=42'
    );
    expect(document.body.classList.contains('kayzart-editor-locked')).toBe(true);
  });

  it('uses only the edit action inside the preview panel', () => {
    expect(document.querySelectorAll('.kayzart-editor-preview__edit')).toHaveLength(1);
    expect(document.querySelector('.kayzart-editor-toolbar')).toBeNull();
    expect(document.querySelector('.editor-document-tools')?.children).toHaveLength(0);
  });

  it('shows a warning and can reload a slow preview', () => {
    const panel = document.querySelector<HTMLElement>('.kayzart-editor-preview')!;
    const reload = panel.querySelector<HTMLButtonElement>('.kayzart-editor-preview__reload')!;

    vi.advanceTimersByTime(12_000);
    expect(panel.classList.contains('has-load-warning')).toBe(true);
    expect(reload.hidden).toBe(false);

    reload.click();
    expect(panel.classList.contains('has-load-warning')).toBe(false);
    expect(reload.hidden).toBe(true);
  });

  it('marks the preview as loaded when the iframe finishes', () => {
    const panel = document.querySelector<HTMLElement>('.kayzart-editor-preview')!;
    const frame = panel.querySelector<HTMLIFrameElement>('.kayzart-editor-preview__frame')!;

    frame.dispatchEvent(new Event('load'));

    expect(panel.classList.contains('is-loaded')).toBe(true);
    expect(panel.classList.contains('has-load-warning')).toBe(false);
  });

  it('keeps the WordPress page title editable', () => {
    const input = document.querySelector<HTMLInputElement>('.kayzart-editor-preview__titleInput')!;
    input.value = 'Updated translated page';
    input.dispatchEvent(new Event('input', { bubbles: true }));

    expect(editPost).toHaveBeenCalledWith({ title: 'Updated translated page' });
  });
});

describe('Classic Editor bridge', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    editPost.mockReset();
    renderClassicEditor();
  });

  afterEach(cleanup);

  it('replaces only the Classic content editor with the managed preview', () => {
    const editor = document.querySelector<HTMLElement>('#postdivrich')!;
    const panel = document.querySelector<HTMLElement>('.kayzart-editor-preview--classic')!;
    const frame = panel.querySelector<HTMLIFrameElement>('.kayzart-editor-preview__frame')!;

    expect(editor.classList.contains('kayzart-classic-editor-source')).toBe(true);
    expect(editor.getAttribute('aria-hidden')).toBe('true');
    expect(panel.nextElementSibling).toBe(editor);
    expect(frame.src).toContain('kayzart_preview_context=wordpress_editor');
  });

  it('keeps the native title, publish settings, and plugin meta boxes', () => {
    expect(document.querySelector<HTMLInputElement>('#title')?.value).toBe('Translated page');
    expect(document.querySelector('#submitdiv')?.textContent).toBe('Publish settings');
    expect(document.querySelector('#acf-group')?.textContent).toBe('ACF settings');
    expect(document.querySelector('.kayzart-editor-preview__titleInput')).toBeNull();
  });

  it('renders one set of Classic-styled view and edit actions', () => {
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;
    const view = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__view')!;

    expect(edit.classList.contains('button-primary')).toBe(true);
    expect(edit.href).toContain('post_id=42');
    expect(view.classList.contains('button-secondary')).toBe(true);
    expect(view.target).toBe('_blank');
    expect(document.querySelectorAll('.kayzart-editor-preview__edit')).toHaveLength(1);
  });
});
