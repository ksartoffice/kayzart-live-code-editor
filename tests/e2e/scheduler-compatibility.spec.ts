import { expect, test, type Page } from '@playwright/test';
import { adminUser, adminPass, baseUrl, login, openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

test.skip(process.env.KAYZART_SCHEDULER_E2E !== '1' || !adminUser || !adminPass, 'Requires a disposable scheduler compatibility site.');
test.setTimeout(90_000);

test.beforeEach(async ({ context }) => {
  await context.route('**/*', route => new URL(route.request().url()).host === baseUrl.host ? route.continue() : route.abort());
});

async function expectScheduler(page: Page) {
  const state = await page.evaluate(() => (window as any).KAYZART_SCHEDULER_E2E);
  expect(state).toBeTruthy();
  const [major, minor] = String(state.wpVersion).split('.').map(Number);
  const modern = major > 6 || (major === 6 && minor >= 8);
  expect(state).toMatchObject({ version: modern ? '4.0.0' : '3.6.4', bundle: modern ? 'modern' : 'legacy', store: 'ActionScheduler_DBStore' });
}

test('authenticated admin screens render with the compatible scheduler', async ({ page }) => {
  await login(page);
  for (const [path, marker] of [
    ['wp-admin/', '#dashboard-widgets-wrap'],
    ['wp-admin/admin.php?page=kayzart-new', '.kayzart-start-methods'],
    ['wp-admin/admin.php?page=kayzart-settings', 'form[action="options.php"]'],
  ]) {
    const response = await page.goto(new URL(path, baseUrl).toString());
    expect(response!.status()).toBe(200);
    await expect(page.locator('#wpadminbar')).toBeVisible();
    await expect(page.locator(marker).first()).toBeVisible();
    await expect(page.locator('#user_login')).toHaveCount(0);
    await expectScheduler(page);
  }
});

for (const order of ['before', 'after']) {
  test(`another bundled scheduler registered ${order} Kayzart preserves version selection`, async ({ page }) => {
    await page.context().addCookies([{ name: 'kayzart_scheduler_other_order', value: order, url: baseUrl.toString() }]);
    await login(page);
    const response = await page.goto(new URL('wp-admin/admin.php?page=kayzart-new', baseUrl).toString());
    expect(response!.status()).toBe(200);
    await expect(page.locator('.kayzart-start-methods')).toBeVisible();
    await expectScheduler(page);
  });
}

test('a page remains editable and saveable on the real admin runtime', async ({ page }) => {
  await login(page);
  const postId = await createTemporaryPage(page, { title: 'Temporary scheduler compatibility page', content: '<main>Scheduler compatibility</main>' });
  try {
    await openKayzartEditor(page, String(postId), 'normal');
    await page.evaluate(() => {
      const api = (window as any).KAYZART_EXTENSION_API;
      const snapshot = api.getEditorSnapshot();
      snapshot.html = '<main>Saved scheduler compatibility</main>';
      if (!api.replaceEditorSnapshot(snapshot)) throw new Error('Editor rejected the compatibility page');
    });
    const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/kayzart/v1/save'));
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    expect((await saved).ok()).toBe(true);
    await page.reload();
    await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
    expect(await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html)).toContain('Saved scheduler compatibility');
  } finally {
    await deleteTemporaryPage(page, postId);
  }
});
