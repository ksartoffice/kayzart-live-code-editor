import { expect, type Page } from '@playwright/test';
import { baseUrl } from './open-editor';

async function currentRestNonce(page: Page): Promise<string> {
  const readNonce = () =>
    page.evaluate(() => {
      const kayzartNonce = (window as any).KAYZART?.restNonce;
      const coreNonce = (window as any).wpApiSettings?.nonce;
      return String(kayzartNonce || coreNonce || '');
    });

  let nonce = await readNonce().catch(() => '');
  if (!nonce) {
    await page.goto(new URL('wp-admin/post-new.php?post_type=page', baseUrl).toString(), {
      waitUntil: 'domcontentloaded',
    });
    nonce = await readNonce();
  }
  expect(nonce, 'A current WordPress REST nonce should be available.').not.toBe('');
  return nonce;
}

export async function createTemporaryPage(
  page: Page,
  input: { title: string; content: string }
): Promise<number> {
  const nonce = await currentRestNonce(page);
  const response = await page.request.post(new URL('wp-json/wp/v2/pages', baseUrl).toString(), {
    headers: { 'X-WP-Nonce': nonce },
    data: { ...input, status: 'draft' },
  });
  const body = await response.text();
  expect(response.status(), body.slice(0, 500)).toBe(201);
  return (JSON.parse(body) as { id: number }).id;
}

export async function deleteTemporaryPage(page: Page, postId: number): Promise<void> {
  const nonce = await currentRestNonce(page);
  const response = await page.request.delete(
    new URL(`wp-json/wp/v2/pages/${postId}?force=true`, baseUrl).toString(),
    { headers: { 'X-WP-Nonce': nonce } }
  );
  const body = await response.text();
  expect(response.status(), body.slice(0, 500)).toBe(200);
  expect((JSON.parse(body) as { deleted?: boolean }).deleted).toBe(true);
}
