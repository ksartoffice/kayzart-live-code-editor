import { beforeEach, describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';

const newPageScript = readFileSync('assets/admin/new-page.js', 'utf8');

const renderForm = () => {
  document.body.innerHTML = [
    '<form class="kayzart-create-form">',
    '<textarea id="kayzart-initial-ai-prompt"></textarea>',
    '<p id="kayzart-initial-ai-prompt-count"></p>',
    '<input id="submit" type="submit" value="Create" data-loading-label="Creating…" />',
    '</form>',
  ].join('');

  (window as any).wp = {
    domReady: (callback: () => void) => callback(),
  };
  (window as any).KAYZART_NEW_PAGE = {
    maxPromptBytes: 5,
    bytesLabel: 'bytes',
  };
  window.eval(newPageScript);
};

describe('new page form', () => {
  beforeEach(() => {
    renderForm();
  });

  it('counts UTF-8 bytes and blocks a prompt over the byte limit', () => {
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const counter = document.querySelector<HTMLElement>('#kayzart-initial-ai-prompt-count')!;
    const submit = document.querySelector<HTMLInputElement>('#submit')!;

    prompt.value = 'あい';
    prompt.dispatchEvent(new Event('input', { bubbles: true }));

    expect(counter.textContent).toBe('6 / 5 bytes');
    expect(counter.classList.contains('is-error')).toBe(true);
    expect(prompt.getAttribute('aria-invalid')).toBe('true');
    expect(submit.disabled).toBe(true);
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
});
