import { expect, test, type Locator } from '@playwright/test';
import { adminPass, adminUser, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(!adminUser || !adminPass, 'Set the WordPress E2E credentials.');
test.setTimeout(90_000);

async function expectCompiledStyles(probe: Locator) {
  await expect(probe).toHaveCSS('background-color', 'rgb(18, 52, 86)');
  await expect(probe).toHaveCSS('padding-left', '8px');
  await expect(probe).toHaveCSS('flex-grow', '1');
  await expect(probe).toHaveCSS('flex-shrink', '0');
  await expect(probe).toHaveCSS('max-width', '1280px');
  await expect(probe).toHaveCSS('font-family', /Noto Sans JP/);
}

test('Tailwind candidates keep their styles in preview, after save, and on the frontend', async ({ page, context }) => {
  await login(page);
  const postId = await createTemporaryPage(page, { title: 'Temporary Tailwind compiler page', content: '<main>Tailwind compiler</main>' });
  try {
    await openKayzartEditor(page, String(postId), 'tailwind');
    const html = `<main id="tailwind-upgrade-probe" class="has-data-[slot=icon]:pl-2 bg-brand flex-grow flex-shrink-0 max-w-screen-xl font-['Noto_Sans_JP']"><span data-slot="icon">Tailwind compiler</span></main>`;
    const css = '@import "tailwindcss"; @theme { --color-brand: #123456; --spacing: 4px; --breakpoint-xl: 1280px; }';
    const compiled = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/kayzart/v1/compile-tailwind'));
    await page.evaluate(({ html, css }) => {
      const api = (window as any).KAYZART_EXTENSION_API;
      const snapshot = api.getEditorSnapshot();
      if (!api.replaceEditorSnapshot({ ...snapshot, html, css, editorMode: 'tailwind', cssByMode: { normal: null, tailwind: css } })) {
        throw new Error('Editor rejected the Tailwind snapshot');
      }
    }, { html, css });
    const compileResponse = await compiled;
    expect(compileResponse.ok()).toBe(true);
    expect(compileResponse.request().postDataJSON().candidates).toContain("font-['Noto_Sans_JP']");
    await expectCompiledStyles(page.frameLocator('.kayzart-iframe').locator('#tailwind-upgrade-probe'));

    const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/kayzart/v1/save'));
    await page.getByRole('button', { name: /^(Save draft|下書き保存)$/ }).click();
    expect((await saved).ok()).toBe(true);
    await page.reload();
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
    expect(await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html)).toBe(html);
    await expectCompiledStyles(page.frameLocator('.kayzart-iframe').locator('#tailwind-upgrade-probe'));

    const frontend = await context.newPage();
    try {
      const url = new URL(`?page_id=${postId}&preview=true`, baseUrl);
      expect((await frontend.goto(url.toString()))?.ok()).toBe(true);
      await expectCompiledStyles(frontend.locator('#tailwind-upgrade-probe'));
    } finally {
      await frontend.close();
    }
  } finally {
    await deleteTemporaryPage(page, postId);
  }
});
