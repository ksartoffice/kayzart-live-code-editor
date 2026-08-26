import { expect, test, type Page, type Response } from '@playwright/test';
import {
  baseUrl,
  missingEnv,
  openKayzartEditor,
  postType,
} from './helpers/open-editor';

test.skip(missingEnv, 'Set the WordPress E2E credentials and post ID.');
test.setTimeout(120_000);

const MARKER_ID = 'kayzart-large-tailwind-e2e';
const CSS_SOURCE = '@import "tailwindcss";';
const EXPECTED_CANDIDATES = ['min-h-screen', 'bg-[#123456]', 'text-white', 'p-4'];

function postRestBase(): string {
  if (postType === 'page') return 'pages';
  if (postType === 'post') return 'posts';
  return postType;
}

function buildLargeHtml(): string {
  const padding = 'x'.repeat(1024 * 1024 + 1024);
  return (
    `<main id="${MARKER_ID}" class="min-h-screen bg-[#123456] text-white">` +
    '<p class="p-4">Large Tailwind E2E</p>' +
    `<!--${padding}--></main>`
  );
}

function isCompileResponseForMarker(response: Response): boolean {
  if (!response.url().includes('/wp-json/kayzart/v1/compile-tailwind')) return false;
  if (response.request().method() !== 'POST') return false;
  const payload = response.request().postDataJSON() as Record<string, unknown> | null;
  return Boolean(
    payload &&
      Array.isArray(payload.candidates) &&
      payload.candidates.includes('bg-[#123456]')
  );
}

async function expectSuccessfulResponse(response: Response, label: string) {
  const body = await response.text();
  expect(response.status(), `${label}: ${body.slice(0, 500)}`).toBe(200);
  const parsed = JSON.parse(body) as { ok?: boolean };
  expect(parsed.ok, `${label}: ${body.slice(0, 500)}`).toBe(true);
}

async function expectCompiledPreview(page: Page) {
  const marker = page.frameLocator('.kayzart-iframe').locator(`#${MARKER_ID}`);
  await expect(marker).toHaveCount(1, { timeout: 20_000 });
  await expect
    .poll(
      () => marker.evaluate((element) => window.getComputedStyle(element).backgroundColor),
      { timeout: 20_000 }
    )
    .toBe('rgb(18, 52, 86)');
}

test('large Tailwind HTML compiles through a compact candidate request', async ({ page }) => {
  await openKayzartEditor(page);
  const nonce = await page.evaluate(() => (window as any).KAYZART.restNonce as string);
  const seed = `${Date.now()}-${Math.floor(Math.random() * 1000)}`;
  const restBase = postRestBase();
  const createResponse = await page.request.post(
    new URL(`wp-json/wp/v2/${restBase}`, baseUrl).toString(),
    {
      headers: { 'X-WP-Nonce': nonce },
      data: {
        title: `Large Tailwind E2E ${seed}`,
        status: 'draft',
        content: '<p>Temporary E2E post</p>',
      },
    }
  );
  const createBody = await createResponse.text();
  expect(createResponse.status(), createBody.slice(0, 500)).toBe(201);
  const created = JSON.parse(createBody) as { id: number };
  const createdPostId = created.id;

  try {
    await openKayzartEditor(page, String(createdPostId), 'tailwind');
    const largeHtml = buildLargeHtml();
    expect(new TextEncoder().encode(largeHtml).byteLength).toBeGreaterThan(1024 * 1024);

    const compileResponsePromise = page.waitForResponse(isCompileResponseForMarker);
    await page.evaluate(
      ({ html, css }) => {
        (window as any).KAYZART_EXTENSION_API.replaceEditorSnapshot({
          html,
          customHead: '',
          css,
          js: '',
          jsMode: 'classic',
          baseHash: '',
          editorMode: 'tailwind',
          cssByMode: { normal: null, tailwind: css },
        });
      },
      { html: largeHtml, css: CSS_SOURCE }
    );

    const compileResponse = await compileResponsePromise;
    await expectSuccessfulResponse(compileResponse, 'initial Tailwind compile');
    const compilePayload = compileResponse.request().postDataJSON() as Record<string, unknown>;
    expect(compilePayload).not.toHaveProperty('html');
    expect(compilePayload.candidates).toEqual(EXPECTED_CANDIDATES);
    expect(JSON.stringify(compilePayload).length).toBeLessThan(10_000);
    await expectCompiledPreview(page);
  } finally {
    await page.request.delete(
      new URL(`wp-json/wp/v2/${restBase}/${createdPostId}?force=true`, baseUrl).toString(),
      { headers: { 'X-WP-Nonce': nonce } }
    );
  }
});
