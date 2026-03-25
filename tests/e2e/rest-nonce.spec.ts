import { test, expect } from '@playwright/test';

const adminUser = process.env.WP_ADMIN_USER ?? '';
const adminPass = process.env.WP_ADMIN_PASS ?? '';
const postIdRaw = process.env.KAYZART_POST_ID ?? '';
const baseUrlRaw = process.env.WP_BASE_URL ?? 'http://localhost';
const baseUrl = (() => {
  const url = new URL(baseUrlRaw);
  if (!url.pathname.endsWith('/')) {
    url.pathname += '/';
  }
  return url;
})();

test.skip(
  !adminUser || !adminPass || !postIdRaw,
  'Set WP_ADMIN_USER, WP_ADMIN_PASS, and KAYZART_POST_ID.'
);

const login = async (
  page: import('@playwright/test').Page,
  username: string,
  password: string
): Promise<void> => {
  await page.context().clearCookies();
  const loginUrl = new URL('wp-login.php', baseUrl).toString();
  await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', username);
  await page.fill('#user_pass', password);
  await page.click('#wp-submit');
  await page.waitForLoadState('networkidle');
};

const openEditorAndGetNonce = async (
  page: import('@playwright/test').Page,
  postId: number
): Promise<string> => {
  const adminUrl = new URL('wp-admin/admin.php', baseUrl);
  adminUrl.searchParams.set('page', 'kayzart');
  adminUrl.searchParams.set('post_id', String(postId));
  await page.goto(adminUrl.toString(), { waitUntil: 'domcontentloaded' });

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

const createAuthorAndPost = async (
  page: import('@playwright/test').Page,
  adminNonce: string
): Promise<{ username: string; password: string; postId: number }> => {
  const seed = `${Date.now()}${Math.floor(Math.random() * 1000)}`;
  const username = `kayzart_author_${seed}`;
  const password = `Cd!${seed}pass`;
  const email = `${username}@example.com`;

  const userResponse = await page.request.post(
    new URL('wp-json/wp/v2/users', baseUrl).toString(),
    {
      headers: {
        'X-WP-Nonce': adminNonce,
      },
      data: {
        username,
        email,
        password,
        roles: ['author'],
      },
    }
  );

  expect([200, 201]).toContain(userResponse.status());
  const user = await userResponse.json();
  const userId = Number(user.id);
  expect(Number.isFinite(userId)).toBe(true);

  const postResponse = await page.request.post(
    new URL('wp-json/wp/v2/kayzart', baseUrl).toString(),
    {
      headers: {
        'X-WP-Nonce': adminNonce,
      },
      data: {
        title: `Author Draft ${seed}`,
        status: 'draft',
        author: userId,
        content: '<p>Author content</p>',
      },
    }
  );

  expect([200, 201]).toContain(postResponse.status());
  const created = await postResponse.json();
  const createdPostId = Number(created.id);
  expect(Number.isFinite(createdPostId)).toBe(true);

  return {
    username,
    password,
    postId: createdPostId,
  };
};

test('REST rejects missing nonce for cookie auth', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const cookies = await page.context().cookies();
  const hasLoggedInCookie = cookies.some((cookie) =>
    cookie.name.startsWith('wordpress_logged_in')
  );
  expect(hasLoggedInCookie).toBe(true);

  const postId = Number(postIdRaw);
  const response = await saveRequest(
    page,
    Number.isNaN(postId) ? Number(postIdRaw) : postId,
    { html: '<p>Missing nonce</p>' }
  );

  expect(response.status()).toBe(401);
});

test('REST rejects invalid nonce for cookie auth', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const postId = Number(postIdRaw);
  const nonce = 'invalid-rest-nonce';
  const response = await saveRequest(page, postId, { html: '<p>Invalid nonce</p>' }, nonce);

  expect(response.status()).toBe(403);
});

test('REST accepts valid nonce for admin without JS payload', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const postId = Number(postIdRaw);
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
});

test('REST accepts valid nonce for admin with JS payload', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const postId = Number(postIdRaw);
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
});

test('REST allows author save with valid nonce when JS is omitted', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const adminPostId = Number(postIdRaw);
  const adminNonce = await openEditorAndGetNonce(page, adminPostId);
  const author = await createAuthorAndPost(page, adminNonce);

  await login(page, author.username, author.password);
  const authorNonce = await openEditorAndGetNonce(page, author.postId);

  const response = await saveRequest(
    page,
    author.postId,
    {
      html: '<p>Author no JS</p>',
      css: 'body{background:#fff;}',
    },
    authorNonce
  );

  expect(response.status()).toBe(200);
  const data = await response.json();
  expect(data.ok).toBe(true);
});

test('REST denies author JS save even with valid nonce', async ({ page }) => {
  await login(page, adminUser, adminPass);

  const adminPostId = Number(postIdRaw);
  const adminNonce = await openEditorAndGetNonce(page, adminPostId);
  const author = await createAuthorAndPost(page, adminNonce);

  await login(page, author.username, author.password);
  const authorNonce = await openEditorAndGetNonce(page, author.postId);

  const response = await saveRequest(
    page,
    author.postId,
    {
      html: '<p>Author JS denied</p>',
      js: 'console.log("blocked");',
    },
    authorNonce
  );

  expect(response.status()).toBe(403);
});
