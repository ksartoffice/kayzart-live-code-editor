import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const newPageScript = readFileSync('assets/admin/new-page.js', 'utf8');

const renderForm = (maxPromptChars = 8000) => {
  document.body.innerHTML = [
    '<form class="kayzart-create-form">',
    '<input id="kayzart-create-title" value="Salon launch" />',
    '<textarea id="kayzart-initial-ai-prompt"></textarea>',
    '<p id="kayzart-initial-ai-prompt-count"></p>',
    '<span id="kayzart-create-blank-hint" hidden>Clear the AI instruction to start with a blank page.</span>',
    '<button id="kayzart-create-blank" type="submit" name="start_mode" value="blank" data-loading-label="Creating…">Start with a blank page</button>',
    '<button id="kayzart-generate-ai" type="submit" name="start_mode" value="ai" data-loading-label="Creating…" disabled>Generate with AI</button>',
    '</form>',
  ].join('');

  (window as any).wp = {
    domReady: (callback: () => void) => callback(),
  };
  (window as any).KAYZART_NEW_PAGE = {
    maxPromptChars,
    charsLabel: 'characters',
  };
  window.eval(newPageScript);
};

describe('new page form', () => {
  beforeEach(() => {
    renderForm();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('counts characters rather than bytes, so the limit does not depend on the language', () => {
    renderForm(5);
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const counter = document.querySelector<HTMLElement>('#kayzart-initial-ai-prompt-count')!;
    const submit = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;

    // Six UTF-8 bytes, but only two characters, so this stays within a five-character limit.
    prompt.value = 'あい';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(counter.textContent).toBe('2 / 5 characters');
    expect(counter.classList.contains('is-error')).toBe(false);
    expect(prompt.getAttribute('aria-invalid')).toBe('false');
    expect(submit.disabled).toBe(false);
  });

  it('counts a surrogate pair as one character, matching mb_strlen on the server', () => {
    renderForm(5);
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const counter = document.querySelector<HTMLElement>('#kayzart-initial-ai-prompt-count')!;

    prompt.value = '🎨🎨';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(counter.textContent).toBe('2 / 5 characters');
  });

  it('blocks a prompt over the character limit', () => {
    renderForm(5);
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const counter = document.querySelector<HTMLElement>('#kayzart-initial-ai-prompt-count')!;
    const submit = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;

    prompt.value = 'あいうえおか';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(counter.textContent).toBe('6 / 5 characters');
    expect(counter.classList.contains('is-error')).toBe(true);
    expect(prompt.getAttribute('aria-invalid')).toBe('true');
    expect(submit.disabled).toBe(true);
  });

  it('resizes the instruction field to match its content', () => {
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    let scrollHeight = 220;
    Object.defineProperty(prompt, 'scrollHeight', {
      configurable: true,
      get: () => scrollHeight,
    });

    prompt.value = 'A longer instruction.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(prompt.style.height).toBe('222px');
    expect(prompt.style.overflowY).toBe('hidden');

    scrollHeight = 180;
    prompt.value = 'Shorter.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(prompt.style.height).toBe('182px');
  });

  it('shows a scrollbar only after the instruction field reaches its maximum height', () => {
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    Object.defineProperty(prompt, 'scrollHeight', {
      configurable: true,
      value: 300,
    });
    vi.spyOn(window, 'getComputedStyle').mockReturnValue({
      borderTopWidth: '1px',
      borderBottomWidth: '1px',
      maxHeight: '240px',
    } as CSSStyleDeclaration);

    prompt.value = 'A very long instruction.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(prompt.style.height).toBe('240px');
    expect(prompt.style.overflowY).toBe('auto');
  });

  it('locks the form and changes the button label after a valid submit', () => {
    const form = document.querySelector<HTMLFormElement>('.kayzart-create-form')!;
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const submit = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;

    prompt.value = 'hello';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    const event = new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: submit });
    form.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
    expect(form.classList.contains('is-submitting')).toBe(true);
    expect(form.getAttribute('aria-busy')).toBe('true');
    expect(submit.disabled).toBe(true);
    expect(submit.textContent).toBe('Creating…');
  });

  it('disables blank-page creation while an AI instruction is present', () => {
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const blank = document.querySelector<HTMLButtonElement>('#kayzart-create-blank')!;
    const generate = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;
    const blankHint = document.querySelector<HTMLElement>('#kayzart-create-blank-hint')!;
    const form = document.querySelector<HTMLFormElement>('.kayzart-create-form')!;

    prompt.value = 'Ignore this prompt.';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(generate.disabled).toBe(false);
    expect(blank.disabled).toBe(true);
    expect(blankHint.hidden).toBe(false);
    const event = new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: blank });
    form.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(true);
    expect(prompt.disabled).toBe(false);
    expect(form.querySelector<HTMLInputElement>('input[type="hidden"][name="start_mode"]')).toBeNull();
    expect(form.classList.contains('is-submitting')).toBe(false);

    prompt.value = '';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));
    expect(blank.disabled).toBe(false);
    expect(blankHint.hidden).toBe(true);

    const blankEvent = new SubmitEvent('submit', { bubbles: true, cancelable: true, submitter: blank });
    form.dispatchEvent(blankEvent);
    expect(blankEvent.defaultPrevented).toBe(false);
    expect(prompt.disabled).toBe(true);
    expect(form.querySelector<HTMLInputElement>('input[type="hidden"][name="start_mode"]')?.value).toBe('blank');
    expect(form.classList.contains('is-submitting')).toBe(true);
  });
});
