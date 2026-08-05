import { test, expect } from '@playwright/test';

// profile.astro must initialize exactly ONCE per profile page entry (initial
// load AND SPA navigation). Regression: a direct initProfilePage() call plus
// an unguarded astro:page-load listener fired a double fetch batch on first
// load, and the listener accumulated across repeated SPA visits. Fix:
//  - a single once-bound astro:page-load listener
//    (window.__profilePageLoadBound) with no direct call;
//  - a per-entry latch ON THE DOM (data-profile-init on #profile-loading):
//    the listener lives on `document` and fires on EVERY page's page-load
//    (home included), so without a DOM-presence check profile's init would
//    leak me+bookings fetches on every navigation. The body is re-created on
//    each SPA swap → each entry has a fresh element without the flag →
//    exactly one init per entry, and duplicate page-load dispatches within
//    the same entry are discarded. No permanent window latch (window persists
//    across SPA navigations).
//
// Request-counting markers:
//  - `/api/user/bookings` is fetched ONLY by profile's init → it is the
//    exact per-entry marker: 1 per entry, no more.
//  - `/api/auth/me` is fetched by profile's init AND by the Navbar auth
//    check (Navbar.astro → getUser(), cached in sessionStorage): on the
//    first entry the Navbar misses the cache (1 fetch) and on later SPA
//    entries it hits the cache (0 fetches). Documented as context, not
//    asserted as a constant.

test.describe('USGAR profile single-init', () => {
  test.beforeEach(async ({ page }) => {
    // Skip the first-load preloader like the other SPA specs
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  });

  // Counts requests to the two profile data endpoints; returns a getter.
  function trackFetches(page: import('@playwright/test').Page) {
    let me = 0;
    let bookings = 0;
    page.on('request', (req) => {
      const url = req.url();
      if (url.includes('/api/auth/me')) me++;
      if (url.includes('/api/user/bookings')) bookings++;
    });
    return () => ({ me, bookings });
  }

  const consoleErrors: string[] = [];
  test.beforeEach(async ({ page }) => {
    consoleErrors.length = 0;
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
  });

  async function mockAuthenticated(page: import('@playwright/test').Page) {
    await page.route('**/api/auth/me', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          user: { name: 'QA Guest', email: 'qa@usgar.test', provider: 'email' },
        }),
      })
    );
    await page.route('**/api/user/bookings', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, bookings: [] }),
      })
    );
  }

  test('full reload of /profile fires exactly one bookings fetch (auth mocked)', async ({ page }) => {
    await mockAuthenticated(page);
    const count = trackFetches(page);

    await page.goto('/profile');
    await page.waitForURL(/\/profile\/?$/);
    // Name renders only after /api/auth/me resolves; the empty-bookings
    // state renders only after /api/user/bookings resolves — both are the
    // natural synchronization points before counting.
    await expect(page.locator('#profile-user-name')).toHaveText('QA Guest', { timeout: 15000 });
    await expect(page.locator('#profile-empty-bookings')).toBeVisible({ timeout: 15000 });

    const { me, bookings } = count();
    expect(bookings).toBe(1); // profile init marker: exactly once
    // me: profile init (1) + Navbar auth check (1, cache cold on first entry)
    expect(me).toBe(2);
    expect(consoleErrors).toEqual([]);
  });

  test('SPA profile → home → profile → home → profile: exactly one bookings fetch per entry', async ({ page }) => {
    await mockAuthenticated(page);
    const count = trackFetches(page);

    // Entry 1: full load
    await page.goto('/profile');
    await page.waitForURL(/\/profile\/?$/);
    await expect(page.locator('#profile-user-name')).toHaveText('QA Guest', { timeout: 15000 });
    await expect(page.locator('#profile-empty-bookings')).toBeVisible({ timeout: 15000 });
    expect(count()).toEqual({ me: 2, bookings: 1 }); // navbar cache cold

    // SPA away to home: no new profile fetches (navbar cache now warm)
    await page.locator('a[aria-label="USGAR Hotels Home"]').first().click();
    await page.waitForURL((url) => url.pathname === '/');
    expect(count()).toEqual({ me: 2, bookings: 1 });

    // Entry 2: SPA back to profile (history traversal, router fires page-load)
    await page.evaluate(() => history.back());
    await page.waitForURL(/\/profile\/?$/);
    await expect(page.locator('#profile-user-name')).toHaveText('QA Guest', { timeout: 15000 });
    await expect(page.locator('#profile-empty-bookings')).toBeVisible({ timeout: 15000 });
    // bookings: exactly one per entry → 2 total. me: +1 profile init only
    // (navbar cache warm) → 3 total.
    expect(count()).toEqual({ me: 3, bookings: 2 });

    // Entry 3: SPA away and back again — listeners must not accumulate
    await page.locator('a[aria-label="USGAR Hotels Home"]').first().click();
    await page.waitForURL((url) => url.pathname === '/');
    await page.evaluate(() => history.back());
    await page.waitForURL(/\/profile\/?$/);
    await expect(page.locator('#profile-user-name')).toHaveText('QA Guest', { timeout: 15000 });
    await expect(page.locator('#profile-empty-bookings')).toBeVisible({ timeout: 15000 });
    expect(count()).toEqual({ me: 4, bookings: 3 });

    expect(consoleErrors).toEqual([]);
  });

  test('unauthenticated profile: one auth/me batch per entry, no bookings fetch', async ({ page }) => {
    const count = trackFetches(page);

    await page.goto('/profile');
    await page.waitForURL(/\/profile\/?$/);
    // Unauthenticated: page shows the login-required state, no redirect
    await expect(page.locator('#profile-unauth')).toBeVisible({ timeout: 15000 });

    const { me, bookings } = count();
    // profile init (1) + navbar auth check (1) → both fail → unauth state;
    // bookings fetch only fires when authenticated → 0.
    expect(me).toBe(2);
    expect(bookings).toBe(0);
  });
});
