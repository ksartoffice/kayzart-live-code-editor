import { afterEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const script = readFileSync('assets/admin/template-library.js', 'utf8');

function render(defaultMode = 'template') {
  document.body.innerHTML = `
    <div class="kayzart-start-methods" data-default-start="${defaultMode}">
      <button data-kayzart-start="template">Template</button><button data-kayzart-start="ai">AI</button><button data-kayzart-start="blank">Blank</button>
    </div>
    <section id="kayzart-template-panel"><p id="kayzart-template-kept-input" hidden>AI input is kept</p>
      <form class="kayzart-template-form"><input name="start_mode" value="template"><input name="request_id" value="request"><input name="post_title" value="Template title"><button type="submit" data-loading-label="Creating">Create</button></form>
      <button data-template-preview="/preview">Preview</button>
    </section>
    <form class="kayzart-create-form"><div id="kayzart-ai-setup-card"></div><a id="kayzart-ai-open-settings"></a>
      <div class="kayzart-create-field"><textarea id="kayzart-initial-ai-prompt">Existing AI instruction</textarea></div>
      <button id="kayzart-generate-ai">Generate</button><button id="kayzart-create-blank">Blank</button>
    </form>
    <dialog id="kayzart-template-preview"><button data-template-close>Close</button><button data-template-width="desktop">Desktop</button><button data-template-width="mobile">Mobile</button><p data-template-preview-status data-loading="Loading" data-error="Retry"></p><iframe sandbox></iframe></dialog>`;
  (window as any).KAYZART_NEW_PAGE = { ai: { available: false, canSetUp: true } };
  const dialog = document.querySelector('dialog')!;
  dialog.showModal = () => { dialog.open = true; };
  dialog.close = () => { dialog.open = false; dialog.dispatchEvent(new Event('close')); };
  window.eval(script);
}

afterEach(() => { vi.restoreAllMocks(); vi.unstubAllGlobals(); });

describe('bundled template UI', () => {
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
});
