import { expect, test } from '@playwright/test';
import { adminPass, adminUser, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(!adminUser || !adminPass, 'Set the WordPress E2E credentials.');
test.setTimeout(60_000);

test('shows a Kayzart bridge card while protecting Gutenberg content', async ({ page }) => {
  await login(page);
  const marker = `kayzart-bridge-${Date.now()}`;
  const originalHtml = `<main id="${marker}">Translated page</main>`;
  const postId = await createTemporaryPage(page, {
    title: 'Translated page',
    content: originalHtml,
  });

  try {
    const unmanagedEditUrl = new URL('wp-admin/post.php', baseUrl);
    unmanagedEditUrl.searchParams.set('post', String(postId));
    unmanagedEditUrl.searchParams.set('action', 'edit');
    await page.goto(unmanagedEditUrl.toString(), { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.kayzart-editor-bridge')).toHaveCount(0);
    if (!(await page.locator('body.block-editor-page').count())) {
      test.skip(true, 'The WordPress test site is configured to use the Classic Editor.');
    }
    const unmanagedEditor = await page.evaluate(() => ({
      isBlockEditor: document.body.classList.contains('block-editor-page'),
      hasBridge: Boolean((window as any).KAYZART_EDITOR),
      content: String((window as any).wp.data.select('core/editor').getEditedPostContent()),
    }));
    expect(unmanagedEditor.isBlockEditor).toBe(true);
    expect(unmanagedEditor.hasBridge).toBe(false);
    expect(unmanagedEditor.content).toContain(marker);

    await openKayzartEditor(page, String(postId), 'normal');
    const editUrl = new URL('wp-admin/post.php', baseUrl);
    editUrl.searchParams.set('post', String(postId));
    editUrl.searchParams.set('action', 'edit');
    await page.goto(editUrl.toString(), { waitUntil: 'domcontentloaded' });

    const bridge = page.locator('.kayzart-editor-bridge');
    await expect(bridge).toBeVisible();
    await expect(page.locator('.kayzart-editor-bridge__edit')).toHaveText('Edit with Kayzart');
    await expect(page.locator('.kayzart-editor-bridge__view')).toHaveCount(1);
    await expect(bridge.locator('iframe')).toHaveCount(0);
    await expect(bridge.locator('.kayzart-editor-bridge__reload')).toHaveCount(0);
    await expect(page.locator('.kayzart-editor-toolbar')).toHaveCount(0);
    await expect(page.locator('.block-editor-block-list__layout')).not.toBeVisible();

    const titleInput = page.locator('.kayzart-editor-bridge__titleInput');
    const restNonce = await page.evaluate(() => String((window as any).wpApiSettings.nonce));
    await titleInput.fill('Updated translated page');
    const [coreSave] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes(`/wp-json/wp/v2/pages/${postId}`) &&
          response.request().method() === 'POST'
      ),
      page.locator('.kayzart-editor-bridge__edit').click(),
    ]);
    expect(coreSave.status()).toBe(200);
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
    expect(saved.title.raw).toBe('Updated translated page');
    expect(saved.content.raw).toBe(originalHtml);
  } finally {
    await deleteTemporaryPage(page, postId);
  }
});

test('returns a converted page to Gutenberg while keeping the current HTML', async ({ page }) => {
  await login(page);
  const marker = `kayzart-return-${Date.now()}`;
  const originalHtml = `<main id="${marker}">Return to Gutenberg</main>`;
  const postId = await createTemporaryPage(page, {
    title: 'Return to Gutenberg',
    content: originalHtml,
  });

  try {
    await openKayzartEditor(page, String(postId), 'normal');
    const editUrl = new URL('wp-admin/post.php', baseUrl);
    editUrl.searchParams.set('post', String(postId));
    editUrl.searchParams.set('action', 'edit');
    await page.goto(editUrl.toString(), { waitUntil: 'domcontentloaded' });
    if (!(await page.locator('body.block-editor-page').count())) {
      test.skip(true, 'The WordPress test site is configured to use the Classic Editor.');
    }

    const bridge = page.locator('.kayzart-editor-bridge');
    await expect(bridge).toBeVisible();
    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('.kayzart-editor-bridge__return').click();
    await page.waitForURL((url) => url.pathname.endsWith('/wp-admin/post.php') && url.searchParams.get('post') === String(postId));
    await expect(page.locator('.kayzart-editor-bridge')).toHaveCount(0);

    const content = await page.evaluate(() => String((window as any).wp.data.select('core/editor').getEditedPostContent()));
    expect(content).toContain(marker);
  } finally {
    await deleteTemporaryPage(page, postId);
  }
});
