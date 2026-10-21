import { expect, test, type Page } from '@playwright/test';
import { adminUser, adminPass, baseUrl, login } from './helpers/open-editor';

test.skip(process.env.KAYZART_DEACTIVATION_E2E !== '1' || !adminUser || !adminPass || !['127.0.0.1', 'localhost'].includes(baseUrl.hostname), 'Requires the disposable local deactivation fixture.');
const row = (page: Page) => page.locator('tr[data-plugin="kayzart-live-code-editor/kayzart-live-code-editor.php"]');
const modal = (page: Page) => page.locator('#kayzart-deactivation-dialog');
test.beforeEach(async ({ page, context }) => {
  await context.route('**/*', (route) => new URL(route.request().url()).host === baseUrl.host ? route.continue() : route.abort());
  await login(page);
  await page.goto(new URL('wp-admin/plugins.php', baseUrl).toString());
  await expect(page.locator('#kayzart-e2e-delivery')).toHaveCount(1);
  await expect(row(page).locator('.deactivate a')).toHaveCount(1);
});
test.afterEach(async ({ page }) => {
  // Restore even after a failed assertion or a real deactivation.
  await page.goto(new URL('wp-admin/plugins.php', baseUrl).toString());
  const activate = row(page).locator('.activate a');
  if (await activate.count()) await activate.click();
  await expect(row(page).locator('.deactivate a')).toHaveCount(1);
});

test('new installation: open, keyboard cancel, empty answer and skip send nothing', async ({ page }) => {
  const before = await page.locator('#kayzart-e2e-delivery').getAttribute('data-count');
  const requests: string[] = [];
  page.on('request', (r) => { if (r.url().includes('admin-ajax.php')) requests.push(r.url()); });
  const trigger = row(page).locator('.deactivate a');
  await trigger.click();
  await expect(modal(page)).toBeVisible();
  await expect(modal(page).locator('input:checked')).toHaveCount(0);
  await modal(page).getByRole('button', { name: 'Cancel', exact: true }).focus();
  await page.keyboard.press('Tab');
  await expect(modal(page).locator('input[type="radio"]').first()).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(modal(page).getByRole('button', { name: 'Cancel', exact: true })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(modal(page)).not.toBeVisible();
  await expect(trigger).toBeFocused();
  await trigger.click();
  await modal(page).getByRole('button', { name: 'Send answer and deactivate', exact: true }).click();
  await expect(modal(page).getByRole('alert')).toContainText('Choose a reason');
  await modal(page).getByRole('button', { name: 'Cancel', exact: true }).click();
  await trigger.click();
  await modal(page).getByRole('button', { name: 'Deactivate without sending', exact: true }).click();
  await expect(row(page).locator('.activate a')).toHaveCount(1);
  await expect(page.locator('#kayzart-e2e-delivery')).toHaveAttribute('data-count', before!);
  expect(requests).toEqual([]);
});

for (const mode of ['success', 'closed', 'malformed']) {
  test(`explicit answer deactivates after ${mode} delivery`, async ({ page, context }) => {
    await context.addCookies([{ name: 'kayzart_delivery_mode', value: mode, url: baseUrl.toString() }]);
    const before = Number(await page.locator('#kayzart-e2e-delivery').getAttribute('data-count'));
    await row(page).locator('.deactivate a').click();
    await modal(page).locator('[value="cost"]').check();
    await modal(page).locator('textarea').fill('Concerned about cost');
    await modal(page).getByRole('button', { name: 'Send answer and deactivate', exact: true }).click();
    await expect(row(page).locator('.activate a')).toHaveCount(1);
    await expect(page.locator('#kayzart-e2e-delivery')).toHaveAttribute('data-count', String(before + 1));
    const payload = JSON.parse((await page.locator('#kayzart-e2e-delivery').getAttribute('data-payload'))!);
    expect(Object.keys(payload).sort()).toEqual(['comment', 'locale', 'plugin_version', 'reason', 'submission_id', 'survey_version']);
    expect(payload.reason).toBe('cost');
    expect(payload.plugin_version).toBe('3.4.3');
  });
}

test('unresponsive AJAX cannot keep the plugin active beyond five seconds', async ({ page }) => {
  let ajaxStarted = 0;
  let deactivationStarted = 0;
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.pathname.endsWith('/admin-ajax.php')) ajaxStarted = Date.now();
    if (url.pathname.endsWith('/plugins.php') && url.searchParams.get('action') === 'deactivate') deactivationStarted = Date.now();
  });
  await page.route('**/admin-ajax.php', () => { /* Deliberately leave the request pending. */ });
  await row(page).locator('.deactivate a').click();
  await modal(page).locator('textarea').fill('Comment-only answer');
  await modal(page).getByRole('button', { name: 'Send answer and deactivate', exact: true }).click();
  await expect.poll(() => deactivationStarted, { timeout: 6500 }).toBeGreaterThan(0);
  expect(ajaxStarted).toBeGreaterThan(0);
  expect(deactivationStarted - ajaxStarted).toBeGreaterThanOrEqual(4800);
  expect(deactivationStarted - ajaxStarted).toBeLessThan(6500);
  await expect(row(page).locator('.activate a')).toHaveCount(1, { timeout: 8000 });
});
