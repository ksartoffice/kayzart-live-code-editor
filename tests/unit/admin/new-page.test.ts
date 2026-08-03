import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const newPageScript = readFileSync('assets/admin/new-page.js', 'utf8');

const renderForm = (maxPromptBytes = 8192) => {
  document.body.innerHTML = [
    '<form class="kayzart-create-form">',
    '<input id="kayzart-create-title" value="Salon launch" />',
    '<textarea id="kayzart-initial-ai-prompt"></textarea>',
    '<button id="kayzart-ai-improve" type="button" disabled>',
    '<span class="kayzart-ai-improve__label">Improve with AI</span>',
    '</button>',
    '<p id="kayzart-ai-improve-status"></p>',
    '<button id="kayzart-ai-improve-undo" type="button" hidden>Undo improvement</button>',
    '<p id="kayzart-initial-ai-prompt-count"></p>',
    '<input id="submit" type="submit" value="Create" data-loading-label="Creating…" />',
    '</form>',
  ].join('');

  (window as any).wp = {
    domReady: (callback: () => void) => callback(),
  };
  (window as any).KAYZART_NEW_PAGE = {
    maxPromptBytes,
    bytesLabel: 'bytes',
    improveUrl: '/wp-json/kayzart/v1/ai/prompts/improve',
    restNonce: 'rest-nonce',
    improveLabel: 'Improve with AI',
    improvingLabel: 'Improving…',
    improvedMessage: 'Improved.',
    restoredMessage: 'Restored.',
    staleMessage: 'Content changed. Improvement canceled.',
    errorMessage: 'Could not improve.',
  };
  window.eval(newPageScript);
};

const flushPromises = async () => {
  await new Promise((resolve) => setTimeout(resolve, 0));
};

