import { test, expect } from '@playwright/test';
import { openKayzartEditor } from './helpers/open-editor';
import { createTemporaryPage, deleteTemporaryPage } from './helpers/temporary-page';

const adminUser = process.env.WP_ADMIN_USER ?? '';
const adminPass = process.env.WP_ADMIN_PASS ?? '';
const baseUrlRaw = process.env.WP_BASE_URL ?? 'http://localhost';
const baseUrl = (() => {
  const url = new URL(baseUrlRaw);
  if (!url.pathname.endsWith('/')) {
    url.pathname += '/';
  }
  return url;
})();

test.skip(!adminUser || !adminPass, 'Set WP_ADMIN_USER and WP_ADMIN_PASS.');
test.setTimeout(120_000);

const login = async (
  page: import('@playwright/test').Page,
  username: string,
  password: string
): Promise<void> => {
  const loginUrl = new URL('wp-login.php', baseUrl).toString();

  for (let attempt = 0; attempt < 3; attempt += 1) {
    await page.context().clearCookies();
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', username);
    await page.fill('#user_pass', password);
    await page.click('#wp-submit');
    await page.waitForLoadState('networkidle');

    const hasLoggedInCookie = (await page.context().cookies()).some((cookie) =>
      cookie.name.startsWith('wordpress_logged_in')
    );
    if (hasLoggedInCookie) {
      return;
    }

    await page.waitForTimeout(500);
  }

  throw new Error(`Failed to log in as ${username}.`);
};

const resolveEditorUrl = async (
  page: import('@playwright/test').Page,
  postId: number
): Promise<string> => {
  const postEditUrl = new URL('wp-admin/post.php', baseUrl);
  postEditUrl.searchParams.set('post', String(postId));
  postEditUrl.searchParams.set('action', 'edit');
  await page.goto(postEditUrl.toString(), { waitUntil: 'domcontentloaded' });

  const editorUrlHandle = await page
    .waitForFunction(
      (targetPostId) => {
        const bridge = (window as any).KAYZART_EDITOR;
        if (!bridge || typeof bridge.actionUrl !== 'string' || !bridge.actionUrl) {
          return null;
        }
        const url = new URL(bridge.actionUrl, window.location.origin);
        url.searchParams.set('post_id', String(targetPostId));
        return url.toString();
      },
      postId,
      { timeout: 8000 }
    )
    .catch(() => null);

  if (editorUrlHandle) {
    const editorUrl = await editorUrlHandle.jsonValue();
    if (typeof editorUrl === 'string' && editorUrl.length > 0) {
      return editorUrl;
    }
  }

  const listUrl = new URL('wp-admin/edit.php', baseUrl);
  listUrl.searchParams.set('post_type', 'kayzart');
  await page.goto(listUrl.toString(), { waitUntil: 'domcontentloaded' });
  const rowActionLink = await page.evaluate((targetPostId) => {
    const anchors = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href]'));
    for (const anchor of anchors) {
      try {
        const url = new URL(anchor.href, window.location.origin);
        if (
          url.searchParams.get('page') === 'kayzart' &&
          url.searchParams.get('post_id') === String(targetPostId) &&
          url.searchParams.get('_wpnonce')
        ) {
          return url.toString();
        }
      } catch {
        // Ignore malformed href values.
      }
    }
    return null;
  }, postId);

  if (typeof rowActionLink !== 'string' || rowActionLink.length === 0) {
    throw new Error('Failed to resolve nonce-protected KayzArt editor URL.');
  }

  return rowActionLink;
};

const openEditorAndGetNonce = async (
  page: import('@playwright/test').Page,
  postId: number
): Promise<string> => {
  const editorUrl = await resolveEditorUrl(page, postId);
  await page.goto(editorUrl, { waitUntil: 'domcontentloaded' });

  const handle = await page.waitForFunction(() => {
    const cfg = (window as any).KAYZART;
    if (!cfg || typeof cfg.restNonce !== 'string' || !cfg.restNonce) {
      return null;
    }
    return cfg.restNonce;
  });

  const nonce = await handle.jsonValue();
  if (typeof nonce !== 'string' || nonce.length === 0) {
    throw new Error('Failed to read KAYZART.restNonce from editor page.');
  }

  return nonce;
};

const createNormalManagedPage = async (
  page: import('@playwright/test').Page,
  title: string,
  author?: number
): Promise<number> => {
  await login(page, adminUser, adminPass);
  const postId = await createTemporaryPage(page, {
    title,
    content: `<p>${title}</p>`,
    ...(author ? { author } : {}),
  });
  try {
    await openKayzartEditor(page, String(postId), 'normal');
    return postId;
  } catch (error) {
    await login(page, adminUser, adminPass);
    await deleteTemporaryPage(page, postId);
    throw error;
  }
};

const deletePagesAsAdmin = async (
  page: import('@playwright/test').Page,
  postIds: number[]
): Promise<void> => {
  await login(page, adminUser, adminPass);
  for (const postId of postIds) {
    await deleteTemporaryPage(page, postId);
  }
};

