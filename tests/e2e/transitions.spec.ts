import { test, expect } from '@playwright/test';

// Native Astro view transitions (fade 250ms) replace the GSAP purple curtain.
// The old `.page-transition-overlay` must never exist in the DOM, and the
// `<main id="main-content">` must carry a real view-transition-name so the
// browser animates a 250ms fade between pages.

test.describe('USGAR native view transitions', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
    await page.goto('/');
  });

  const consoleErrors: string[] = [];
  test.beforeEach(async ({ page }) => {
    consoleErrors.length = 0;
    page.on('console', (msg) => {
      if (msg.type() === 'error') consoleErrors.push(msg.text());
    });
  });

  async function expectNoOverlay(page: import('@playwright/test').Page) {
    await expect(page.locator('.page-transition-overlay')).toHaveCount(0);
  }

  async function expectMainFadeDirective(page: import('@playwright/test').Page) {
    const name = await page
      .locator('#main-content')
      .evaluate((el) => getComputedStyle(el).viewTransitionName);
    expect(name).not.toBe('none');
  }

  test('SPA home → rooms → gallery → back fades main content without the purple curtain', async ({ page }) => {
    await expectMainFadeDirective(page);

    await page.locator('a[href="/rooms/"]').first().click();
    await page.waitForURL(/\/rooms\/?$/);
    await expectNoOverlay(page);
    await expectMainFadeDirective(page);
    await page.screenshot({ path: 'test-results/transitions-rooms.png' });

    await page.locator('a[href="/gallery/"]').first().click();
    await page.waitForURL(/\/gallery\/?$/);
    await expectNoOverlay(page);
    await expectMainFadeDirective(page);
    await page.screenshot({ path: 'test-results/transitions-gallery.png' });

    await page.evaluate(() => history.back());
    await page.waitForURL((url) => url.pathname === '/rooms/');
    await expectNoOverlay(page);
    await expectMainFadeDirective(page);
    await page.screenshot({ path: 'test-results/transitions-back-rooms.png' });

    await page.evaluate(() => history.back());
    await page.waitForURL((url) => url.pathname === '/');
    await expectNoOverlay(page);
    await expectMainFadeDirective(page);
    await page.screenshot({ path: 'test-results/transitions-home.png' });

    expect(consoleErrors).toEqual([]);
  });

  test('rapid double navigation settles on the last link clicked without a covering overlay', async ({ page }) => {
    await page.locator('a[href="/rooms/"]').first().click({ noWaitAfter: true });
    await page.locator('a[href="/gallery/"]').first().click({ noWaitAfter: true });

    await page.waitForTimeout(3000);

    expect(page.url()).toMatch(/\/gallery\/?$/);
    await expectNoOverlay(page);

    // Nothing that BLOCKS the screen may remain after settling: fixed + inset:0
    // with pointer events (the grain NoiseOverlay and preloader are
    // pointer-events-none by design and are not transition artifacts)
    const covering = await page.evaluate(() => {
      return Array.from(document.querySelectorAll('*'))
        .filter((el) => {
          const cs = getComputedStyle(el);
          if (cs.position !== 'fixed') return false;
          if (cs.inset !== '0px') return false;
          if (cs.display === 'none' || cs.visibility === 'hidden') return false;
          if (cs.pointerEvents === 'none') return false;
          return parseFloat(cs.opacity) > 0;
        })
        .length;
    });
    expect(covering).toBe(0);

    expect(consoleErrors).toEqual([]);
  });
});
