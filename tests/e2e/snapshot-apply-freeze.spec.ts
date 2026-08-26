import { expect, test, type Page } from '@playwright/test';
import { buildAiSnapshot, TAILWIND_CSS_SOURCE } from './fixtures/large-tailwind-page';
import { missingEnv, openKayzartEditor } from './helpers/open-editor';
import { fetchStoredAiSnapshot } from './helpers/timeline-snapshot';

/**
 * Reproduces the editor freeze without spending anything on the AI provider.
 *
 * The AI job, the REST handoff and the server are all irrelevant to the hang:
 * it starts when `replaceEditorSnapshot` puts a large Tailwind document into
 * the editors. That entry point is already on the public extension API, so this
 * spec applies the same payload directly and then checks that the renderer
 * still runs tasks. A microtask loop starves the task queue forever, so a timer
 * that never fires again is a reliable, fast signal.
 */

test.skip(missingEnv, 'Set the WordPress E2E credentials and post ID.');
test.setTimeout(120_000);

/**
 * Put the editor into the exact state the AI handoff applies into: the AI tab
 * open (which collapses the editor panes), the editors locked for the running
 * job, and an empty document. Every reported freeze started from an empty post,
 * and a collapsed pane changes how CodeMirror measures itself.
 */
async function enterAiHandoffState(page: Page) {
  await page.evaluate((css) => {
    const api = (window as any).KAYZART_EXTENSION_API;
    api.replaceEditorSnapshot({
      html: '',
      customHead: '',
      css,
      js: '',
      jsMode: 'classic',
      baseHash: '',
    });
    api.openSettingsTab('kayzart-ai');
    api.setEditorLock(true);
  }, TAILWIND_CSS_SOURCE);
  await page.waitForTimeout(500);
}

/** Start a self-rescheduling timer whose tick count proves tasks still run. */
async function startTaskProbe(page: Page) {
  await page.evaluate(() => {
    const scope = window as any;
    scope.__kayzartTaskTicks = 0;
    const tick = () => {
      scope.__kayzartTaskTicks += 1;
      scope.setTimeout(tick, 50);
    };
    scope.setTimeout(tick, 50);
  });
}

/**
 * Apply from inside a timer so the evaluate call returns before the freeze can
 * begin. Awaiting the apply itself would hang along with the renderer.
 */
async function applySnapshotDetached(page: Page, snapshot: unknown, aiHandoff = false) {
  await page.evaluate(({ payload, aiHandoff }) => {
    window.setTimeout(() => {
      const api = (window as any).KAYZART_EXTENSION_API;
      if (aiHandoff) {
        api.applyAiEditorSnapshot(payload);
      } else {
        api.replaceEditorSnapshot(payload);
      }
    }, 0);
  }, { payload: snapshot, aiHandoff });
}

/** A frozen renderer never answers, so cap the wait instead of hanging. */
function withDeadline<T>(work: Promise<T>, ms: number, fallback: T): Promise<T> {
  return Promise.race([
    work.catch(() => fallback),
    new Promise<T>((resolve) => setTimeout(() => resolve(fallback), ms)),
  ]);
}

async function expectMainThreadAlive(page: Page, label: string) {
  const baseline = await withDeadline(
    page.evaluate(() => (window as any).__kayzartTaskTicks as number),
    5_000,
    -1
  );
  if (baseline < 0) {
    throw new Error(
      `${label}: the main thread stopped running tasks after applying the snapshot. ` +
        'This is the microtask-starvation freeze.'
    );
  }
  try {
    await page.waitForFunction(
      (previous) => ((window as any).__kayzartTaskTicks as number) > previous + 5,
      baseline,
      { timeout: 10_000 }
    );
  } catch {
    throw new Error(
      `${label}: the main thread stopped running tasks after applying the snapshot. ` +
        'This is the microtask-starvation freeze.'
    );
  }
}

async function expectSnapshotApplied(page: Page, expectedHtml: string) {
  const actualHtml = await page.evaluate(
    () => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html as string
  );
  expect(actualHtml).toBe(expectedHtml);
}

test('applying a large AI snapshot keeps the editor main thread responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await startTaskProbe(page);

  await applySnapshotDetached(page, buildAiSnapshot(3));
  await expectMainThreadAlive(page, 'single apply');

  const html = await page.evaluate(
    () => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html as string
  );
  expect(html).toContain('リンゴの販売ページ');
});

test('repeated snapshot applies stay responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await startTaskProbe(page);

  // The freeze is intermittent, so apply several differently sized documents.
  for (const sections of [2, 4, 3, 6, 3]) {
    await applySnapshotDetached(page, buildAiSnapshot(sections));
    await expectMainThreadAlive(page, `apply with ${sections} sections`);
  }
});

test('a very large snapshot still keeps the main thread responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await startTaskProbe(page);

  await applySnapshotDetached(page, buildAiSnapshot(12));
  await expectMainThreadAlive(page, 'oversized apply');
});