describe('new page form', () => {
  beforeEach(() => {
    renderForm();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('counts UTF-8 bytes and blocks a prompt over the byte limit', () => {
    renderForm(5);
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const counter = document.querySelector<HTMLElement>('#kayzart-initial-ai-prompt-count')!;
    const submit = document.querySelector<HTMLInputElement>('#submit')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;

    prompt.value = 'あい';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(counter.textContent).toBe('6 / 5 bytes');
    expect(counter.classList.contains('is-error')).toBe(true);
    expect(prompt.getAttribute('aria-invalid')).toBe('true');
    expect(submit.disabled).toBe(true);
    expect(improve.disabled).toBe(true);
  });

  it('locks the form and changes the button label after a valid submit', () => {
    const form = document.querySelector<HTMLFormElement>('.kayzart-create-form')!;
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const submit = document.querySelector<HTMLInputElement>('#submit')!;

    prompt.value = 'hello';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    const event = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
    expect(form.classList.contains('is-submitting')).toBe(true);
    expect(form.getAttribute('aria-busy')).toBe('true');
    expect(submit.disabled).toBe(true);
    expect(submit.value).toBe('Creating…');
  });

  it('sends the instruction and title, replaces the text, and restores it with undo', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ improvedPrompt: 'A clearer landing-page brief.' }),
    });
    vi.stubGlobal('fetch', fetchMock);
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;
    const label = improve.querySelector<HTMLElement>('.kayzart-ai-improve__label')!;
    const undo = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve-undo')!;

    prompt.value = 'Build a salon LP.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(improve.disabled).toBe(false);
    improve.click();

    expect(improve.disabled).toBe(true);
    expect(label.textContent).toBe('Improving…');
    await flushPromises();

    expect(fetchMock).toHaveBeenCalledWith(
      '/wp-json/kayzart/v1/ai/prompts/improve',
      expect.objectContaining({
        method: 'POST',
        headers: expect.objectContaining({ 'X-WP-Nonce': 'rest-nonce' }),
        body: JSON.stringify({ prompt: 'Build a salon LP.', title: 'Salon launch' }),
      })
    );
    expect(prompt.value).toBe('A clearer landing-page brief.');
    expect(undo.hidden).toBe(false);

    undo.click();
    expect(prompt.value).toBe('Build a salon LP.');
    expect(undo.hidden).toBe(true);
  });

  it('removes undo after the improved instruction is manually edited', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ improvedPrompt: 'Improved brief.' }),
    }));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;
    const undo = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve-undo')!;

    prompt.value = 'Original.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    await flushPromises();
    expect(undo.hidden).toBe(false);

    prompt.value += ' Manual change.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(undo.hidden).toBe(true);
  });

  it('cancels the request and keeps the edit when the instruction changes', async () => {
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn().mockImplementation((_url, init?: RequestInit) => {
      requestSignal = init?.signal || null;
      return new Promise((_resolve, reject) => {
        requestSignal?.addEventListener('abort', () => {
          reject(new DOMException('Aborted', 'AbortError'));
        }, { once: true });
      });
    }));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;
    const status = document.querySelector<HTMLElement>('#kayzart-ai-improve-status')!;

    prompt.value = 'Original.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    prompt.value = 'Edited while waiting.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();

    expect(prompt.value).toBe('Edited while waiting.');
    expect(requestSignal?.aborted).toBe(true);
    expect(improve.disabled).toBe(false);
    expect(status.textContent).toBe('Content changed. Improvement canceled.');
    expect(status.classList.contains('is-info')).toBe(true);
  });

  it('cancels the request when the title changes', async () => {
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn().mockImplementation((_url, init?: RequestInit) => {
      requestSignal = init?.signal || null;
      return new Promise((_resolve, reject) => {
        requestSignal?.addEventListener('abort', () => {
          reject(new DOMException('Aborted', 'AbortError'));
        }, { once: true });
      });
    }));
    const title = document.querySelector<HTMLInputElement>('#kayzart-create-title')!;
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;
    const status = document.querySelector<HTMLElement>('#kayzart-ai-improve-status')!;

    prompt.value = 'Original.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    title.value = 'Clinic launch';
    title.dispatchEvent(new Event('input', { bubbles: true }));
    await flushPromises();

    expect(title.value).toBe('Clinic launch');
    expect(prompt.value).toBe('Original.');
    expect(requestSignal?.aborted).toBe(true);
    expect(improve.disabled).toBe(false);
    expect(status.textContent).toBe('Content changed. Improvement canceled.');
  });

  it('allows an immediate retry and ignores a late response from the canceled request', async () => {
    const pending: Array<(value: unknown) => void> = [];
    vi.stubGlobal('fetch', vi.fn().mockImplementation(() => new Promise((resolve) => {
      pending.push(resolve);
    })));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;

    prompt.value = 'First instruction.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    prompt.value = 'Second instruction.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(improve.disabled).toBe(false);

    improve.click();
    expect(improve.disabled).toBe(true);
    pending[0]({
      ok: true,
      json: async () => ({ improvedPrompt: 'Late stale result.' }),
    });
    await flushPromises();

    expect(prompt.value).toBe('Second instruction.');
    expect(improve.disabled).toBe(true);

    pending[1]({
      ok: true,
      json: async () => ({ improvedPrompt: 'Current result.' }),
    });
    await flushPromises();

    expect(prompt.value).toBe('Current result.');
    expect(improve.disabled).toBe(false);
  });

  it('does not cancel when only surrounding input whitespace changes', async () => {
    let resolveFetch: (value: unknown) => void = () => undefined;
    let requestSignal: AbortSignal | null = null;
    vi.stubGlobal('fetch', vi.fn().mockImplementation((_url, init?: RequestInit) => {
      requestSignal = init?.signal || null;
      return new Promise((resolve) => {
        resolveFetch = resolve;
      });
    }));
    const title = document.querySelector<HTMLInputElement>('#kayzart-create-title')!;
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;

    prompt.value = 'Original.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    prompt.value = '  Original.  ';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    title.value = '  Salon launch  ';
    title.dispatchEvent(new Event('input', { bubbles: true }));

    expect(requestSignal?.aborted).toBe(false);
    expect(improve.disabled).toBe(true);

    resolveFetch({
      ok: true,
      json: async () => ({ improvedPrompt: 'Improved result.' }),
    });
    await flushPromises();

    expect(prompt.value).toBe('Improved result.');
    expect(improve.disabled).toBe(false);
  });

  it('keeps the original text and displays the REST error on failure', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ message: 'Too many requests.' }),
    }));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const improve = document.querySelector<HTMLButtonElement>('#kayzart-ai-improve')!;
    const status = document.querySelector<HTMLElement>('#kayzart-ai-improve-status')!;

    prompt.value = 'Keep this text.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    improve.click();
    await flushPromises();

    expect(prompt.value).toBe('Keep this text.');
    expect(status.textContent).toBe('Too many requests.');
    expect(status.classList.contains('is-error')).toBe(true);
    expect(improve.disabled).toBe(false);
  });
});
