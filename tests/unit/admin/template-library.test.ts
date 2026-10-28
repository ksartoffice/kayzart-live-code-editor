import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const script = readFileSync('assets/admin/template-library.js', 'utf8');

const variants = {
  ja: { thumbnail: '/thumbnail.webp', preview: '/preview?template_locale=ja' },
  en: { thumbnail: '/thumbnail.en.webp', preview: '/preview?template_locale=en' },
};

function render(defaultMode = 'template', cardVariants: Array<Record<string, typeof variants.ja>> = [variants], defaultLocale = 'ja') {
  document.body.innerHTML = `
    <div class="kayzart-start-methods" data-default-start="${defaultMode}">
      <button data-kayzart-start="template">Template</button><button data-kayzart-start="ai">AI</button><button data-kayzart-start="blank">Blank</button>
    </div>
    <section id="kayzart-template-panel"><p id="kayzart-template-kept-input" hidden>AI input is kept</p>
      <label for="language-filter">Template language</label><select id="language-filter" data-template-language-filter>${Array.from(new Set(['ja', 'en', ...cardVariants.flatMap(card => Object.keys(card))])).map(locale => `<option value="${locale}" ${locale === defaultLocale ? 'selected' : ''}>${locale}</option>`).join('')}</select>
      ${cardVariants.map((locales, index) => `<article class="kayzart-template-card" data-template-variants='${JSON.stringify(locales)}'><div class="kayzart-template-art"><img src="/thumbnail.webp"></div>
      <form id="template-form-${index}" class="kayzart-template-form"><input type="hidden" name="template_locale" value="ja"><input name="start_mode" value="template"><input name="request_id" value="request"><input name="post_title" value="Template title"><button type="submit" data-loading-label="Creating">Create</button></form>
      <button data-template-preview="/preview?template_locale=ja">Preview</button></article>`).join('')}
      <p data-template-empty hidden>No templates yet</p>
    </section>
    <form class="kayzart-create-form"><div id="kayzart-ai-setup-card"></div><a id="kayzart-ai-open-settings"></a>
      <div class="kayzart-create-field"><textarea id="kayzart-initial-ai-prompt">Existing AI instruction</textarea></div>
      <button id="kayzart-generate-ai">Generate</button><button id="kayzart-create-blank">Blank</button>
    </form>
    <dialog id="kayzart-template-preview"><button data-template-close>Close</button><button data-template-width="desktop" aria-pressed="true">Desktop</button><button data-template-width="mobile" aria-pressed="false">Mobile</button><p data-template-preview-status data-loading="Loading" data-error="Retry"></p><iframe sandbox></iframe></dialog>`;
  (window as any).KAYZART_NEW_PAGE = { ai: { available: false, canSetUp: true } };
  const dialog = document.querySelector('dialog')!;
  dialog.showModal = () => { dialog.open = true; };
  dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new Event('close')); };
  window.eval(script);
}

