import { test, expect } from '@playwright/test';

test.describe('USGAR Hotels - Booking Flow E2E', () => {
  test('should render homepage and navigate to book page', async ({ page }) => {
    await page.goto('/');

    // Check title
    await expect(page).toHaveTitle(/USGAR Hotels/i);

    // Verify main navigation links
    const bookLink = page.locator('nav a[href*="/book"]').first();
    await expect(bookLink).toBeVisible();

    // Click book link
    await bookLink.click();
    await expect(page).toHaveURL(/\/book/);

    // Verify booking page header
    const heading = page.locator('h1');
    await expect(heading).toBeVisible();
  });

  test('should display rooms listing', async ({ page }) => {
    await page.goto('/rooms');
    await expect(page.locator('h1')).toBeVisible();

    // Verify at least 4 room cards exist
    const roomCards = page.locator('[data-room-card]');
    await expect(roomCards).toHaveCount(4);
  });
});
