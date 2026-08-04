import { expect, test, type Page } from '@playwright/test';
import { missingEnv, openKayzartEditor } from './helpers/open-editor';

/**
 * Astral characters (emoji) occupy two UTF-16 code units. Any place that mixes
 * code-point counting with code-unit offsets will drift by one per emoji, so
 * these tests check the editor in its normal, unlocked state: text must survive
 * a round trip and element source ranges must still point at the right markup.
 */

test.skip(missingEnv, 'Set the WordPress E2E credentials and post ID.');
test.setTimeout(120_000);

const APPLE = '\u{1F34E}';

async function applySnapshot(page: Page, html: string) {
  await page.evaluate((htmlText) => {
    (window as any).KAYZART_EXTENSION_API.replaceEditorSnapshot({
      html: htmlText,
      customHead: '',
      css: '',
      js: '',
      jsMode: 'classic',
      baseHash: '',
    });
  }, html);
  await page.waitForTimeout(300);
}

const readHtml = (page: Page) =>
  page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html as string);

test('an emoji survives a snapshot round trip unchanged', async ({ page }) => {
  await openKayzartEditor(page);
  const html = `<p>fresh ${APPLE} apples</p>`;
  await applySnapshot(page, html);

  expect(await readHtml(page)).toBe(html);
});

test('element source ranges stay correct after an emoji', async ({ page }) => {
  await openKayzartEditor(page);
  // The emoji sits before the target, so a code-point/code-unit mix-up shifts
  // every following offset and the slice stops matching the element.
  await applySnapshot(page, `<p>${APPLE}${APPLE}${APPLE}</p><div id="target">TARGET</div>`);

  const result = await page.evaluate(() => {
    const api = (window as any).KAYZART_EXTENSION_API;
    const html = api.getEditorSnapshot().html as string;
    for (let index = 1; index <= 6; index += 1) {
      const context = api.getElementContext(`kayzart-${index}`);
      if (context?.attributes?.some((attr: any) => attr.name === 'id' && attr.value === 'target')) {
        return { html, context };
      }
    }
    return { html, context: null };
  });

  expect(result.context, 'the target element was not found in the source map').toBeTruthy();
  const { startOffset, endOffset } = result.context.sourceRange;
  expect(result.html.slice(startOffset, endOffset)).toBe(result.context.outerHTML);
  expect(result.context.outerHTML).toContain('TARGET');
});

test('typing an emoji into the editor keeps the document intact', async ({ page }) => {
  await openKayzartEditor(page);
  await applySnapshot(page, '<p>start</p>');

  // The preview iframe covers part of the editor, so focus directly instead of
  // clicking through it.
  const content = page.locator('.cm-content').first();
  await content.focus();
  await page.keyboard.press('Control+End');
  await page.keyboard.insertText(`<p>${APPLE}</p>`);
  await page.waitForTimeout(500);

  const html = await readHtml(page);
  expect(html).toContain(APPLE);
  expect(html).toContain('<p>start</p>');
});
