import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { adminUser, adminPass, baseUrl, login } from './helpers/open-editor';
import { withTemporaryTemplatePage } from './helpers/temporary-page';

test.skip(process.env.KAYZART_TEMPLATE_E2E !== '1' || !adminUser || !adminPass, 'Requires a disposable template test site.');

test.beforeEach(async ({ context }) => {
  await context.route('**/*', route => {
    if (new URL(route.request().url()).host !== baseUrl.host) return route.abort();
    return route.continue();
  });
});

test('preview stays local and a template becomes an editable, publishable page', async ({ page }) => {
  test.setTimeout(90_000);
  const jobs: string[] = [];
  page.on('request', request => {
    if (request.method() === 'POST' && request.url().includes('/ai/jobs')) jobs.push(request.url());
  });
  await login(page);
  await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
  await expect(page.locator('.kayzart-template-card')).toBeVisible();
  await expect(page.locator('.kayzart-template-photo-note')).toBeVisible();
  await page.locator('[data-template-language]').selectOption('ja');
  await page.locator('[data-template-preview]').click();
  const frame = page.locator('#kayzart-template-preview iframe');
  await expect(frame).toHaveAttribute('sandbox', '');
  await expect(frame).toHaveAttribute('srcdoc', /images\/landscape\.png/);
  await page.locator('[data-template-width="mobile"]').click();
  await expect(frame).toHaveCSS('width', '390px');
  await page.locator('[data-template-close]').click();
  await page.locator('[data-template-preview]').click();
  const desktop = page.locator('[data-template-width="desktop"]');
  await expect(desktop).toBeFocused();
  await expect(desktop).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-template-width="mobile"]')).toHaveAttribute('aria-pressed', 'false');
  await expect(frame).toHaveAttribute('srcdoc', /images\/landscape\.png/);
  await expect.poll(async () => (await frame.boundingBox())!.width).toBeGreaterThan(390);
  await page.locator('[data-template-close]').click();

  await withTemporaryTemplatePage(page, 'Temporary template page', async postId => {
    await expect(page.locator('.kayzart-settings')).toBeVisible();
    await expect(page.locator('.kayzart-app')).toHaveClass(/is-editor-collapsed/);
    const initial = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(initial.html).toContain('/uploads/');
    expect(initial.html).not.toContain('assets/templates');
    expect(await page.evaluate(() => (window as any).KAYZART.initialCssByMode.normal)).toBeNull();
    await expect(page.frameLocator('.kayzart-iframe').locator('h1')).toBeVisible();
    await page.screenshot({ path: 'test-results/template-editor.png' });
    const nonce = await page.evaluate(() => (window as any).KAYZART.restNonce);
    const uploaded = await page.request.post(new URL('wp-json/wp/v2/media', baseUrl).toString(), {
      headers: { 'X-WP-Nonce': nonce },
      multipart: {
        post: String(postId),
        file: { name: 'template-replacement.png', mimeType: 'image/png', buffer: readFileSync('assets/templates/free/general-service-lp/images/landscape.png') },
      },
    });
    expect(uploaded.status()).toBe(201);
    const image = await uploaded.json();
    const editorPreview = page.frameLocator('.kayzart-iframe');
    await editorPreview.locator('#home img').click();
    await page.locator('#kayzart-elements-image-url').fill(image.source_url);
    await page.locator('#kayzart-elements-image-url').press('Tab');
    await expect(editorPreview.locator('#home img')).toHaveAttribute('src', image.source_url);
    await editorPreview.locator('h1').click();
    const text = page.locator('.kayzart-elementsTextInput').first();
    await text.fill('テスト用のサービス');
    await text.press('Tab');
    await expect(editorPreview.locator('h1')).toContainText('テスト用のサービス');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toBeEnabled();
    const currentNonce = await page.evaluate(() => (window as any).KAYZART.restNonce);
    const published = await page.request.post(new URL(`wp-json/wp/v2/pages/${postId}`, baseUrl).toString(), { headers: { 'X-WP-Nonce': currentNonce }, data: { status: 'publish' } });
    expect(published.ok()).toBe(true);
    const result = await published.json();
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto(result.link);
      await expect(publicPage.locator('h1')).toContainText('テスト用のサービス');
      await expect(publicPage.locator('#home img')).toHaveAttribute('src', image.source_url);
      expect(await publicPage.locator('#home img').evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
      expect(await publicPage.locator('body').evaluate(element => getComputedStyle(element).backgroundColor)).toBe('rgb(246, 245, 240)');
      await publicPage.screenshot({ path: 'test-results/template-published.png', fullPage: true });
      await publicPage.setViewportSize({ width: 390, height: 844 });
      await expect(publicPage.locator('h1')).toBeVisible();
      expect(await publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
      const missingAnchors = await publicPage.locator('a[href^="#"]').evaluateAll(links => links.filter(link => !document.getElementById(link.getAttribute('href')!.slice(1))).length);
      expect(missingAnchors).toBe(0);
    } finally {
      await publicPage.close();
    }
  });
  expect(jobs).toEqual([]);
});

