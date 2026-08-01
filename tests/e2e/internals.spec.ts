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

  test('contact page renders the editorial form', async ({ page }) => {
    await page.goto('/contact');
    await expect(page.locator('#contact-form')).toBeVisible();
    await expect(page.locator('#contact-name')).toBeVisible();
  });
});
