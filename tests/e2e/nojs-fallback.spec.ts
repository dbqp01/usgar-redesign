import { test, expect, type Page } from '@playwright/test';

// Todo 8: progressive-enhancement fallback for `.animate-on-scroll`.
// The hidden-by-default rules are gated behind `html.js`, so without JS the
// content is born visible; with JS the `js` class is re-applied on the INCOMING
// root in `astro:before-swap` (the router's swapRootAttributes wipes the current
// root's attributes on every SPA nav), so reveals keep animating after navigation.

function animateOnScrollElements(page: Page) {
  return page.locator('.animate-on-scroll');
}

test.describe('no-JS progressive-enhancement fallback', () => {
  test('(i) without JavaScript every .animate-on-scroll is visible and the preloader is hidden', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/');

    await expect(page.locator('#cinematic-preloader')).toBeHidden();

    const count = await animateOnScrollElements(page).count();
    expect(count).toBeGreaterThan(0);

    const hiddenCount = await page.evaluate(() => {
      let hidden = 0;
      document.querySelectorAll('.animate-on-scroll').forEach((el) => {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        if (rect.width <= 0 || rect.height <= 0 || style.opacity === '0') hidden += 1;
      });
      return hidden;
    });
    expect(hiddenCount).toBe(0);

    await page.screenshot({ path: 'test-results/nojs-home.png' });
    await context.close();
  });

  test('(ii) with JS reveals animate on first load and still animate after SPA navigation', async ({ page }) => {
    await page.goto('/');

    // Initial load: html carries the js class and content starts hidden.
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);
    const initialCount = await animateOnScrollElements(page).count();
    expect(initialCount).toBeGreaterThan(0);

    // SPA nav home -> contact (contact has guaranteed .animate-on-scroll elements):
    // the js class must survive the root-attribute swap.
    await page.locator('a[href="/contact/"]').first().click();
    await page.waitForURL(/\/contact\/?$/);
    // networkidle is unreliable here: dev-mode /_image transforms keep the page busy.
    await expect(page.locator('#main-content')).toBeAttached();
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);

    // Reveals still animate after nav: a .animate-on-scroll section reaches .visible.
    const contactTarget = page.locator('.animate-on-scroll').last();
    await expect(contactTarget).toBeAttached();
    await contactTarget.scrollIntoViewIfNeeded();
    await expect(contactTarget).toHaveClass(/visible/, { timeout: 10000 });
    await page.screenshot({ path: 'test-results/nojs-contact-revealed.png' });

    // And back home: class still present after the return nav.
    await page.locator('a[href="/"]').first().click();
    await page.waitForURL((url) => url.pathname === '/');
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);
  });

  test('(iii) reduced motion: content visible instantly and the js class survives 3 SPA navs', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();
    await page.goto('/');

    // Nothing hidden at all: every .animate-on-scroll is already visible.
    const allVisible = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.animate-on-scroll')).every(
        (el) => getComputedStyle(el).opacity === '1' && getComputedStyle(el).visibility === 'visible'
      )
    );
    expect(allVisible).toBe(true);

    // 3 SPA navs: home -> rooms -> gallery -> home.
    await page.locator('a[href="/rooms/"]').first().click();
    await page.waitForURL(/\/rooms\/?$/);
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);

    await page.locator('a[href="/gallery/"]').first().click();
    await page.waitForURL(/\/gallery\/?$/);
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);

    await page.locator('a[href="/"]').first().click();
    await page.waitForURL((url) => url.pathname === '/');
    expect(await page.evaluate(() => document.documentElement.classList.contains('js'))).toBe(true);

    // And no element stuck hidden after the navs (reduced motion must win).
    const stillVisible = await page.evaluate(() =>
      Array.from(document.querySelectorAll('.animate-on-scroll')).every(
        (el) => getComputedStyle(el).opacity === '1' && getComputedStyle(el).visibility === 'visible'
      )
    );
    expect(stillVisible).toBe(true);
    await context.close();
  });
});