function chooseLanguage(locale: string) {
  const select = document.querySelector<HTMLSelectElement>('[data-template-language-filter]')!;
  select.value = locale;
  select.dispatchEvent(new Event('change'));
}

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('bundled template UI', () => {
  it('changes the thumbnail, preview URL, and submitted language together without losing inputs', () => {
    render();
    chooseLanguage('en');
    expect(document.querySelector<HTMLSelectElement>('[data-template-language-filter]')!.value).toBe('en');
    expect(document.querySelector('.kayzart-template-card select')).toBeNull();
    expect(document.querySelector('.kayzart-template-art img')!.getAttribute('src')).toBe('/thumbnail.en.webp');
    expect(document.querySelector('[data-template-preview]')!.getAttribute('data-template-preview')).toBe('/preview?template_locale=en');
    const fields = new FormData(document.querySelector<HTMLFormElement>('.kayzart-template-form')!);
    expect(fields.get('template_locale')).toBe('en');
    expect(fields.get('request_id')).toBe('request');
    expect(fields.get('post_title')).toBe('Template title');
    expect(document.querySelector<HTMLTextAreaElement>('textarea')!.value).toBe('Existing AI instruction');
  });

  it('filters the whole list without requiring a translation for each template', () => {
    render('template', [{ ja: variants.ja }, { en: variants.en }, variants]);
    const cards = document.querySelectorAll<HTMLElement>('.kayzart-template-card');
    expect(Array.from(cards, card => card.hidden)).toEqual([false, true, false]);
    chooseLanguage('en');
    expect(Array.from(cards, card => card.hidden)).toEqual([true, false, false]);
    expect(new FormData(cards[1].querySelector('form')!).get('template_locale')).toBe('en');
    expect(new FormData(cards[2].querySelector('form')!).get('template_locale')).toBe('en');
    chooseLanguage('ja');
    expect(Array.from(cards, card => card.hidden)).toEqual([false, true, false]);
  });

  it('keeps the typed page title when switching to another language card', () => {
    render('template', [{ ja: variants.ja }, { en: variants.en }]);
    const [japanese, english] = Array.from(document.querySelectorAll<HTMLInputElement>('[name="post_title"]'));
    japanese.value = 'Spring campaign';
    japanese.dispatchEvent(new Event('input'));
    chooseLanguage('en');
    expect(english.closest<HTMLElement>('.kayzart-template-card')!.hidden).toBe(false);
    expect(english.value).toBe('Spring campaign');
  });

  it('uses the server-selected locale and shows an empty state when no template matches', () => {
    render('template', [{ ja: variants.ja }], 'en');
    expect(document.querySelector<HTMLElement>('.kayzart-template-card')!.hidden).toBe(true);
    expect(document.querySelector<HTMLElement>('[data-template-empty]')!.hidden).toBe(false);
    expect(document.querySelector<HTMLSelectElement>('[data-template-language-filter]')!.value).toBe('en');
    chooseLanguage('ja');
    expect(document.querySelector<HTMLElement>('.kayzart-template-card')!.hidden).toBe(false);
    expect(document.querySelector<HTMLElement>('[data-template-empty]')!.hidden).toBe(true);
  });

  it('supports another language in the same dropdown without adding controls to cards', () => {
    const french = { thumbnail: '/thumbnail.fr.webp', preview: '/preview?template_locale=fr' };
    render('template', [{ ja: variants.ja }, { fr: french }], 'fr');
    const cards = document.querySelectorAll<HTMLElement>('.kayzart-template-card');
    expect(Array.from(cards, card => card.hidden)).toEqual([true, false]);
    expect(new FormData(cards[1].querySelector('form')!).get('template_locale')).toBe('fr');
    expect(cards[1].querySelector('img')!.getAttribute('src')).toBe(french.thumbnail);
    chooseLanguage('ja');
    expect(Array.from(cards, card => card.hidden)).toEqual([false, true]);
  });

  it('resynchronizes the list when the browser restores the dropdown on pageshow', () => {
    render();
    document.querySelector<HTMLSelectElement>('[data-template-language-filter]')!.value = 'en';
    window.dispatchEvent(new Event('pageshow'));
    expect(new FormData(document.querySelector<HTMLFormElement>('.kayzart-template-form')!).get('template_locale')).toBe('en');
    expect(document.querySelector('img')!.getAttribute('src')).toBe(variants.en.thumbnail);
  });

  it('keeps cached Japanese and English preview documents separate', async () => {
    render();
    const fetcher = vi.fn().mockImplementation(async (url: string) => ({ ok: true, json: async () => ({ success: true, data: { document: url.endsWith('=en') ? '<p>English preview</p>' : '<p>日本語プレビュー</p>' } }) }));
    vi.stubGlobal('fetch', fetcher);
    const preview = document.querySelector<HTMLButtonElement>('[data-template-preview]')!;
    const close = document.querySelector<HTMLButtonElement>('[data-template-close]')!;
    preview.click();
    await vi.waitFor(() => expect(document.querySelector('iframe')!.srcdoc).toContain('日本語プレビュー'));
    close.click();
    chooseLanguage('en');
    preview.click();
    await vi.waitFor(() => expect(document.querySelector('iframe')!.srcdoc).toContain('English preview'));
    close.click();
    chooseLanguage('ja');
    preview.click();
    expect(document.querySelector('iframe')!.srcdoc).toContain('日本語プレビュー');
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('keeps the AI instruction outside the template form when changing paths', () => {
    render();
    const form = document.querySelector<HTMLFormElement>('.kayzart-template-form')!;
    expect(new FormData(form).get('start_mode')).toBe('template');
    expect(new FormData(form).has('initial_ai_prompt')).toBe(false);
    expect(document.querySelector<HTMLElement>('.kayzart-create-form')!.hidden).toBe(true);
    document.querySelector<HTMLButtonElement>('[data-kayzart-start="ai"]')!.click();
    expect(document.querySelector<HTMLTextAreaElement>('textarea')!.value).toBe('Existing AI instruction');
    expect(document.querySelector<HTMLElement>('#kayzart-ai-setup-card')!.hidden).toBe(false);
    document.querySelector<HTMLButtonElement>('[data-kayzart-start="template"]')!.click();
    expect(document.querySelector<HTMLElement>('#kayzart-template-kept-input')!.hidden).toBe(false);
  });

  it('blocks a repeated template submit without changing the request or input', () => {
    render();
    const form = document.querySelector<HTMLFormElement>('.kayzart-template-form')!;
    const first = new Event('submit', { cancelable: true });
    form.dispatchEvent(first);
    expect(first.defaultPrevented).toBe(false);
    expect(form.querySelector('button')!.disabled).toBe(true);
    const repeated = new Event('submit', { cancelable: true });
    form.dispatchEvent(repeated);
    expect(repeated.defaultPrevented).toBe(true);
    expect(new FormData(form).get('request_id')).toBe('request');
    expect(new FormData(form).get('post_title')).toBe('Template title');
  });

  it('fetches the preview only on demand and switches widths without refetching', async () => {
    render();
    const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: { document: '<!doctype html><p>Local preview</p>' } }) });
    vi.stubGlobal('fetch', fetcher);
    expect(fetcher).not.toHaveBeenCalled();
    document.querySelector<HTMLButtonElement>('[data-template-preview]')!.click();
    await vi.waitFor(() => expect(document.querySelector('iframe')!.srcdoc).toContain('Local preview'));
    expect(fetcher).toHaveBeenCalledTimes(1);
    expect(document.querySelector('iframe')!.getAttribute('sandbox')).toBe('');
    document.querySelector<HTMLButtonElement>('[data-template-width="mobile"]')!.click();
    expect(document.querySelector('iframe')!.style.width).toBe('390px');
    expect(fetcher).toHaveBeenCalledTimes(1);
    document.querySelector<HTMLButtonElement>('[data-template-close]')!.click();
    expect(document.querySelector('dialog')!.open).toBe(false);
  });

  it('resets the cached preview to desktop when reopened after mobile', async () => {
    render();
    const fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ success: true, data: { document: '<!doctype html><p>Local preview</p>' } }) });
    vi.stubGlobal('fetch', fetcher);
    const preview = document.querySelector<HTMLButtonElement>('[data-template-preview]')!;
    const desktop = document.querySelector<HTMLButtonElement>('[data-template-width="desktop"]')!;
    const mobile = document.querySelector<HTMLButtonElement>('[data-template-width="mobile"]')!;
    const frame = document.querySelector('iframe')!;

    preview.click();
    await vi.waitFor(() => expect(frame.srcdoc).toContain('Local preview'));
    mobile.click();
    expect(frame.style.width).toBe('390px');
    expect(mobile.getAttribute('aria-pressed')).toBe('true');
    document.querySelector<HTMLButtonElement>('[data-template-close]')!.click();

    preview.click();

    expect(document.querySelector('dialog')!.open).toBe(true);
    expect(frame.style.width).toBe('100%');
    expect(desktop.getAttribute('aria-pressed')).toBe('true');
    expect(mobile.getAttribute('aria-pressed')).toBe('false');
    expect(frame.srcdoc).toContain('Local preview');
    expect(fetcher).toHaveBeenCalledTimes(1);
    mobile.click();
    expect(frame.style.width).toBe('390px');
    expect(mobile.getAttribute('aria-pressed')).toBe('true');
    desktop.click();
    expect(frame.style.width).toBe('100%');
    expect(desktop.getAttribute('aria-pressed')).toBe('true');
    expect(mobile.getAttribute('aria-pressed')).toBe('false');
  });
});
