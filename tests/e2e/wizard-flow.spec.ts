import { test, expect } from '@playwright/test';

// Flujo del wizard tras la unificacion de pasos (2026-08-10):
// paso 1 = calendario + seleccion de habitacion/tarifa en el mismo panel,
// paso 2 = datos del huesped, paso 3 = pago.
// La tarifa se elige con [data-select-rate]; [data-allocation-next] avanza.
test.describe('USGAR booking wizard (Fase 4)', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
    await page.goto('/book');
  });

  test('step 1 shows the calendar and, after picking dates, room cards with both rates', async ({ page }) => {
    await expect(page.locator('[data-step-panel="1"]')).toBeVisible();
    const dayCount = await page.locator('[data-calendar-month="0"] [data-calendar-day]').count();
    expect(dayCount).toBeGreaterThanOrEqual(28);
    await expect(page.locator('[data-calendar-day][data-past="false"]').first()).toBeVisible();

    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    // El allocator pinta las tarjetas con las 2 tarifas por habitacion.
    await expect(page.locator('[data-allocation-card]').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('[data-select-rate="standard"]').first()).toBeVisible();
    await expect(page.locator('[data-select-rate="non_refundable"]').first()).toBeVisible();
  });

  test('selecting a rate enables continue and advances to the guest step', async ({ page }) => {
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    const nextBtn = page.locator('[data-allocation-next]');
    await expect(nextBtn).toBeDisabled({ timeout: 15000 });

    await page.locator('[data-select-rate="standard"]').first().click();
    await expect(nextBtn).toBeEnabled();

    await nextBtn.click();
    await expect(page.locator('[data-step-panel="2"]')).toBeVisible();
  });

  test('guest step validates required fields before advancing', async ({ page }) => {
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    await expect(page.locator('[data-allocation-card]').first()).toBeVisible({ timeout: 15000 });
    await page.locator('[data-select-rate="standard"]').first().click();
    await page.locator('[data-allocation-next]').click();

    await expect(page.locator('[data-step-panel="2"]')).toBeVisible();
    await page.locator('[data-guest-next]').click();
    await expect(page.locator('#error-banner')).toBeVisible();
    await expect(page.locator('#toast-root [data-toast="error"]')).toBeVisible();

    await page.locator('#guest-name').fill('Test User');
    await page.locator('#guest-email').fill('test@example.com');
    await page.locator('#guest-phone').fill('+51 999 888 777');
    await page.locator('[data-guest-next]').click();

    // Tras CONTINUE aparece el placeholder de preparacion del pago; el
    // formulario real solo se muestra si createHold tiene exito.
    await expect(page.locator('[data-payment-placeholder]')).toBeVisible({ timeout: 15000 });
  });

  test('preselects a single room from /book?roomType=matrimonial', async ({ page }) => {
    await page.goto('/book?roomType=matrimonial');
    const futureDay = page.locator('[data-calendar-day][data-past="false"]').first();
    const futureDay2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
    await futureDay.click();
    await futureDay2.click();

    // Preseleccion automatica de la habitacion pedida: el boton continua
    // habilitado sin necesidad de tocar una tarifa.
    await expect(page.locator('[data-allocation-card]').first()).toContainText('Matrimonial', { timeout: 15000 });
    await expect(page.locator('[data-allocation-next]')).toBeEnabled();
  });
});
