import { test, expect } from '@playwright/test';

test.describe('owner panel availability errors', () => {
  test('shows a safe retry state when the calendar endpoint is unavailable', async ({ page }) => {
    await page.route('**/api/panel/availability**', async (route) => {
      await route.fulfill({ status: 502, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'UPSTREAM_ERROR', message: 'backend detail' } }) });
    });

    await page.goto('/panel');

    await expect(page.locator('#calendarError')).toBeVisible();
    await expect(page.locator('#calendarError')).toContainText('No pudimos cargar el calendario');
    await expect(page.locator('#calendarError')).toContainText('Reintentar');
    await expect(page.locator('#calendarError')).not.toContainText('HTTP 502');
    await expect(page.locator('#calendarError')).not.toContainText('backend detail');
  });
});
