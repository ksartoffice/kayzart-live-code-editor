import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const bridgeScript = readFileSync('assets/admin/editor-bridge.js', 'utf8');
const editPost = vi.fn();
const savePost = vi.fn();
let editorDirty = false;
let saveSucceeded = false;

const setupWordPress = (supportsTitle = true) => {
  (window as any).wp = {
    domReady: (callback: () => void) => callback(),
    i18n: { __: (text: string) => text },
    data: {
      select: () => ({
        getCurrentPostId: () => 42,
        isEditedPostDirty: () => editorDirty,
        didPostSaveRequestSucceed: () => saveSucceeded,
        getEditedPostAttribute: (attribute: string) =>
          attribute === 'title' ? 'Translated page' : '',
      }),
      dispatch: () => ({ editPost, savePost }),
    },
  };
  (window as any).KAYZART_EDITOR = {
    postId: 42,
    supportsTitle,
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

const renderBlockEditor = (supportsTitle = true) => {
  document.body.className = 'block-editor-page';
  document.body.innerHTML = [
    '<div class="interface-interface-skeleton">',
    '<div class="interface-interface-skeleton__header">',
    '<div class="editor-document-tools"></div>',
    '</div>',
    '<div class="interface-interface-skeleton__content"></div>',
    '</div>',
  ].join('');
  setupWordPress(supportsTitle);
  window.eval(bridgeScript);
};

const renderClassicEditor = ({
  includeEditor = true,
  includeTitle = true,
  postStatus = 'draft',
}: { includeEditor?: boolean; includeTitle?: boolean; postStatus?: string } = {}) => {
  document.body.className = 'wp-admin post-php';
  document.body.innerHTML = [
    '<form id="post">',
    '<div id="post-body-content">',
    includeTitle
      ? '<div id="titlediv"><input id="title" value="Translated page"></div>'
      : '',
    includeEditor
      ? '<div id="postdivrich"><textarea id="content">Stored Kayzart HTML</textarea></div>'
      : '',
    '</div>',
    '<div id="postbox-container-1"><div id="submitdiv">Publish settings</div></div>',
    '<div id="acf-group">ACF settings</div>',
    '<input id="post_ID" value="42">',
    `<input id="post_status" value="${postStatus}">`,
    '<button id="save-post" type="submit">Save draft</button>',
    '<button id="publish" type="submit">Update</button>',
    '</form>',
  ].join('');
  setupWordPress();
  window.eval(bridgeScript);
};

const rerenderClassicEditor = (options: {
  includeEditor?: boolean;
  includeTitle?: boolean;
  postStatus?: string;
}) => {
  vi.clearAllTimers();
  document.body.className = '';
  document.body.innerHTML = '';
  renderClassicEditor(options);
};

const rerenderBlockEditor = (supportsTitle: boolean) => {
  window.dispatchEvent(new Event('unload'));
  vi.clearAllTimers();
  document.body.className = '';
  document.body.innerHTML = '';
  renderBlockEditor(supportsTitle);
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
    savePost.mockReset();
    editorDirty = false;
    saveSucceeded = false;
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
    expect(frame.referrerPolicy).toBe('strict-origin-when-cross-origin');
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts');
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

  it('omits the title input when the post type does not support titles', () => {
    rerenderBlockEditor(false);

    expect(document.querySelector('.kayzart-editor-preview__titleInput')).toBeNull();
    expect(document.querySelector('.kayzart-editor-preview__titleLabel')).toBeNull();
    expect(editPost).not.toHaveBeenCalled();
  });

  it('waits for a dirty post to save and prevents duplicate saves', async () => {
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    let finishSave: (() => void) | undefined;
    editorDirty = true;
    savePost.mockReturnValue(new Promise<void>((resolve) => {
      finishSave = resolve;
    }));
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;

    edit.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    edit.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));

    expect(savePost).toHaveBeenCalledTimes(1);
    expect(edit.getAttribute('aria-disabled')).toBe('true');
    expect(edit.textContent).toBe('Saving...');

    editorDirty = false;
    saveSucceeded = true;
    finishSave?.();
    await Promise.resolve();

    expect(edit.classList.contains('is-busy')).toBe(true);
    consoleError.mockRestore();
  });

  it('restores the edit action when saving fails', async () => {
    editorDirty = true;
    savePost.mockRejectedValue(new Error('save failed'));
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;

    edit.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    await Promise.resolve();

    expect(edit.getAttribute('aria-disabled')).toBeNull();
    expect(edit.textContent).toBe('Edit with Kayzart');
  });

  it('stays in Gutenberg when the post remains dirty after saving', async () => {
    editorDirty = true;
    saveSucceeded = true;
    savePost.mockResolvedValue(undefined);
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;

    edit.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    await Promise.resolve();

    expect(edit.classList.contains('is-busy')).toBe(false);
    expect(edit.textContent).toBe('Edit with Kayzart');
  });

  it('does not issue a REST save when there are no pending changes', () => {
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });

    edit.addEventListener('click', (clickEvent) => clickEvent.preventDefault());
    edit.dispatchEvent(event);

    expect(savePost).not.toHaveBeenCalled();
  });

  it('stays in Gutenberg when the editor save API is unavailable', () => {
    editorDirty = true;
    (window as any).wp.data.dispatch = () => ({ editPost });
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;
    const event = new MouseEvent('click', { bubbles: true, cancelable: true });

    expect(edit.dispatchEvent(event)).toBe(false);
    expect(edit.classList.contains('is-busy')).toBe(false);
  });
});

