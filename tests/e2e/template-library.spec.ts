import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { adminUser, adminPass, baseUrl, login } from './helpers/open-editor';
import { withTemporaryTemplatePage } from './helpers/temporary-page';

test.skip(process.env.KAYZART_TEMPLATE_E2E !== '1' || !adminUser || !adminPass, 'Requires a disposable template test site.');

test.beforeEach(async ({ context }) => {
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.hostname === 'fonts.googleapis.com') return route.fulfill({ status: 200, contentType: 'text/css', body: '/* Google Fonts mocked; fallback layout remains readable. */' });
    if (url.host !== baseUrl.host) return route.abort();
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
  const visibleCard = page.locator('.kayzart-template-card:visible');
  await page.locator('[data-template-language-filter]').selectOption('ja');
  await expect(visibleCard).toHaveCount(1);
  await expect(visibleCard.locator('.kayzart-template-photo-note')).toBeVisible();
  await expect(visibleCard.locator('.kayzart-template-font-note')).toContainText('Noto Sans JP');
  await visibleCard.locator('[data-template-preview]').click();
  const frame = page.locator('#kayzart-template-preview iframe');
  await expect(frame).toHaveAttribute('sandbox', 'allow-scripts');
  await expect(frame).toHaveAttribute('srcdoc', /images\/landscape\.png/);
  await page.locator('[data-template-width="mobile"]').click();
  await expect(frame).toHaveCSS('width', '390px');
  await page.locator('[data-template-close]').click();
  await page.locator('.kayzart-template-card:visible [data-template-preview]').click();
  const desktop = page.locator('[data-template-width="desktop"]');
  await expect(desktop).toBeFocused();
  await expect(desktop).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-template-width="mobile"]')).toHaveAttribute('aria-pressed', 'false');
  await expect(frame).toHaveAttribute('srcdoc', /images\/landscape\.png/);
  await expect.poll(async () => (await frame.boundingBox())!.width).toBeGreaterThan(390);
  await page.locator('[data-template-close]').click();

  // The English design is a separate card with its own Google Fonts.
  await page.locator('[data-template-language-filter]').selectOption('en');
  const englishCard = page.locator('.kayzart-template-card:visible');
  await expect(englishCard).toHaveCount(1);
  await expect(englishCard.locator('.kayzart-template-font-note')).toContainText('Instrument Sans, Instrument Serif');
  await expect(englishCard.locator('[name="template_locale"]')).toHaveValue('en');
  await englishCard.locator('[data-template-preview]').click();
  await expect(frame).toHaveAttribute('srcdoc', /Less busywork\./);
  await expect(frame).toHaveAttribute('srcdoc', /family=Instrument\+Sans/);
  await expect(frame).toHaveAttribute('srcdoc', /business-motion-en\/images\/landscape\.png/);
  await page.locator('[data-template-close]').click();
  await page.locator('[data-template-language-filter]').selectOption('ja');

  await withTemporaryTemplatePage(page, 'Temporary template page', async postId => {
    await expect(page.locator('.kayzart-settings')).toBeVisible();
    await expect(page.locator('.kayzart-app')).toHaveClass(/is-editor-collapsed/);
    const initial = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(initial.html).toContain('/uploads/');
    expect(initial.html).not.toContain('assets/templates');
    expect(await page.evaluate(() => (window as any).KAYZART.initialCssByMode.normal)).toBeNull();
    await expect(page.frameLocator('.kayzart-iframe').locator('h1')).toBeVisible();
    // The template is styled by Tailwind utilities in the HTML, compiled for the page.
    await expect(page.frameLocator('.kayzart-iframe').locator('h1')).toHaveCSS('font-weight', '900');
    await expect(page.frameLocator('.kayzart-iframe').locator('h1 > span').nth(1)).toHaveCSS('color', 'rgb(239, 87, 52)');
    await page.screenshot({ path: 'test-results/template-editor.png' });
    const nonce = await page.evaluate(() => (window as any).KAYZART.restNonce);
    const uploaded = await page.request.post(new URL('wp-json/wp/v2/media', baseUrl).toString(), {
      headers: { 'X-WP-Nonce': nonce },
      multipart: {
        post: String(postId),
        file: { name: 'template-replacement.png', mimeType: 'image/png', buffer: readFileSync('assets/templates/free/business-motion-ja/images/landscape.png') },
      },
    });
    expect(uploaded.status()).toBe(201);
    const image = await uploaded.json();
    const editorPreview = page.frameLocator('.kayzart-iframe');
    await editorPreview.locator('#approach img').click();
    await page.locator('#kayzart-elements-image-url').fill(image.source_url);
    await page.locator('#kayzart-elements-image-url').press('Tab');
    await expect(editorPreview.locator('#approach img')).toHaveAttribute('src', image.source_url);
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
      await expect.poll(() => publicPage.locator('[data-kz-effect="parallax"]').evaluate(el => el.getAnimations().length)).toBe(1);
      await expect(publicPage.locator('#approach img')).toHaveAttribute('src', image.source_url);
      expect(await publicPage.locator('#approach img').evaluate((element: HTMLImageElement) => element.naturalWidth)).toBeGreaterThan(0);
      expect(await publicPage.locator('body').evaluate(element => getComputedStyle(element).backgroundColor)).toBe('rgb(255, 253, 248)');
      await expect(publicPage.locator('h1')).toHaveCSS('font-weight', '900');
      await expect(publicPage.locator('#process')).toHaveCSS('background-color', 'rgb(32, 35, 34)');
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

test('declared motion responds to live HTML edits and survives saving and publishing', async ({ page }) => {
  test.setTimeout(100_000);
  await login(page);
  const google: string[] = [];
  page.on('request', request => { if (new URL(request.url()).hostname === 'fonts.googleapis.com') google.push(request.url()); });
  await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
  // The disposable site has no WPLANG, so English is the initial content language.
  await expect(page.locator('[data-template-language-filter]')).toHaveValue('en');
  await expect(page.locator('[data-template-language-filter] option')).toHaveCount(2);
  await expect(page.locator('.kayzart-template-card select')).toHaveCount(0);
  await page.locator('.kayzart-template-card:visible [name="post_title"]').fill('Kept title');
  await page.locator('[data-template-language-filter]').selectOption('ja');
  await expect(page.locator('.kayzart-template-card:visible [name="post_title"]')).toHaveValue('Kept title');
  expect(google).toEqual([]);
  await page.locator('.kayzart-template-card:visible [data-template-preview]').click();
  const detail = page.frameLocator('#kayzart-template-preview iframe');
  await expect(detail.locator('h1')).toContainText('終止符を。');
  await expect.poll(() => detail.locator('[data-kz-effect="float"]').evaluate(el => el.getAnimations().length)).toBe(1);
  await expect.poll(() => google.length).toBeGreaterThan(0);
  await page.locator('[data-template-close]').click();
  await expect(page.locator('#kayzart-template-preview iframe')).not.toHaveAttribute('srcdoc');

  await withTemporaryTemplatePage(page, 'Temporary motion template', async postId => {
    const initial = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(initial.jsMode).toBe('module');
    expect(initial.customHead).toContain('fonts.googleapis.com');
    const editor = page.frameLocator('.kayzart-iframe');
    await expect(editor.locator('h1')).toBeVisible();
    await page.waitForTimeout(400);
    await page.evaluate(() => {
      const api = (window as any).KAYZART_EXTENSION_API;
      const snapshot = api.getEditorSnapshot();
      snapshot.html = snapshot.html.replace('data-kz-distance="12"', 'data-kz-distance="4"');
      if (!api.replaceEditorSnapshot(snapshot)) throw new Error('Motion attribute edit was rejected');
    });
    await expect(editor.locator('[data-kz-effect="float"]')).toHaveAttribute('data-kz-distance', '4');
    await expect.poll(() => editor.locator('[data-kz-effect="float"]').evaluate(el => el.getAnimations().some(animation => (animation.effect as KeyframeEffect).getKeyframes().some(frame => String(frame.transform).includes('-4px'))))).toBe(true);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await expect.poll(() => editor.locator('[data-kz-motion]').evaluate(el => el.getAnimations({ subtree: true }).length)).toBe(0);
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    await editor.locator('[data-kz-motion]').evaluate(root => {
      const element = document.createElement('p');
      element.className = 'bm-motion-fixture';
      element.setAttribute('data-kz-effect', 'float');
      element.textContent = 'Temporary animation';
      root.appendChild(element);
    });
    await expect.poll(() => editor.locator('.bm-motion-fixture').evaluate(el => el.getAnimations().length)).toBe(1);
    const detached = await editor.locator('.bm-motion-fixture').elementHandle();
    await editor.locator('.bm-motion-fixture').evaluate(element => element.remove());
    await expect(editor.locator('.bm-motion-fixture')).toHaveCount(0);
    await expect.poll(() => detached!.evaluate(el => el.getAnimations().length)).toBe(0);
    await page.evaluate(() => {
      const api = (window as any).KAYZART_EXTENSION_API;
      const snapshot = api.getEditorSnapshot();
      snapshot.html = snapshot.html.replace('data-kz-motion="on"', 'data-kz-motion="off"');
      api.replaceEditorSnapshot(snapshot);
    });
    await expect(editor.locator('[data-kz-motion]')).toHaveAttribute('data-kz-motion', 'off');
    await expect.poll(() => editor.locator('[data-kz-motion]').evaluate(el => el.getAnimations({ subtree: true }).length)).toBe(0);
    const saved = page.waitForResponse(response => response.url().includes('/kayzart/v1/save') && response.request().method() === 'POST');
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await saved).ok()).toBe(true);
    await page.reload();
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
    const reopened = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
    expect(reopened.js).toBe(initial.js);
    expect(reopened.jsMode).toBe(initial.jsMode);
    expect(reopened.customHead).toBe(initial.customHead);
    expect(reopened.html).toContain('data-kz-motion="off"');
    const nonce = await page.evaluate(() => (window as any).KAYZART.restNonce);
    const published = await page.request.post(new URL(`wp-json/wp/v2/pages/${postId}`, baseUrl).toString(), { headers: { 'X-WP-Nonce': nonce }, data: { status: 'publish' } });
    expect(published.ok()).toBe(true);
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto((await published.json()).link);
      await expect(publicPage.locator('[data-kz-motion]')).toHaveAttribute('data-kz-motion', 'off');
      await expect(publicPage.locator('head link[href*="fonts.googleapis.com"]')).toHaveCount(1);
      await publicPage.setViewportSize({ width: 390, height: 844 });
      expect(await publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390);
    } finally { await publicPage.close(); }
  });
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
    await expect(preview.locator('body')).toHaveCSS('background-color', 'rgb(255, 253, 248)');
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
    await expect(preview.locator('body')).toHaveCSS('background-color', 'rgb(255, 253, 248)');
  });
});
