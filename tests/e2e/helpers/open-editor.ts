import type { Page } from '@playwright/test';

/**
 * Opens a post in the Kayzart editor regardless of its post type.
 *
 * The editor screen itself is nonce-protected and `window.KAYZART_EDITOR` only
 * exists on posts Kayzart already manages, so neither can bootstrap a plain page
 * or post. The conversion screen can: it needs no nonce to render, it accepts
 * any editor-enabled post type, and it either redirects straight to the editor
 * (already managed) or offers a nonce-protected form that does (not yet
 * managed). Scraping the post list would break on pagination, so it is avoided.
 */

export const adminUser = process.env.WP_ADMIN_USER ?? '';
export const adminPass = process.env.WP_ADMIN_PASS ?? '';
export const postId = process.env.KAYZART_POST_ID ?? '';
export const postType = process.env.KAYZART_POST_TYPE ?? 'page';

export const baseUrl = (() => {
  const url = new URL(process.env.WP_BASE_URL ?? 'http://localhost');
  if (!url.pathname.endsWith('/')) url.pathname += '/';
  return url;
})();

export const missingEnv = !adminUser || !adminPass || !postId;

export async function login(page: Page) {
  await page.goto(new URL('wp-login.php', baseUrl).toString());
  await page.fill('#user_login', adminUser);
  await page.fill('#user_pass', adminPass);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
}

function convertUrl(targetPostId: string) {
  const url = new URL('wp-admin/admin.php', baseUrl);
  url.searchParams.set('page', 'kayzart-convert');
  url.searchParams.set('post_id', targetPostId);
  return url.toString();
}

const editorReady = () =>
  Boolean((window as any).KAYZART_EXTENSION_API?.replaceEditorSnapshot);

export async function openKayzartEditor(
  page: Page,
  targetPostId = postId,
  setupMode?: 'normal' | 'tailwind'
) {
  await login(page);
  await page.goto(convertUrl(targetPostId), { waitUntil: 'domcontentloaded' });

  if (!(await page.evaluate(editorReady).catch(() => false))) {
    // Not managed yet: confirm the conversion, which redirects into the editor.
    const submit = page.locator('form[action*="admin.php"] [type="submit"]').first();
    const openLink = page.locator('a[href*="page=kayzart&post_id="]').first();
    if ((await submit.count()) > 0) {
      if (setupMode) {
        await page.locator(`input[name="mode"][value="${setupMode}"]`).check();
      }
      await submit.click();
    } else if ((await openLink.count()) > 0) {
      await openLink.click();
    } else {
      const body = await page.locator('#wpbody-content').innerText().catch(() => '');
      throw new Error(
        `Could not open post ${targetPostId} in the Kayzart editor. ` +
          'Check KAYZART_POST_ID, and that its post type is enabled in the Kayzart settings. ' +
          `Screen said: ${body.slice(0, 200)}`
      );
    }
    await page.waitForLoadState('networkidle');
  }

  await page.waitForFunction(editorReady, undefined, { timeout: 20_000 });
}
