import { test, expect, type Page } from '@playwright/test';

// Aviso de privacidad en los puntos de recolección y aceptación explícita de
// las condiciones de reserva (no consentimiento de privacidad: la base legal
// de la reserva es la ejecución del contrato).

async function reachGuestStep(page: Page) {
  await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  await page.goto('/book');
  test.skip(await page.locator('[data-inquiry-page]').count() > 0, 'El checkout está deshabilitado durante la confirmación manual.');
  await expect(page.locator('[data-guest-step]')).toBeAttached();
  // El paso 2 solo se alcanza con inventario del PMS; para probar el aviso y
  // la compuerta de aceptación se muestra el panel ya renderizado.
  await page.evaluate(() => {
    const first = document.querySelector('[data-step-panel="1"]');
    const guestPanel = document.querySelector('[data-step-panel="2"]');
    first?.classList.add('hidden');
    guestPanel?.classList.remove('hidden');
    guestPanel?.removeAttribute('aria-hidden');
    guestPanel?.removeAttribute('inert');
  });
  await expect(page.locator('[data-step-panel="2"]')).toBeVisible();
}

test.describe('privacy notice and booking acceptance', () => {
  test('guest step explains data use and requires accepting the booking terms', async ({ page }) => {
    await reachGuestStep(page);

    const notice = page.locator('#guest-privacy-notice');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText(/manage your booking/i);
    await expect(notice.locator('a[href*="/privacy"]')).toHaveAttribute('href', /\/privacy/);
    await expect(page.locator('#guest-email')).toHaveAttribute('aria-describedby', 'guest-privacy-notice');

    await page.locator('#guest-name').fill('Privacy Test');
    await page.locator('#guest-email').fill('privacy@example.com');
    await page.locator('#guest-phone').fill('+51 999 888 777');
    await expect(page.locator('#booking-acceptance')).not.toBeChecked();

    // Sin aceptar las condiciones no se avanza al pago.
    await page.locator('[data-guest-next]').click();
    await expect(page.locator('#error-banner')).toBeVisible();
    await expect(page.locator('[data-step-panel="2"]')).toBeVisible();

    await page.locator('#booking-acceptance').check();
    await page.locator('[data-guest-next]').click();
    await expect(page.locator('[data-step-panel="2"]')).toBeHidden();
  });

  test('contact form shows the privacy notice before collecting data', async ({ page }) => {
    await page.goto('/contact');
    const notice = page.locator('#contact-privacy-notice');
    await notice.scrollIntoViewIfNeeded();
    await expect(notice).toBeVisible();
    await expect(notice).toContainText(/answer your request/i);
    await expect(notice.locator('a[href*="/privacy"]')).toHaveAttribute('href', /\/privacy/);
    await expect(page.locator('#contact-name')).toHaveAttribute('aria-describedby', 'contact-privacy-notice');
  });

  test('login and register forms disclose data use before the fields', async ({ page }) => {
    await page.goto('/login');
    const notice = page.locator('#auth-privacy-notice');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText(/create and secure your account/i);
    await expect(notice.locator('a[href*="/privacy"]')).toHaveAttribute('href', /\/privacy/);

    await page.locator('#tab-register-btn').click();
    await expect(page.locator('#register-form')).toBeVisible();
    await expect(notice).toBeVisible();
    await expect(page.locator('#reg-email')).toHaveAttribute('aria-describedby', 'auth-privacy-notice');
  });
});
