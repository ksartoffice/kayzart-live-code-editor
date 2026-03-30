import { test, expect } from '@playwright/test';

const adminUser = process.env.WP_ADMIN_USER ?? '';
const adminPass = process.env.WP_ADMIN_PASS ?? '';
const postId = process.env.KAYZART_POST_ID ?? '';
const baseUrlRaw = process.env.WP_BASE_URL ?? 'http://localhost';
const baseUrl = (() => {
  const url = new URL(baseUrlRaw);
  if (!url.pathname.endsWith('/')) {
    url.pathname += '/';
  }
  return url;
})();

test.skip(
  !adminUser || !adminPass || !postId,
  'Set WP_ADMIN_USER, WP_ADMIN_PASS, and KAYZART_POST_ID.'
);

const loginAsAdmin = async (page: import('@playwright/test').Page): Promise<void> => {
  const loginUrl = new URL('wp-login.php', baseUrl).toString();

  for (let attempt = 0; attempt < 3; attempt += 1) {
    await page.context().clearCookies();
    await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
    await page.fill('#user_login', adminUser);
    await page.fill('#user_pass', adminPass);
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

  throw new Error(`Failed to log in as ${adminUser}.`);
};

const resolveEditorUrl = async (
  page: import('@playwright/test').Page,
  targetPostId: string
): Promise<string> => {
  const postEditUrl = new URL('wp-admin/post.php', baseUrl);
  postEditUrl.searchParams.set('post', targetPostId);
  postEditUrl.searchParams.set('action', 'edit');
  await page.goto(postEditUrl.toString(), { waitUntil: 'domcontentloaded' });

  const editorUrlHandle = await page
    .waitForFunction(
      (postIdValue) => {
        const bridge = (window as any).KAYZART_EDITOR;
        if (!bridge || typeof bridge.actionUrl !== 'string' || !bridge.actionUrl) {
          return null;
        }
        const url = new URL(bridge.actionUrl, window.location.origin);
        url.searchParams.set('post_id', String(postIdValue));
        return url.toString();
      },
      targetPostId,
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
  const rowActionLink = await page.evaluate((postIdValue) => {
    const anchors = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href]'));
    for (const anchor of anchors) {
      try {
        const url = new URL(anchor.href, window.location.origin);
        if (
          url.searchParams.get('page') === 'kayzart' &&
          url.searchParams.get('post_id') === String(postIdValue) &&
          url.searchParams.get('_wpnonce')
        ) {
          return url.toString();
        }
      } catch {
        // Ignore malformed href values.
      }
    }
    return null;
  }, targetPostId);

  if (typeof rowActionLink !== 'string' || rowActionLink.length === 0) {
    throw new Error('Failed to resolve nonce-protected KayzArt editor URL.');
  }

  return rowActionLink;
};

const loginAndGetPreviewUrl = async (page: import('@playwright/test').Page): Promise<string> => {
  await loginAsAdmin(page);
  const editorUrl = await resolveEditorUrl(page, postId);
  await page.goto(editorUrl, { waitUntil: 'domcontentloaded' });

  const handle = await page.waitForFunction(() => {
    return (window as any).KAYZART?.iframePreviewUrl || null;
  });
  const previewUrl = await handle.jsonValue();
  if (typeof previewUrl !== 'string' || previewUrl.length === 0) {
    throw new Error('iframePreviewUrl not found. Check KAYZART_POST_ID and login.');
  }
  return previewUrl;
};

test('preview postMessage handshake works', async ({ page }) => {
  const previewUrl = await loginAndGetPreviewUrl(page);

  const messages: Array<{ type?: string }> = [];

  await page.goto(new URL('./', baseUrl).toString(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(
    ({ url }) => {
      const iframe = document.createElement('iframe');
      iframe.id = 'kayzart-preview-frame';
      iframe.src = url;
      iframe.style.width = '800px';
      iframe.style.height = '600px';
      document.body.appendChild(iframe);
    },
    { url: previewUrl }
  );

  await page.waitForSelector('#kayzart-preview-frame');
  await page.waitForFunction(() => {
    const iframe = document.getElementById('kayzart-preview-frame') as HTMLIFrameElement | null;
    if (!iframe || !iframe.contentWindow) {
      return false;
    }
    const href = iframe.contentWindow.location.href || '';
    const readyState = iframe.contentDocument?.readyState;
    return href.includes('kayzart_preview=1') && readyState === 'complete';
  });

  const ready = await page.evaluate(() => {
    return new Promise<{ type?: string }>((resolve, reject) => {
      const iframe = document.getElementById('kayzart-preview-frame') as HTMLIFrameElement | null;
      if (!iframe) {
        reject(new Error('Preview iframe not found'));
        return;
      }

      const handler = (event: MessageEvent) => {
        if (event.data && event.data.type === 'KAYZART_READY') {
          window.removeEventListener('message', handler as EventListener);
          resolve(event.data);
        }
      };

      window.addEventListener('message', handler as EventListener);
      iframe.contentWindow?.postMessage({ type: 'KAYZART_INIT' }, '*');

      window.setTimeout(() => {
        window.removeEventListener('message', handler as EventListener);
        reject(new Error('Timed out waiting for KAYZART_READY'));
      }, 5_000);
    });
  });
  messages.push(ready);

  expect(messages.some((message) => message.type === 'KAYZART_READY')).toBe(true);
});

test('preview does not send READY before INIT', async ({ page }) => {
  const previewUrl = await loginAndGetPreviewUrl(page);

  await page.goto(new URL('./', baseUrl).toString(), { waitUntil: 'domcontentloaded' });
  await page.evaluate(
    ({ url }) => {
      const iframe = document.createElement('iframe');
      iframe.id = 'kayzart-preview-frame';
      iframe.src = url;
      iframe.style.width = '800px';
      iframe.style.height = '600px';
      document.body.appendChild(iframe);
    },
    { url: previewUrl }
  );

  await page.waitForSelector('#kayzart-preview-frame');

  const outcome = await page.evaluate(() => {
    return new Promise<'ready' | 'timeout'>((resolve) => {
      const handler = (event: MessageEvent) => {
        if (event.data && event.data.type === 'KAYZART_READY') {
          window.removeEventListener('message', handler as EventListener);
          resolve('ready');
        }
      };
      window.addEventListener('message', handler as EventListener);
      window.setTimeout(() => {
        window.removeEventListener('message', handler as EventListener);
        resolve('timeout');
      }, 400);
    });
  });

  expect(outcome).toBe('timeout');
});

test('preview ignores postMessage when allowedOrigin mismatches', async ({ page }) => {
  const previewUrl = await loginAndGetPreviewUrl(page);

  await page.goto('about:blank', { waitUntil: 'domcontentloaded' });
  await page.evaluate(
    ({ url }) => {
      const iframe = document.createElement('iframe');
      iframe.id = 'kayzart-preview-frame';
      iframe.src = url;
      iframe.style.width = '800px';
      iframe.style.height = '600px';
      document.body.appendChild(iframe);
    },
    { url: previewUrl }
  );

  await page.waitForSelector('#kayzart-preview-frame');

  const outcome = await page.evaluate(() => {
    return new Promise<'ready' | 'timeout'>((resolve) => {
      const handler = (event: MessageEvent) => {
        if (event.data && event.data.type === 'KAYZART_READY') {
          window.removeEventListener('message', handler as EventListener);
          resolve('ready');
        }
      };
      window.addEventListener('message', handler as EventListener);
      const iframe = document.getElementById('kayzart-preview-frame') as HTMLIFrameElement | null;
      iframe?.contentWindow?.postMessage({ type: 'KAYZART_INIT' }, '*');
      window.setTimeout(() => {
        window.removeEventListener('message', handler as EventListener);
        resolve('timeout');
      }, 400);
    });
  });

  expect(outcome).toBe('timeout');
});

