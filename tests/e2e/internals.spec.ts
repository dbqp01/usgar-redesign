import { test, expect } from '@playwright/test';

test.describe('USGAR internal pages (Fase 3)', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  });

  test('rooms page shows editorial cards with photos and working capacity filter', async ({ page }) => {
    await page.goto('/rooms');
    const cards = page.locator('[data-rooms-gallery] [data-room-card]');
    await expect(cards).toHaveCount(4);
    await expect(page.locator('[data-rooms-gallery] picture img')).toHaveCount(4);
    await expect(page.locator('[data-capacity-filter="2"]')).toBeVisible();
    await page.locator('[data-capacity-filter="2"]').click();
    await expect(cards.first()).toBeVisible();
  });

  test('explore page shows cards with photos and search filters', async ({ page }) => {
    await page.goto('/explore');
    const cards = page.locator('[data-explore-cards] [data-explore-card]');
    await page.locator('#attractions-grid').scrollIntoViewIfNeeded();
    await expect(cards.first()).toBeVisible();
    await expect(cards.locator('picture img').first()).toBeVisible();

    await page.locator('#explore-search').fill('machu');
    await expect(page.locator('[data-name-en="machu picchu"]')).toBeVisible();
  });

  test('gallery keeps its lightbox grid with optimized images', async ({ page }) => {
    await page.goto('/gallery');
    await expect(page.locator('#gallery-grid')).toBeVisible();
    await expect(page.locator('#gallery-grid img').first()).toBeVisible();
    await expect(page.locator('.filter-btn[data-filter="hotel"]')).toBeVisible();
  });

  test('home booking widget opens the custom calendar popover and picks a range', async ({ page }) => {
    await page.goto('/');
    const trigger = page.locator('[data-widget-dates]');
    await expect(trigger).toBeVisible();
    await trigger.click();
    await expect(page.locator('[data-widget-popover]')).toBeVisible();
    await page.locator('[data-widget-popover]').scrollIntoViewIfNeeded();
    await page.waitForTimeout(900);
    await expect(page.locator('[data-widget-month="0"] [data-calendar-day]').first()).toBeVisible();

    const futureDay = page.locator('[data-widget-popover] [data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-widget-popover] [data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();
    await expect(page.locator('[data-widget-range]')).toContainText('nights');
    await page.locator('[data-widget-done]').click();
    await expect(page.locator('[data-widget-popover]')).toBeHidden();
  });

  test('contact page renders the editorial form', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.locator('#contact-form')).toBeVisible();
    await expect(page.locator('#contact-name')).toBeVisible();
  });
});