const saveRequest = async (
  page: import('@playwright/test').Page,
  postId: number,
  payload: Record<string, unknown>,
  nonce?: string
) => {
  const headers: Record<string, string> = {};
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }

  return page.request.post(new URL('wp-json/kayzart/v1/save', baseUrl).toString(), {
    headers,
    data: {
      post_id: postId,
      ...payload,
    },
  });
};

const createEditorAndPage = async (
  page: import('@playwright/test').Page,
  adminNonce: string
): Promise<{ username: string; password: string; postId: number }> => {
  const seed = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  const username = `kayzart_editor_${seed}`;
  const password = `Cd!${seed}pass`;
  const email = `${username}@example.com`;

  const userResponse = await page.request.post(new URL('wp-json/wp/v2/users', baseUrl).toString(), {
    headers: {
      'X-WP-Nonce': adminNonce,
    },
    data: {
      username,
      email,
      password,
      roles: ['editor'],
    },
  });

  expect([200, 201]).toContain(userResponse.status());
  const user = await userResponse.json();
  const userId = Number(user.id);
  expect(Number.isFinite(userId)).toBe(true);

  const createdPostId = await createNormalManagedPage(page, `Editor Draft ${seed}`, userId);

  return {
    username,
    password,
    postId: createdPostId,
  };
};

test('REST rejects missing nonce for cookie auth', async ({ page }) => {
  const postId = await createNormalManagedPage(page, 'Missing nonce test');
  try {
    const cookies = await page.context().cookies();
    const hasLoggedInCookie = cookies.some((cookie) =>
      cookie.name.startsWith('wordpress_logged_in')
    );
    expect(hasLoggedInCookie).toBe(true);

    const response = await saveRequest(page, postId, {
      html: '<p>Missing nonce</p>',
    });
    expect(response.status()).toBe(401);
  } finally {
    await deletePagesAsAdmin(page, [postId]);
  }
});

test('REST rejects invalid nonce for cookie auth', async ({ page }) => {
  const postId = await createNormalManagedPage(page, 'Invalid nonce test');
  try {
    const nonce = 'invalid-rest-nonce';
    const response = await saveRequest(page, postId, { html: '<p>Invalid nonce</p>' }, nonce);
    expect(response.status()).toBe(403);
  } finally {
    await deletePagesAsAdmin(page, [postId]);
  }
});

test('REST accepts valid nonce for admin without JS payload', async ({ page }) => {
  const postId = await createNormalManagedPage(page, 'Admin no JS test');
  try {
    const nonce = await openEditorAndGetNonce(page, postId);
    const response = await saveRequest(
      page,
      postId,
      {
        html: '<p>Admin no JS</p>',
        css: 'body{color:#333;}',
      },
      nonce
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.ok).toBe(true);
  } finally {
    await deletePagesAsAdmin(page, [postId]);
  }
});

test('REST accepts valid nonce for admin with JS payload', async ({ page }) => {
  const postId = await createNormalManagedPage(page, 'Admin with JS test');
  try {
    const nonce = await openEditorAndGetNonce(page, postId);
    const response = await saveRequest(
      page,
      postId,
      {
        html: '<p>Admin with JS</p>',
        js: 'console.log("admin-ok");',
      },
      nonce
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.ok).toBe(true);
  } finally {
    await deletePagesAsAdmin(page, [postId]);
  }
});

test('REST allows editor save with valid nonce when JS is omitted', async ({ page }) => {
  const adminPostId = await createNormalManagedPage(page, 'Editor setup test');
  let editorPostId: number | null = null;
  try {
    const adminNonce = await openEditorAndGetNonce(page, adminPostId);
    const editor = await createEditorAndPage(page, adminNonce);
    editorPostId = editor.postId;
    await login(page, editor.username, editor.password);
    const editorNonce = await openEditorAndGetNonce(page, editor.postId);

    const response = await saveRequest(
      page,
      editor.postId,
      {
        html: '<p>Editor no JS</p>',
        css: 'body{background:#fff;}',
      },
      editorNonce
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.ok).toBe(true);
  } finally {
    await deletePagesAsAdmin(page, [...(editorPostId !== null ? [editorPostId] : []), adminPostId]);
  }
});

test('REST allows editor JS save with valid nonce', async ({ page }) => {
  const adminPostId = await createNormalManagedPage(page, 'Editor JS setup test');
  let editorPostId: number | null = null;
  try {
    const adminNonce = await openEditorAndGetNonce(page, adminPostId);
    const editor = await createEditorAndPage(page, adminNonce);
    editorPostId = editor.postId;
    await login(page, editor.username, editor.password);
    const editorNonce = await openEditorAndGetNonce(page, editor.postId);

    const response = await saveRequest(
      page,
      editor.postId,
      {
        html: '<p>Editor JS allowed</p>',
        js: 'console.log("allowed");',
      },
      editorNonce
    );

    expect(response.status()).toBe(200);
    const data = await response.json();
    expect(data.ok).toBe(true);
  } finally {
    await deletePagesAsAdmin(page, [...(editorPostId !== null ? [editorPostId] : []), adminPostId]);
  }
});
