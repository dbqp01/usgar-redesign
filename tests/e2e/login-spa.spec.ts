import { test, expect } from '@playwright/test';

// Login page under Astro client-side routing. The inline login script had no
// `data-astro-rerun`: after the first SPA visit the router marks it
// `data-astro-exec` (swap-functions.js `deselectScripts` + router.js
// `runScripts`) and skips it on 2nd+ visits, leaving the form dead. This spec
// covers SPA nav, go-and-return (the 2nd+ visit case), full reload, and
// exactly-once request firing per submit (no duplicated listeners). Submits
// are route-intercepted — no real users are created and no real auth is
// expected; a 200-with-success:false fulfillment keeps the page in place
// (a 401 would make Chromium log a "Failed to load resource" console error).

test.describe('USGAR login under SPA navigation', () => {
  test.skip(({ browserName }) => browserName !== 'chromium', 'login-spa runs on chromium only');

  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  });

  const consoleErrors: string[] = [];
  test.beforeEach(async ({ page }) => {
    consoleErrors.length = 0;
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
  });

  /** Intercept the real login endpoint; returns a live counter of POSTs seen. */
  function interceptLogin(page: import('@playwright/test').Page) {
    const requests: string[] = [];
    page.route('**/api/auth/login-email', (route) => {
      requests.push(route.request().method());
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, error: { message: 'intercepted by e2e' } }),
      });
    });
    return requests;
  }

  // Hermetic API: the navbar auth check and the providers fetch run on every
  // page entry; stub them so the spec has no dependency on a live PHP backend.
  test.beforeEach(async ({ page }) => {
    await page.route('**/api/auth/providers', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, providers: [] }),
      })
    );
    await page.route('**/api/auth/me', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: false }),
      })
    );
  });

  async function submitLogin(page: import('@playwright/test').Page) {
    await page.locator('#login-email').fill('e2e@test.local');
    await page.locator('#login-password').fill('wrong-password');
    await page.locator('#login-submit').click();
  }

  async function expectAlertVisible(page: import('@playwright/test').Page) {
    await expect(page.locator('#auth-alert')).not.toHaveClass(/hidden/);
  }

  test('SPA home → login: tabs work and submit fires exactly one intercepted request', async ({ page }) => {
    const requests = interceptLogin(page);
    await page.goto('/');
    await page.locator('#auth-login-btn').click();
    await page.waitForURL(/\/login\/?$/);

    // Login / Register tabs functional after SPA entry
    await expect(page.locator('#register-form')).toBeHidden();
    await page.locator('#tab-register-btn').click();
    await expect(page.locator('#register-form')).toBeVisible();
    await expect(page.locator('#login-form')).toBeHidden();
    await page.locator('#tab-login-btn').click();
    await expect(page.locator('#login-form')).toBeVisible();

    await submitLogin(page);
    await expectAlertVisible(page);
    expect(requests).toEqual(['POST']);
    expect(consoleErrors).toEqual([]);
  });

  test('go-and-return: 2nd+ SPA visit still functional (fails without data-astro-rerun)', async ({ page }) => {
    const requests = interceptLogin(page);
    await page.goto('/');
    await page.locator('#auth-login-btn').click();
    await page.waitForURL(/\/login\/?$/);

    // Back home, then SPA nav to /login again — the 2nd+ entry
    await page.evaluate(() => history.back());
    await page.waitForURL((url) => url.pathname === '/');
    await page.locator('#auth-login-btn').click();
    await page.waitForURL(/\/login\/?$/);

    await submitLogin(page);
    await expectAlertVisible(page);
    expect(requests).toEqual(['POST']);
    expect(consoleErrors).toEqual([]);
  });

  test('full reload of /login also works', async ({ page }) => {
    const requests = interceptLogin(page);
    await page.goto('/login');

    await submitLogin(page);
    await expectAlertVisible(page);
    expect(requests).toEqual(['POST']);
    expect(consoleErrors).toEqual([]);
  });
});