describe('Classic Editor bridge', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    editPost.mockReset();
    savePost.mockReset();
    editorDirty = false;
    saveSucceeded = false;
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
    expect(frame.referrerPolicy).toBe('strict-origin-when-cross-origin');
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts');
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

  it('mounts the preview after the title when the post type has no editor support', () => {
    rerenderClassicEditor({ includeEditor: false });

    const title = document.querySelector<HTMLElement>('#titlediv')!;
    const panel = document.querySelector<HTMLElement>('.kayzart-editor-preview--classic')!;

    expect(document.querySelector('#postdivrich')).toBeNull();
    expect(title.nextElementSibling).toBe(panel);
    expect(document.querySelectorAll('.kayzart-editor-preview__edit')).toHaveLength(1);
  });

  it('mounts the preview at the start of post content without editor or title support', () => {
    rerenderClassicEditor({ includeEditor: false, includeTitle: false });

    const content = document.querySelector<HTMLElement>('#post-body-content')!;
    const panel = document.querySelector<HTMLElement>('.kayzart-editor-preview--classic')!;

    expect(content.firstElementChild).toBe(panel);
    expect(document.querySelectorAll('.kayzart-editor-preview__edit')).toHaveLength(1);
  });

  it.each([
    ['draft', 'save-post'],
    ['pending', 'save-post'],
    ['publish', 'publish'],
    ['private', 'publish'],
    ['future', 'publish'],
  ])('submits %s posts with the standard %s control', (postStatus, expectedButton) => {
    rerenderClassicEditor({ postStatus });
    const form = document.querySelector<HTMLFormElement>('form#post')!;
    const expected = document.getElementById(expectedButton)!;
    const unexpected = document.getElementById(expectedButton === 'publish' ? 'save-post' : 'publish')!;
    const expectedClick = vi.fn((event: Event) => event.preventDefault());
    const unexpectedClick = vi.fn((event: Event) => event.preventDefault());
    expected.addEventListener('click', expectedClick);
    unexpected.addEventListener('click', unexpectedClick);

    document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!.click();

    expect(expectedClick).toHaveBeenCalledTimes(1);
    expect(unexpectedClick).not.toHaveBeenCalled();
    expect(form.querySelector<HTMLInputElement>('[name="kayzart_open_after_save"]')?.value).toBe('1');
  });

  it('clears the redirect flag and busy state when form submission is rejected', () => {
    const form = document.querySelector<HTMLFormElement>('form#post')!;
    form.addEventListener('submit', (event) => event.preventDefault());
    const edit = document.querySelector<HTMLAnchorElement>('.kayzart-editor-preview__edit')!;

    edit.click();
    expect(edit.getAttribute('aria-disabled')).toBe('true');
    expect(form.querySelector('[name="kayzart_open_after_save"]')).not.toBeNull();

    vi.runOnlyPendingTimers();

    expect(edit.getAttribute('aria-disabled')).toBeNull();
    expect(edit.textContent).toBe('Edit with Kayzart');
    expect(form.querySelector('[name="kayzart_open_after_save"]')).toBeNull();
  });
});
