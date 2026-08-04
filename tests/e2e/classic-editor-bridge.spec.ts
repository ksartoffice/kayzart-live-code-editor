import { expect, test } from '@playwright/test';
import { adminPass, adminUser, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(!adminUser || !adminPass, 'Set the WordPress E2E credentials.');
test.setTimeout(60_000);

test('shows a Kayzart preview while protecting Classic Editor content', async ({ page }) => {
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
      test.info().annotations.push({
        type: 'skip-reason',
        description: 'The WordPress test site is configured to use Gutenberg.',
      });
      return;
    }

    const bridge = page.locator('.kayzart-editor-preview--classic');
    await expect(bridge).toBeVisible();
    await expect(page.locator('#postdivrich')).toBeHidden();
    await expect(page.locator('#title')).toBeVisible();
    await expect(page.locator('#submitdiv')).toBeVisible();
    await expect(page.locator('.kayzart-editor-preview__titleInput')).toHaveCount(0);
    await expect(page.locator('.kayzart-editor-preview__edit')).toHaveCount(1);
    await expect(page.locator('.kayzart-editor-preview__view')).toHaveCount(1);

    const previewMarker = page.frameLocator('.kayzart-editor-preview__frame').locator(`#${marker}`);
    await expect(previewMarker).toBeVisible();

    await page.locator('#title').fill('Updated Classic translated page');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
      page.locator('#save-post').click(),
    ]);

    const restNonce = await page.evaluate(() => String((window as any).wpApiSettings.nonce));
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