test('mode switches preserve the template styles and source after reopening', async ({ page }) => {
  test.setTimeout(90_000);
  await login(page);
  await withTemporaryTemplatePage(page, 'Temporary template mode check', async () => {
    const source = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().css);
    await page.getByRole('tab', { name: 'Settings', exact: true }).click();
    const mode = page.getByRole('combobox', { name: 'CSS mode', exact: true });
    await mode.selectOption('normal');
    await page.getByRole('dialog').getByRole('button', { name: 'Switch mode', exact: true }).click();
    await expect.poll(() => page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorMode())).toBe('normal');
    const compiled = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().css);
    expect(compiled).toContain('--color-ink');
    expect(compiled).not.toContain('@import "tailwindcss"');
    const preview = page.frameLocator('.kayzart-iframe');
    await expect(preview.locator('body')).toHaveCSS('background-color', 'rgb(246, 245, 240)');
    await mode.selectOption('tailwind');
    await page.getByRole('dialog').getByRole('button', { name: 'Switch mode', exact: true }).click();
    await expect.poll(() => page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().css)).toBe(source);
    const saved = page.waitForResponse(response => response.url().includes('/kayzart/v1/save') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await saved).ok()).toBe(true);
    await page.reload();
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
    const reopened = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(reopened.css).toBe(source);
    expect(await page.evaluate(() => (window as any).KAYZART.initialCssByMode.normal)).toBe(compiled);
    await expect(preview.locator('body')).toHaveCSS('background-color', 'rgb(246, 245, 240)');
  });
});

test('language selection changes the thumbnail, preview, and saved English page together', async ({ page }) => {
  test.setTimeout(90_000);
  page.setDefaultTimeout(15_000);
  await login(page);
  await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
  const language = page.locator('[data-template-language]');
  const thumbnail = page.locator('.kayzart-template-art img');
  const preview = page.frameLocator('#kayzart-template-preview iframe');
  await language.selectOption('en');
  await expect(thumbnail).toHaveAttribute('src', /thumbnail\.en\.webp/);
  expect(await language.evaluate(select => new FormData(document.querySelector<HTMLFormElement>('.kayzart-template-form')!).get('template_locale'))).toBe('en');
  await page.locator('[data-template-preview]').click();
  await expect(preview.locator('html')).toHaveAttribute('lang', 'en');
  await expect(preview.locator('h1')).toContainText('Good design.');
  await page.locator('[data-template-close]').click();
  await language.selectOption('ja');
  await expect(thumbnail).toHaveAttribute('src', /\/thumbnail\.webp/);
  await page.locator('[data-template-preview]').click();
  await expect(preview.locator('html')).toHaveAttribute('lang', 'ja');
  await expect(preview.locator('h1')).toContainText('伝わるデザインで、');
  await page.locator('[data-template-close]').click();
  await language.selectOption('en');
  await page.locator('[data-template-preview]').click();
  await expect(preview.locator('h1')).toContainText('Good design.');
  await page.locator('[data-template-close]').click();
  await withTemporaryTemplatePage(page, 'Temporary English template page', async postId => {
    const initial = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(initial.html).toContain('lang="en"');
    expect(initial.html).toContain('Good design.');
    expect(initial.html).not.toContain('伝わるデザインで、');
    await page.evaluate(() => {
      const api = (window as any).KAYZART_EXTENSION_API;
      const snapshot = api.getEditorSnapshot();
      snapshot.html = snapshot.html.replace('Good design.', 'A fresh start,');
      if (!api.replaceEditorSnapshot(snapshot)) throw new Error('English snapshot was rejected');
    });
    const saved = page.waitForResponse(response => response.url().includes('/kayzart/v1/save') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await saved).ok()).toBe(true);
    const nonce = await page.evaluate(() => (window as any).KAYZART.restNonce);
    const published = await page.request.post(new URL(`wp-json/wp/v2/pages/${postId}`, baseUrl).toString(), { headers: { 'X-WP-Nonce': nonce }, data: { status: 'publish' } });
    expect(published.ok()).toBe(true);
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto((await published.json()).link);
      await expect(publicPage.locator('div[lang="en"]')).toBeVisible();
      await expect(publicPage.locator('h1')).toContainText('A fresh start,');
      await publicPage.setViewportSize({ width: 390, height: 844 });
      expect(await publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
      expect(await publicPage.locator('a[href^="#"]').evaluateAll(links => links.filter(link => !document.getElementById(link.getAttribute('href')!.slice(1))).length)).toBe(0);
    } finally {
      await publicPage.close();
    }
  }, 'en');
});
