import { expect, test, type Page } from '@playwright/test';

const adminUser = process.env.WP_ADMIN_USER ?? '';
const adminPass = process.env.WP_ADMIN_PASS ?? '';
const postId = process.env.KAYZART_POST_ID ?? '';
const baseUrl = new URL(process.env.WP_BASE_URL ?? 'http://localhost');
if (!baseUrl.pathname.endsWith('/')) baseUrl.pathname += '/';

test.skip(!adminUser || !adminPass || !postId, 'Set the WordPress E2E credentials and post ID.');

async function openEditor(page: Page) {
  await page.goto(new URL('wp-login.php', baseUrl).toString());
  await page.fill('#user_login', adminUser);
  await page.fill('#user_pass', adminPass);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');

  const editUrl = new URL('wp-admin/post.php', baseUrl);
  editUrl.searchParams.set('post', postId);
  editUrl.searchParams.set('action', 'edit');
  await page.goto(editUrl.toString(), { waitUntil: 'domcontentloaded' });
  const actionUrl = await page.evaluate((targetPostId) => {
    const raw = (window as any).KAYZART_EDITOR?.actionUrl;
    if (!raw) return '';
    const url = new URL(raw, window.location.origin);
    url.searchParams.set('post_id', targetPostId);
    return url.toString();
  }, postId);
  if (!actionUrl) throw new Error('Kayzart editor URL was not available for the configured post.');
  await page.goto(actionUrl, { waitUntil: 'networkidle' });
  await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
}

test('loads one free AI entry point and the Phase 3 REST configuration', async ({ page }) => {
  await openEditor(page);

  const ai = await page.evaluate(() => (window as any).KAYZART?.ai);
  expect(ai).toMatchObject({
    available: true,
    canEdit: true,
    featureEnabled: true,
    sdkPresent: true,
    providerConfigured: true,
    schedulerPresent: true,
  });
  expect(ai.jobsUrl).toContain('/wp-json/kayzart/v1/ai/jobs');
  await expect(page.locator('script[src*="/assets/dist/ai-editor.js"]')).toHaveCount(1);

  const toolbarButton = page.getByRole('button', { name: 'AI Edit', exact: true }).first();
  await expect(toolbarButton).toBeVisible();
  await toolbarButton.click();
  await expect(page.locator('.kayzart-settingsTab').filter({ hasText: 'AI Edit' })).toHaveCount(1);
  await expect(page.locator('.kayzart-ai-panel')).toBeVisible();
  await expect(page.locator('.kayzart-ai-panel')).not.toContainText(/credits|license|model/i);
});

test('opening the AI panel does not horizontally shift the editor shell', async ({ page }) => {
  await openEditor(page);

  await page.getByRole('button', { name: 'AI Edit', exact: true }).first().click();
  await expect(page.locator('.kayzart-ai-composer textarea')).toBeVisible();
  await page.waitForTimeout(350);

  const layout = await page.evaluate(() => {
    const app = document.querySelector<HTMLElement>('.kayzart-app');
    const main = document.querySelector<HTMLElement>('.kayzart-main');
    if (!app || !main) throw new Error('Editor shell is unavailable.');
    return {
      appScrollLeft: app.scrollLeft,
      appOverflow: app.scrollWidth - app.clientWidth,
      mainLeft: main.getBoundingClientRect().left,
    };
  });

  expect(layout).toEqual({ appScrollLeft: 0, appOverflow: 0, mainLeft: 0 });
});

