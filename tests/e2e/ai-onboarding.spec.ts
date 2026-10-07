import { expect, test, type Page } from '@playwright/test';
import { adminUser, adminPass, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

// Requires fixtures/ai-onboarding.php as a mu-plugin and KAYZART_ONBOARDING_E2E=true
// in a disposable installation. Never run this fixture against a production site.
test.skip(process.env.KAYZART_ONBOARDING_E2E !== '1' || !adminUser || !adminPass, 'Requires the disposable onboarding fixture.');

test.beforeEach(async ({ context }) => {
  await context.route('**/*', (route) => {
    if (new URL(route.request().url()).host !== baseUrl.host) return route.abort();
    return route.continue();
  });
});

async function setFixture(page: Page, mode: string, ready = false) {
  await page.context().addCookies([
    { name: 'kayzart_onboarding_mode', value: mode, url: baseUrl.toString() },
    { name: 'kayzart_onboarding_ready', value: ready ? 'yes' : 'no', url: baseUrl.toString() },
  ]);
}

test('saving a key preserves every collapsed advanced setting', async ({ page }) => {
  await setFixture(page, 'direct');
  await login(page);
  await page.goto(new URL('wp-admin/admin.php?page=kayzart-settings', baseUrl).toString());
  const advanced = page.locator('details.kayzart-ai-advanced');
  await expect(advanced).not.toHaveAttribute('open', '');
  await advanced.locator('summary').click();
  const model = await page.locator('[name="kayzart_ai_default_model"]').inputValue();
  await page.locator('[name="kayzart_ai_site_instructions"]').fill('Keep the cafe name.');
  await page.locator('[name="kayzart_ai_max_turns"]').fill('11');
  await page.locator('[name="kayzart_ai_max_prompt_chars"]').fill('1900');
  await page.locator('input[type="checkbox"][name="kayzart_ai_reference_fetch"]').uncheck();
  await page.getByRole('button', { name: 'Save Changes', exact: true }).click();
  await page.waitForLoadState('networkidle');
  await expect(advanced).not.toHaveAttribute('open', '');
  await page.locator('[name="kayzart_openai_api_key"]').fill('sk-e2e-local-fixture');
  await page.getByRole('button', { name: 'Save connection settings', exact: true }).click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('[name="kayzart_ai_default_model"]')).toHaveValue(model);
  await expect(page.locator('[name="kayzart_ai_site_instructions"]')).toHaveValue('Keep the cafe name.');
  await expect(page.locator('[name="kayzart_ai_max_turns"]')).toHaveValue('11');
  await expect(page.locator('[name="kayzart_ai_max_prompt_chars"]')).toHaveValue('1900');
  await expect(page.locator('input[type="checkbox"][name="kayzart_ai_reference_fetch"]')).not.toBeChecked();
});

for (const mode of ['direct', 'connectors', 'fallback']) {
  test(`new page retains input through ${mode} setup and manual recheck`, async ({ page }) => {
    await setFixture(page, mode);
    await login(page);
    const writes: string[] = [];
    page.on('request', (request) => {
      if (request.method() === 'POST') writes.push(request.url());
    });
    await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
    const prompt = page.locator('#kayzart-initial-ai-prompt');
    await prompt.fill('A cafe page with opening hours and directions.');
    await page.locator('#kayzart-create-title').fill('Onboarding cafe');
    await page.locator('label:has(input[name="mode"][value="normal"])').click();
    const link = page.locator('#kayzart-ai-open-settings');
    await expect(link).toHaveAttribute('target', '_blank');
    await expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    await expect(link).toHaveAttribute('href', mode === 'connectors' ? /options-connectors\.php$/ : /page=kayzart-settings#kayzart-ai-connection$/);
    // Settings are represented in a separate tab; no real credential is entered.
    await page.context().route(/options-connectors\.php|page=kayzart-settings/, (route) => route.fulfill({ contentType: 'text/html', body: '<p>Fixture connection settings</p>' }));
    const popupPromise = page.waitForEvent('popup');
    await link.click();
    const popup = await popupPromise;
    await popup.waitForLoadState();
    await setFixture(page, mode, true);
    await popup.close();
    await page.bringToFront();
    await expect(page.locator('#kayzart-generate-ai')).toBeHidden();
    await page.locator('#kayzart-ai-recheck').click();
    await expect(page.locator('#kayzart-generate-ai')).toBeEnabled();
    await expect(prompt).toHaveValue('A cafe page with opening hours and directions.');
    await expect(page.locator('#kayzart-create-title')).toHaveValue('Onboarding cafe');
    await expect(page.locator('input[name="mode"][value="normal"]')).toBeChecked();
    await expect(page.locator('#kayzart-ai-setup-card')).toBeHidden();
    expect(writes).toEqual([]);
  });

  test(`editor retains unsaved code through ${mode} setup and enables explicit send`, async ({ page }) => {
    await setFixture(page, mode);
    await login(page);
    const postId = await createTemporaryPage(page, { title: `Onboarding ${mode}`, content: '<main>Original</main>' });
    try {
      await openKayzartEditor(page, String(postId), 'normal');
      const draft = await page.evaluate(() => {
        const api = (window as any).KAYZART_EXTENSION_API;
        const snapshot = { ...api.getEditorSnapshot(), html: '<main>Unsaved cafe draft</main>', css: 'main { color: red; }', js: 'console.log("draft")' };
        if (!api.replaceEditorSnapshot(snapshot)) throw new Error('Fixture snapshot rejected');
        return api.getEditorSnapshot();
      });
      await page.getByRole('button', { name: /AI Setup|AI設定/, exact: true }).first().click();
      const panel = page.locator('.kayzart-ai-panel');
      const prompt = panel.locator('textarea');
      await prompt.fill('Add opening hours');
      const link = panel.locator('a.kayzart-btn[target="_blank"]');
      await expect(link).toHaveAttribute('href', mode === 'connectors' ? /options-connectors\.php$/ : /#kayzart-ai-connection$/);
      await page.context().route(/options-connectors\.php|page=kayzart-settings/, (route) => route.fulfill({ contentType: 'text/html', body: '<p>Fixture connection settings</p>' }));
      const popupPromise = page.waitForEvent('popup');
      await link.click();
      const popup = await popupPromise;
      await popup.waitForLoadState();
      await setFixture(page, mode, true);
      await popup.close();
      await page.bringToFront();
      const send = panel.locator('.kayzart-ai-composer-footer button').last();
      await expect(send).toBeDisabled();
      let submitted: Record<string, unknown> | null = null;
      await page.route('**/kayzart/v1/ai/jobs', async (route) => {
        submitted = route.request().postDataJSON();
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ code: 'e2e_stub', message: 'AI response stubbed; no provider request.' }) });
      });
      await panel.getByRole('button', { name: /Check settings again|設定を再確認する/ }).click();
      await expect(send).toBeEnabled();
      await expect(page.getByRole('button', { name: 'AI Edit', exact: true }).first()).toBeVisible();
      await expect(prompt).toHaveValue('Add opening hours');
      expect(await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).toEqual(draft);
      expect(submitted).toBeNull();
      await send.click();
      await expect.poll(() => submitted).not.toBeNull();
      expect(submitted).toMatchObject({ post_id: postId, prompt: 'Add opening hours', html: draft.html, css: draft.css, js: draft.js });
    } finally {
      await deleteTemporaryPage(page, postId);
    }
  });
}
