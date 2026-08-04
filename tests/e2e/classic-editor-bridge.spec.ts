import { expect, test } from '@playwright/test';
import { adminPass, adminUser, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(!adminUser || !adminPass, 'Set the WordPress E2E credentials.');
test.setTimeout(60_000);

test('shows a Kayzart bridge card while protecting Classic Editor content', async ({ page }) => {
  await login(page);
  const marker = `kayzart-classic-bridge-${Date.now()}`;
  const originalHtml = `<main id="${marker}">Classic translated page</main>`;
  const postId = await createTemporaryPage(page, {
    title: 'Classic translated page',
    content: originalHtml,
  });

  try {
    await openKayzartEditor(page, String(postId), 'normal');

    const editUrl = new URL('wp-admin/post.php', baseUrl);
    editUrl.searchParams.set('post', String(postId));
    editUrl.searchParams.set('action', 'edit');
    await page.goto(editUrl.toString(), { waitUntil: 'domcontentloaded' });

    if (await page.locator('body.block-editor-page').count()) {
      test.skip(true, 'The WordPress test site is configured to use Gutenberg.');
    }

    const bridge = page.locator('.kayzart-editor-bridge--classic');
    await expect(bridge).toBeVisible();
    await expect(page.locator('#postdivrich')).toBeHidden();
    await expect(page.locator('#title')).toBeVisible();
    await expect(page.locator('#submitdiv')).toBeVisible();
    await expect(page.locator('.kayzart-editor-bridge__titleInput')).toHaveCount(0);
    await expect(page.locator('.kayzart-editor-bridge__edit')).toHaveCount(1);
    await expect(page.locator('.kayzart-editor-bridge__view')).toHaveCount(1);
    await expect(bridge.locator('iframe')).toHaveCount(0);
    await expect(bridge.locator('.kayzart-editor-bridge__reload')).toHaveCount(0);

    const restNonce = await page.evaluate(() => String((window as any).wpApiSettings.nonce));
    await page.locator('#title').fill('Updated Classic translated page');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('.kayzart-editor-bridge__edit').click(),
    ]);
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API));

    const response = await page.request.get(
      new URL(`wp-json/wp/v2/pages/${postId}?context=edit`, baseUrl).toString(),
      { headers: { 'X-WP-Nonce': restNonce } }
    );
    expect(response.status()).toBe(200);
    const saved = (await response.json()) as {
      title: { raw: string };
      content: { raw: string };
    };
    expect(saved.title.raw).toBe('Updated Classic translated page');
    expect(saved.content.raw).toBe(originalHtml);
  } finally {
    await deleteTemporaryPage(page, postId);
  }
});