test('the full AI handoff sequence keeps the main thread responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await enterAiHandoffState(page);
  await startTaskProbe(page);

  const snapshot = buildAiSnapshot(3);
  await applySnapshotDetached(page, snapshot, true);
  await expectMainThreadAlive(page, 'AI handoff apply');
  await expectSnapshotApplied(page, snapshot.html);
});

test('repeated AI handoff applies stay responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await startTaskProbe(page);

  for (const sections of [3, 5, 3, 7]) {
    const snapshot = buildAiSnapshot(sections);
    await enterAiHandoffState(page);
    await applySnapshotDetached(page, snapshot, true);
    await expectMainThreadAlive(page, `AI handoff apply with ${sections} sections`);
    await expectSnapshotApplied(page, snapshot.html);
  }
});

test('replaying the stored AI snapshot keeps the main thread responsive', async ({ page }) => {
  await openKayzartEditor(page);

  const stored = await fetchStoredAiSnapshot(page, Number(process.env.KAYZART_ACTIVITY_ID ?? 0));
  test.skip(!stored, 'No completed AI edit is stored for this post yet.');
  await startTaskProbe(page);

  // Replay it repeatedly: the hang is intermittent, and this is the exact
  // payload shape that produced it in production.
  for (let attempt = 1; attempt <= 5; attempt += 1) {
    await enterAiHandoffState(page);
    await applySnapshotDetached(page, stored, true);
    await expectMainThreadAlive(page, `stored snapshot replay #${attempt}`);
    await expectSnapshotApplied(page, stored.html);
  }
});

test('a snapshot containing an astral emoji keeps the main thread responsive', async ({ page }) => {
  await openKayzartEditor(page);
  await enterAiHandoffState(page);
  await startTaskProbe(page);

  // U+1F34E is a surrogate pair. Offsets measured in code points instead of
  // UTF-16 code units land inside it, and CodeMirror can then never reconcile
  // its DOM with the document, which starves the microtask queue.
  const snapshot = {
    html: '<div class="text-8xl" aria-hidden="true">\u{1F34E}</div>',
    customHead: '',
    css: TAILWIND_CSS_SOURCE,
    js: '',
    jsMode: 'classic',
    baseHash: '',
  };
  await applySnapshotDetached(page, snapshot, true);
  await expectMainThreadAlive(page, 'astral emoji apply');
  await expectSnapshotApplied(page, snapshot.html);
});

test('a locked editor still refuses user edits', async ({ page }) => {
  await openKayzartEditor(page);
  await page.evaluate(() => {
    (window as any).KAYZART_EXTENSION_API.replaceEditorSnapshot({
      html: '<div>locked</div>', customHead: '', css: '', js: '',
      jsMode: 'classic', baseHash: '',
    });
  });

  const before = await page.evaluate(
    () => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html as string
  );
  await page.evaluate(() => (window as any).KAYZART_EXTENSION_API.setEditorLock(true));

  const content = page.locator('.cm-content').first();
  await content.click({ force: true }).catch(() => undefined);
  await page.keyboard.type('XXX');
  await page.keyboard.press('Enter');

  const after = await page.evaluate(
    () => (window as any).KAYZART_EXTENSION_API.getEditorSnapshot().html as string
  );
  if (after !== before) {
    throw new Error(`Locked editor accepted a user edit: ${JSON.stringify(after)}`);
  }
});

test('the AI lock blocks user snapshots and shows a non-blocking preview status', async ({ page }) => {
  await openKayzartEditor(page);
  const result = await page.evaluate(() => {
    const api = (window as any).KAYZART_EXTENSION_API;
    const before = {
      html: '<main>Before</main>', customHead: '', css: '', js: '',
      jsMode: 'classic', baseHash: 'before',
    };
    const user = { ...before, html: '<main>User</main>', baseHash: 'user' };
    const ai = { ...before, html: '<main>AI</main>', baseHash: 'ai' };
    api.replaceEditorSnapshot(before);
    api.setEditorLock(true);
    const userApplied = api.replaceEditorSnapshot(user);
    const afterUser = api.getEditorSnapshot().html;
    const aiApplied = api.applyAiEditorSnapshot(ai);
    const afterAi = api.getEditorSnapshot().html;
    const status = document.querySelector('.kayzart-previewAiStatus');
    const visible = status?.classList.contains('is-visible');
    const pointerEvents = status ? getComputedStyle(status).pointerEvents : '';
    api.setEditorLock(false);
    return { userApplied, afterUser, aiApplied, afterAi, visible, pointerEvents, hiddenAfter: status?.getAttribute('aria-hidden') };
  });

  expect(result).toEqual({
    userApplied: false,
    afterUser: '<main>Before</main>',
    aiApplied: true,
    afterAi: '<main>AI</main>',
    visible: true,
    pointerEvents: 'none',
    hiddenAfter: 'true',
  });
});
