import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const script = readFileSync('assets/admin/deactivation-feedback.js', 'utf8');
let dom: JSDOM;
let doc: Document;
let dialog: HTMLDialogElement;
let browser: any;
let navigate: ReturnType<typeof vi.fn>;
let fetch: ReturnType<typeof vi.fn>;
function open() { doc.querySelector<HTMLAnchorElement>('.deactivate a')!.click(); }
function submit() { doc.querySelector('form')!.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true })); }

describe('deactivation feedback', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    dom = new JSDOM('<table><tr data-plugin="kayzart-live-code-editor/kayzart-live-code-editor.php"><td><span class="deactivate"><a href="https://example.test/plugins.php?action=deactivate&nonce=core">Deactivate</a></span></td></tr></table><dialog id="kayzart-deactivation-dialog"><form><h2 tabindex="-1">Why?</h2><input type="radio" name="reason" value="cost"><textarea name="comment"></textarea><p role="alert"></p><button type="submit">Send</button><button type="button" data-kayzart-skip>Skip</button><button type="button" data-kayzart-cancel>Cancel</button></form></dialog>', { url: 'https://example.test/plugins.php' });
    doc = dom.window.document;
    dialog = doc.querySelector('dialog')!;
    dialog.showModal = () => { dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    navigate = vi.fn();
    fetch = vi.fn().mockResolvedValue({ ok: true });
    browser = { KAYZART_DEACTIVATION: { ajaxUrl: '/ajax', action: 'survey', nonce: 'nonce', submissionId: 'answer-id', pluginFile: 'kayzart-live-code-editor/kayzart-live-code-editor.php', emptyError: 'Empty answer', lengthError: 'Too long' }, location: { href: 'https://example.test/plugins.php', assign: navigate }, fetch, AbortController, setTimeout, clearTimeout };
    new Function('window', 'document', script)(browser, doc);
    doc.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
  });
  afterEach(() => { dom.window.close(); vi.useRealTimers(); });

  it('opens without sending, has no selected reason, cancels and restores focus', () => {
    open();
    expect(dialog.open).toBe(true);
    expect(doc.querySelector('[name="reason"]:checked')).toBeNull();
    doc.querySelector<HTMLButtonElement>('[data-kayzart-cancel]')!.click();
    expect(dialog.open).toBe(false);
    expect(doc.activeElement).toBe(doc.querySelector('.deactivate a'));
    expect(fetch).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
  });
  it('Escape cancels without sending', () => {
    open(); dialog.dispatchEvent(new dom.window.Event('cancel', { cancelable: true }));
    expect(dialog.open).toBe(false); expect(fetch).not.toHaveBeenCalled();
  });
  it('keeps Tab and Shift+Tab inside the modal', () => {
    open();
    const first = doc.querySelector<HTMLInputElement>('input')!;
    const last = doc.querySelector<HTMLButtonElement>('[data-kayzart-cancel]')!;
    last.focus();
    last.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }));
    expect(doc.activeElement).toBe(first);
    first.dispatchEvent(new dom.window.KeyboardEvent('keydown', { key: 'Tab', shiftKey: true, bubbles: true, cancelable: true }));
    expect(doc.activeElement).toBe(last);
  });
  it('skips with the original core URL and makes no request', () => {
    open(); doc.querySelector<HTMLButtonElement>('[data-kayzart-skip]')!.click();
    expect(navigate).toHaveBeenCalledWith('https://example.test/plugins.php?action=deactivate&nonce=core');
    expect(fetch).not.toHaveBeenCalled();
  });
  it('validates empty and overlong answers but leaves skip available', () => {
    open(); submit(); expect(doc.querySelector('[role="alert"]')!.textContent).toBe('Empty answer');
    doc.querySelector<HTMLTextAreaElement>('textarea')!.value = '🎨'.repeat(1001);
    submit(); expect(doc.querySelector('[role="alert"]')!.textContent).toBe('Too long');
    expect(fetch).not.toHaveBeenCalled();
    expect(doc.querySelector<HTMLButtonElement>('[data-kayzart-skip]')!.disabled).toBe(false);
  });
  it('accepts comment-only unicode answers and prevents duplicate sending', async () => {
    open(); doc.querySelector<HTMLTextAreaElement>('textarea')!.value = '🎨'.repeat(1000);
    submit(); submit();
    expect(fetch).toHaveBeenCalledTimes(1);
    const request = fetch.mock.calls[0][1];
    expect(request.body.get('reason')).toBe(''); expect(request.body.get('comment')).toHaveLength(2000);
    expect([...request.body.keys()]).toEqual(['action', 'nonce', 'submission_id', 'reason', 'comment']);
    await Promise.resolve(); expect(navigate).toHaveBeenCalledTimes(1);
  });
  it.each([new Error('offline'), { ok: false, status: 410 }, { malformed: true }])('continues after failure or invalid response %s', async (response) => {
    if (response instanceof Error) fetch.mockRejectedValue(response); else fetch.mockResolvedValue(response);
    open(); doc.querySelector<HTMLInputElement>('input')!.checked = true; submit();
    await Promise.resolve(); expect(navigate).toHaveBeenCalledTimes(1);
  });
  it('aborts at five seconds and does not navigate twice on a late response', async () => {
    let resolve!: (value: unknown) => void;
    fetch.mockReturnValue(new Promise((done) => { resolve = done; }));
    open(); doc.querySelector<HTMLInputElement>('input')!.checked = true; submit();
    vi.advanceTimersByTime(4999); expect(navigate).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1); expect(navigate).toHaveBeenCalledTimes(1);
    expect(fetch.mock.calls[0][1].signal.aborted).toBe(true);
    resolve({ ok: true }); await Promise.resolve(); expect(navigate).toHaveBeenCalledTimes(1);
  });
});
