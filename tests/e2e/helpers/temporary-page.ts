import { expect, type Page, type Response } from '@playwright/test';
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
  input: { title: string; content: string; author?: number }
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

/** Create through Add new and always collect the new page and its own media. */
export async function withTemporaryTemplatePage(
  page: Page,
  title: string,
  use: (postId: number) => Promise<void>,
  locale: 'ja' | 'en' = 'ja'
): Promise<void> {
  let createdId = 0;
  const capture = (response: Response) => {
    if (response.request().method() !== 'POST') return;
    const location = response.headers().location;
    if (!location) return;
    const destination = new URL(location, baseUrl);
    if (destination.searchParams.get('kayzart_entry') === 'template') {
      createdId = Number(destination.searchParams.get('post_id')) || createdId;
    }
  };
  page.on('response', capture);
  try {
    await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
    await page.locator('[data-kayzart-start="template"]').click();
    await page.locator('[data-template-language-filter]').selectOption(locale);
    const card = page.locator('.kayzart-template-card:visible');
    await card.locator('[name="post_title"]').fill(title);
    await card.locator('.kayzart-template-form button[type="submit"]').click();
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
    createdId = await page.evaluate(() => Number((window as any).KAYZART.post_id));
    expect(createdId).toBeGreaterThan(0);
    await use(createdId);
  } finally {
    page.off('response', capture);
    if (createdId) {
      await deleteTemporaryTemplatePage(page, createdId);
    }
  }
}

/** Collect a known temporary template page and its own media, including interrupted runs. */
export async function deleteTemporaryTemplatePage(page: Page, postId: number): Promise<void> {
  try {
    const nonce = await currentRestNonce(page);
    const media = await page.request.get(new URL(`wp-json/wp/v2/media?parent=${postId}&per_page=100&context=edit`, baseUrl).toString(), { headers: { 'X-WP-Nonce': nonce } });
    expect(media.ok()).toBe(true);
    for (const attachment of await media.json()) {
      const freshNonce = await currentRestNonce(page);
      const deleted = await page.request.delete(new URL(`wp-json/wp/v2/media/${attachment.id}?force=true`, baseUrl).toString(), { headers: { 'X-WP-Nonce': freshNonce } });
      expect(deleted.ok()).toBe(true);
      expect((await deleted.json()).deleted).toBe(true);
    }
  } finally {
    await deleteTemporaryPage(page, postId);
  }
}