test('completes one real AI edit without saving the post automatically', async ({ page }) => {
  test.skip(process.env.KAYZART_RUN_AI_E2E !== '1', 'Set KAYZART_RUN_AI_E2E=1 for the one-call provider check.');
  test.setTimeout(620_000);
  await openEditor(page);
  const before = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());

  await page.getByRole('button', { name: 'AI Edit', exact: true }).first().click();
  await page.locator('.kayzart-ai-panel textarea').fill(
    'Append the HTML comment <!-- kayzart phase 4 verified --> at the very end of the HTML. Do not change CSS or JavaScript.'
  );
  await page.locator('.kayzart-ai-composer-footer button').click();
  await expect(page.locator('.kayzart-ai-result')).toBeVisible({ timeout: 600_000 });

  const after = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
  expect(after.html).toContain('<!-- kayzart phase 4 verified -->');
  expect(after.baseHash).not.toBe(before.baseHash);

  const actions = page.locator('.kayzart-ai-result-actions button');
  await actions.nth(0).click();
  expect((await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).html).toBe(before.html);
  await actions.nth(1).click();
  expect((await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).html).toBe(after.html);

  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
  expect((await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).html)
    .not.toContain('<!-- kayzart phase 4 verified -->');
});

test('restores and applies a previously completed active job', async ({ page }) => {
  const jobId = process.env.KAYZART_RESTORE_AI_JOB_ID ?? '';
  test.skip(!jobId, 'Set KAYZART_RESTORE_AI_JOB_ID to verify sessionStorage recovery without another provider call.');
  await openEditor(page);
  const inputSnapshot = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
  await page.evaluate(({ targetPostId, targetJobId, snapshot }) => {
    const base = (window as any).KAYZART.ai.jobsBaseUrl;
    sessionStorage.setItem(`kayzart.ai.activeJob.${targetPostId}`, JSON.stringify({
      version: 1,
      postId: Number(targetPostId),
      jobId: targetJobId,
      requestId: `restore-${targetJobId}`,
      statusUrl: `${base}${targetJobId}`,
      cancelUrl: `${base}${targetJobId}/cancel`,
      pollIntervalMs: 1,
      timeoutMs: 600000,
      startedAt: Date.now(),
      prompt: 'Restore completed Phase 4 verification job',
      contexts: [],
      inputSnapshot: snapshot,
    }));
  }, { targetPostId: postId, targetJobId: jobId, snapshot: inputSnapshot });

  await page.reload({ waitUntil: 'networkidle' });
  await expect(page.locator('.kayzart-ai-result')).toBeVisible();
  expect((await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).html)
    .toContain('<!-- kayzart phase 4 verified -->');
  expect(await page.evaluate((targetPostId) => sessionStorage.getItem(`kayzart.ai.activeJob.${targetPostId}`), postId))
    .toBeNull();
});

test('continues after parallel OpenAI tool calls and applies the result', async ({ page }) => {
  test.skip(
    process.env.KAYZART_RUN_AI_PARALLEL_E2E !== '1',
    'Set KAYZART_RUN_AI_PARALLEL_E2E=1 for the paid parallel tool-call regression check.'
  );
  test.setTimeout(620_000);
  let latestStatus: any = null;
  page.on('response', async (response) => {
    if (response.request().method() !== 'GET' || !response.url().includes('/wp-json/kayzart/v1/ai/jobs/')) return;
    latestStatus = await response.json().catch(() => latestStatus);
  });

  await openEditor(page);
  const before = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
  await page.getByRole('button', { name: 'AI Edit', exact: true }).first().click();
  await page.locator('.kayzart-ai-panel textarea').fill('お客様の声セクションを追加してください。');
  await page.locator('.kayzart-ai-composer-footer button').click();
  await expect(page.locator('.kayzart-ai-result')).toBeVisible({ timeout: 600_000 });

  const after = await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot());
  expect(after.html).not.toBe(before.html);
  const events = Array.isArray(latestStatus?.events) ? latestStatus.events : [];
  const secondTurn = events.findIndex((event: any) => event.event === 'progress' && event.message === 'AI turn 2/15');
  expect(secondTurn).toBeGreaterThan(0);
  expect(events.slice(0, secondTurn).filter((event: any) => event.event === 'tool_start').length).toBeGreaterThan(1);

  await page.reload({ waitUntil: 'networkidle' });
  await page.waitForFunction(() => Boolean((window as any).KAYZART_EXTENSION_API?.getEditorSnapshot));
  expect((await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot())).html).toBe(before.html);
});
