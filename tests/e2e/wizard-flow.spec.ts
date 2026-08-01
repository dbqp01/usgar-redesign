import { test, expect } from '@playwright/test';

test.describe('USGAR booking wizard (Fase 4)', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
    await page.goto('/book');
  });

  test('step 1 shows a custom calendar with a continue button that unlocks after dates are picked', async ({ page }) => {
    await expect(page.locator('[data-step-panel="1"]')).toBeVisible();
    const dayCount = await page.locator('[data-calendar-month="0"] [data-calendar-day]').count();
    expect(dayCount).toBeGreaterThanOrEqual(28);
    await expect(page.locator('[data-calendar-day][data-past="false"]').first()).toBeVisible();

    const continueBtn = page.locator('[data-calendar-continue]');
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    await expect(page.locator('[data-calendar-checking]')).toBeHidden();
    await expect(continueBtn).toBeEnabled();
  });

  test('after picking dates, the allocator shows room options with a best-price badge', async ({ page }) => {
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    await page.locator('[data-calendar-continue]').click();

    await expect(page.locator('[data-step-panel="2"]')).toBeVisible();
    const options = page.locator('[data-allocation-option]');
    await expect(options.first()).toBeVisible();
    await expect(page.locator('text=Best price').first()).toBeVisible();
  });

  test('guest step validates required fields before advancing', async ({ page }) => {
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();
    await page.locator('[data-calendar-continue]').click();
    await page.locator('[data-allocation-next]').click();

    await expect(page.locator('[data-step-panel="3"]')).toBeVisible();
    await page.locator('[data-guest-next]').click();
    await expect(page.locator('#error-banner')).toBeVisible();

    await page.locator('#guest-name').fill('Test User');
    await page.locator('#guest-email').fill('test@example.com');
    await page.locator('#guest-phone').fill('+51 999 888 777');
    await page.locator('[data-guest-next]').click();

    await expect(page.locator('#mp-payment-form')).toBeVisible();
  });

  test('preselects a single room from /book?roomType=matrimonial and shows alternatives toggle', async ({ page }) => {
    await page.goto('/book?roomType=matrimonial');
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();
    await page.locator('[data-calendar-continue]').click();

    await expect(page.locator('[data-allocation-option]').first()).toContainText('Matrimonial');
    await expect(page.locator('[data-allocation-option]')).toHaveCount(1);
    await expect(page.locator('text=View alternatives').first()).toBeVisible();
  });
});
