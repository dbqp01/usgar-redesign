import { test, expect } from '@playwright/test';

test.describe('USGAR home visual sections', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
    await page.goto('/');
  });

  test('shows four equally usable room cards with photos', async ({ page }) => {
    const cards = page.locator('#rooms [data-room-card]');

    await page.locator('#rooms').scrollIntoViewIfNeeded();
    await expect(cards).toHaveCount(4);
    await expect(cards.first()).toBeVisible();
    await expect(cards.locator('img')).toHaveCount(4);
  });

  test('shows services as image-led scenes', async ({ page }) => {
    const scenes = page.locator('#services [data-service-scene]');

    await page.locator('#services').scrollIntoViewIfNeeded();
    await expect(scenes).toHaveCount(4);
    await expect(scenes.first()).toBeVisible();
    await expect(scenes.locator('img')).toHaveCount(4);
  });

  test('uses an interactive atlas instead of a horizontal carousel for Explore', async ({ page }) => {
    await page.locator('#explore-atlas').scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);
    await expect(page.locator('#explore-atlas')).toHaveCount(1);
    await expect(page.locator('#explore-magazine')).toHaveCount(0);
    await expect(page.locator('#explore-atlas [data-atlas-item]')).toHaveCount(13);

    const item = page.locator('#explore-atlas [data-atlas-item]').nth(3);
    await item.focus();
    await expect(page.locator('[data-atlas-name]')).toHaveText(/Rainbow Mountain/i);
  });

  test('renders a full-size map surface and contained footer wordmark', async ({ page }) => {
    test.setTimeout(45000);
    await expect(page.locator('#location-map')).toHaveCount(1);
    await page.locator('#location-map').scrollIntoViewIfNeeded();
    await expect(page.locator('#location-map .usgar-marker')).toHaveCount(1, { timeout: 15000 });
    await expect(page.locator('[data-footer-wordmark="USGAR"]')).toHaveCount(1);

    const wordmark = page.locator('[data-footer-wordmark="USGAR"] span');
    const viewportWidth = await page.evaluate(() => window.innerWidth);
    const box = await wordmark.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.x).toBeGreaterThanOrEqual(0);
    expect(box!.x + box!.width).toBeLessThanOrEqual(viewportWidth);
  });
});
