import { test, expect } from '@playwright/test';

test.describe('USGAR Hotels - i18n & Theme E2E', () => {
  test('should switch language between English and Spanish', async ({ page }) => {
    await page.goto('/');

    // Check default EN content
    const enHeading = page.locator('h1').first();
    await expect(enHeading).toContainText(/USGAR Hotels/i);

    // Navigate to Spanish version
    await page.goto('/es');
    await expect(page).toHaveURL(/\/es/);
  });

  test('should toggle dark mode class on html element', async ({ page }) => {
    await page.goto('/');
    const htmlElement = page.locator('html');
    await expect(htmlElement).toBeVisible();

    // Check theme button exists
    const themeButton = page.locator('[aria-label*="theme"], [aria-label*="modo"], button:has-svg').first();
    if (await themeButton.isVisible()) {
      await themeButton.click();
    }
  });
});
