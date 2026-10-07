import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';

const newPageScript = readFileSync('assets/admin/new-page.js', 'utf8');

const renderForm = (maxPromptChars = 8000, needsSetup = false, systemUnavailable = false) => {
  document.body.innerHTML = [
    '<form class="kayzart-create-form">',
    `<div id="kayzart-ai-setup-card"><div id="kayzart-ai-setup-guide"${systemUnavailable ? ' hidden' : ''}><details class="kayzart-ai-connection-guide"><summary>Initial guide</summary><ol><li>Initial step</li></ol></details></div><div id="kayzart-ai-return" hidden></div><p id="kayzart-ai-unavailable-reason"></p><button id="kayzart-ai-recheck" type="button">Check settings again</button></div>`,
    '<p id="kayzart-ai-check-result" role="status"></p>',
    `<a id="kayzart-ai-open-settings" href="/settings" target="_blank" rel="noopener noreferrer"${systemUnavailable ? ' hidden' : ''}>Set up</a>`,
    '<input name="post_type" value="page"><input name="mode" value="normal">',
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
    ai: { available: !needsSetup && !systemUnavailable, canSetUp: needsSetup && !systemUnavailable, availabilityUrl: '/availability' },
    newTabLabel: '(opens in a new tab)',
    restNonce: 'nonce', checkingLabel: 'Checking settings', readyLabel: 'Available', notReadyLabel: 'Not configured', checkError: 'Retry; input preserved',
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
  it('opens setup without submitting and enables generation only after a manual recheck', async () => {
    renderForm(5, true);
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(JSON.stringify({ ok: true, ai: { available: true, maxPromptChars: 5 } })));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const generate = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;
    const link = document.querySelector<HTMLAnchorElement>('#kayzart-ai-open-settings')!;
    prompt.value = 'hello'; prompt.dispatchEvent(new Event('input'));
    expect(generate.disabled).toBe(true);
    link.dispatchEvent(new MouseEvent('click', { cancelable: true }));
    expect(document.querySelector<HTMLElement>('#kayzart-ai-return')!.hidden).toBe(false);
    window.dispatchEvent(new Event('focus'));
    expect(fetchMock).not.toHaveBeenCalled();
    expect(document.querySelector('.is-submitting')).toBeNull();
    document.querySelector<HTMLButtonElement>('#kayzart-ai-recheck')!.click();
    await vi.waitFor(() => expect(generate.disabled).toBe(false));
    expect(prompt.value).toBe('hello');
    expect(document.querySelector<HTMLInputElement>('#kayzart-create-title')!.value).toBe('Salon launch');
    expect(document.querySelector<HTMLInputElement>('[name="post_type"]')!.value).toBe('page');
    expect(document.querySelector<HTMLInputElement>('[name="mode"]')!.value).toBe('normal');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][1]).toMatchObject({ method: 'GET', headers: { 'X-WP-Nonce': 'nonce' } });
    prompt.value = 'too long'; prompt.dispatchEvent(new Event('input'));
    expect(generate.disabled).toBe(true);
    expect(document.querySelector('.is-submitting')).toBeNull();
  });

  it('restores setup guidance after environment requirements are fixed without losing input or starting generation', async () => {
    renderForm(8000, true, true);
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, ai: {
        available: false, canSetUp: true, availabilityUrl: '/availability', setupUrl: '/connectors',
        setupGuide: { summary: 'Connect an AI service', steps: ['Choose a service', 'Save its settings'], links: [{ label: 'Documentation', url: 'https://example.test/docs' }] },
      } })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, ai: { available: true, maxPromptChars: 8000 } })));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const link = document.querySelector<HTMLAnchorElement>('#kayzart-ai-open-settings')!;
    const guide = document.querySelector<HTMLElement>('#kayzart-ai-setup-guide')!;
    const generate = document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!;
    const check = document.querySelector<HTMLButtonElement>('#kayzart-ai-recheck')!;
    prompt.value = 'A cafe page with opening hours'; prompt.dispatchEvent(new Event('input'));
    expect(link.hidden).toBe(true);
    expect(guide.hidden).toBe(true);
    check.click();
    await vi.waitFor(() => expect(guide.hidden).toBe(false));
    expect(link.hidden).toBe(false);
    expect(link.getAttribute('href')).toBe('/connectors');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
    expect(guide.querySelector('summary')!.textContent).toBe('Connect an AI service');
    expect(Array.from(guide.querySelectorAll('li'), (item) => item.textContent)).toEqual(['Choose a service', 'Save its settings']);
    expect(guide.querySelector('a')!.textContent).toBe('Documentation (opens in a new tab)');
    expect(guide.querySelector('a')!.target).toBe('_blank');
    expect(guide.querySelector('a')!.rel).toBe('noopener noreferrer');
    expect(generate.hidden).toBe(true);
    expect(generate.disabled).toBe(true);
    link.dispatchEvent(new MouseEvent('click', { cancelable: true }));
    expect(document.querySelector<HTMLElement>('#kayzart-ai-return')!.hidden).toBe(false);
    window.dispatchEvent(new Event('focus'));
    expect(fetchMock).toHaveBeenCalledTimes(1);
    check.click();
    await vi.waitFor(() => expect(generate.disabled).toBe(false));
    expect(generate.hidden).toBe(false);
    expect(guide.hidden).toBe(true);
    expect(link.hidden).toBe(true);
    expect(prompt.value).toBe('A cafe page with opening hours');
    expect(document.querySelector<HTMLInputElement>('#kayzart-create-title')!.value).toBe('Salon launch');
    expect(document.querySelector<HTMLInputElement>('[name="post_type"]')!.value).toBe('page');
    expect(document.querySelector<HTMLInputElement>('[name="mode"]')!.value).toBe('normal');
    expect(document.querySelector('.is-submitting')).toBeNull();
    expect(fetchMock).toHaveBeenCalledTimes(2);
    for (const [, request] of fetchMock.mock.calls) expect(request!.method).toBe('GET');
  });

  it('preserves input through a failed check and an unconfigured response, then allows retry', async () => {
    renderForm(5, true);
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('offline'))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, ai: { available: false, availabilityUrl: '/availability', canSetUp: true } })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ ok: true, ai: { available: true, maxPromptChars: 5 } })));
    const prompt = document.querySelector<HTMLTextAreaElement>('#kayzart-initial-ai-prompt')!;
    const check = document.querySelector<HTMLButtonElement>('#kayzart-ai-recheck')!;
    prompt.value = 'hello'; prompt.dispatchEvent(new Event('input'));
    check.click(); check.click();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await vi.waitFor(() => expect(document.querySelector('#kayzart-ai-check-result')!.textContent).toContain('Retry'));
    expect(prompt.value).toBe('hello');
    check.click();
    await vi.waitFor(() => expect(document.querySelector('#kayzart-ai-check-result')!.textContent).toBe('Not configured'));
    expect(document.querySelector<HTMLElement>('#kayzart-ai-setup-card')!.hidden).toBe(false);
    check.click();
    await vi.waitFor(() => expect(document.querySelector<HTMLButtonElement>('#kayzart-generate-ai')!.disabled).toBe(false));
    expect(prompt.value).toBe('hello');
  });

});
