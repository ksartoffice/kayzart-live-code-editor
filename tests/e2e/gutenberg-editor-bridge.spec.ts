import { expect, test } from '@playwright/test';
import { adminPass, adminUser, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(!adminUser || !adminPass, 'Set the WordPress E2E credentials.');
test.setTimeout(60_000);

test('shows a styled Kayzart preview while protecting Gutenberg content', async ({ page }) => {
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
    await expect(page.locator('.kayzart-editor-preview')).toHaveCount(0);
    if (!(await page.locator('body.block-editor-page').count())) {
      test.info().annotations.push({
        type: 'skip-reason',
        description: 'The WordPress test site is configured to use the Classic Editor.',
      });
      return;
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
    const kayzartNonce = await page.evaluate(() => String((window as any).KAYZART.restNonce));
    const saveResponse = await page.request.post(
      new URL('wp-json/kayzart/v1/save', baseUrl).toString(),
      {
        headers: { 'X-WP-Nonce': kayzartNonce },
        data: {
          post_id: postId,
          html: originalHtml,
          css: `#${marker}{color:rgb(18,52,86)}`,
          js: [
            "document.documentElement.setAttribute('data-kayzart-saved-classic-js', 'ran');",
            "try { window.parent.document.documentElement.setAttribute('data-kayzart-frame-escape', '1'); } catch (error) {}",
            "try { window.top.location.href = '/?kayzart_admin_escape=1'; } catch (error) {}",
          ].join('\n'),
          jsMode: 'classic',
          tailwindEnabled: false,
        },
      }
    );
    expect(saveResponse.status(), (await saveResponse.text()).slice(0, 500)).toBe(200);

    const editUrl = new URL('wp-admin/post.php', baseUrl);
    editUrl.searchParams.set('post', String(postId));
    editUrl.searchParams.set('action', 'edit');
    await page.goto(editUrl.toString(), { waitUntil: 'domcontentloaded' });

    const bridge = page.locator('.kayzart-editor-preview');
    await expect(bridge).toBeVisible();
    await expect(page.locator('.kayzart-editor-preview__edit')).toHaveText('Edit with Kayzart');
    const previewFrame = page.locator('.kayzart-editor-preview__frame');
    await expect(previewFrame).toHaveAttribute('sandbox', 'allow-scripts');
    await expect(previewFrame).toHaveAttribute(
      'referrerpolicy',
      'strict-origin-when-cross-origin'
    );
    await expect(page.locator('.kayzart-editor-toolbar')).toHaveCount(0);
    await expect(page.locator('.block-editor-block-list__layout')).not.toBeVisible();
    const previewMarker = page.frameLocator('.kayzart-editor-preview__frame').locator(`#${marker}`);
    await expect(previewMarker).toBeVisible();
    await expect
      .poll(() => previewMarker.evaluate((node) => getComputedStyle(node).color))
      .toBe('rgb(18, 52, 86)');
    await expect(
      page.frameLocator('.kayzart-editor-preview__frame').locator('html')
    ).toHaveAttribute('data-kayzart-saved-classic-js', 'ran');
    await expect(page.locator('html')).not.toHaveAttribute('data-kayzart-frame-escape', '1');
    expect(new URL(page.url()).searchParams.has('kayzart_admin_escape')).toBe(false);

    const moduleSaveResponse = await page.request.post(
      new URL('wp-json/kayzart/v1/save', baseUrl).toString(),
      {
        headers: { 'X-WP-Nonce': kayzartNonce },
        data: {
          post_id: postId,
          html: originalHtml,
          css: `#${marker}{color:rgb(18,52,86)}`,
          js: "export default function () { document.documentElement.setAttribute('data-kayzart-saved-module-js', 'ran'); }",
          jsMode: 'module',
          tailwindEnabled: false,
        },
      }
    );
    expect(moduleSaveResponse.status(), (await moduleSaveResponse.text()).slice(0, 500)).toBe(200);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(
      page.frameLocator('.kayzart-editor-preview__frame').locator('html')
    ).toHaveAttribute('data-kayzart-saved-module-js', 'ran');

    const titleInput = page.locator('.kayzart-editor-preview__titleInput');
    const restNonce = await page.evaluate(() => String((window as any).wpApiSettings.nonce));
    await titleInput.fill('Updated translated page');
    const [coreSave] = await Promise.all([
      page.waitForResponse(
        (response) =>
          response.url().includes(`/wp-json/wp/v2/pages/${postId}`) &&
          response.request().method() === 'POST'
      ),
      page.locator('.kayzart-editor-preview__edit').click(),
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
