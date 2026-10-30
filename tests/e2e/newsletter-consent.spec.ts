import { test, expect } from '@playwright/test';

// UX del consentimiento explicito: sin checkbox no hay peticion; con checkbox
// se pide confirmacion por correo (doble opt-in) y se muestra el aviso.
test('footer newsletter requires explicit consent before subscribing', async ({ page }) => {
  let requests = 0;
  await page.route('**/api/newsletter', async (route) => {
    requests += 1;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, status: 'pending', message: 'confirmation_sent' }),
    });
  });

  await page.goto('/');

  const form = page.locator('#newsletter-form');
  const consent = page.locator('#newsletter-consent');
  const status = page.locator('#newsletter-status');
  await expect(form).toBeVisible();
  await expect(consent).not.toBeChecked();
  await expect(page.locator('#newsletter-consent-note')).toContainText(/unsubscribe/i);
  await expect(page.locator('#newsletter-consent-note a')).toHaveAttribute('href', /\/privacy\/?$/);

  await page.fill('#newsletter-email', 'guest@example.com');
  await form.locator('button[type="submit"]').click();
  await expect(status).toContainText(/consent/i);
  expect(requests, 'sin consentimiento no debe salir ninguna peticion').toBe(0);

  await consent.check();
  await form.locator('button[type="submit"]').click();
  await expect(status).toContainText(/check your inbox/i);
  expect(requests).toBe(1);
});

test('newsletter form ships the consent notice in Spanish', async ({ page }) => {
  await page.goto('/es/');
  await expect(page.locator('#newsletter-consent')).not.toBeChecked();
  await expect(page.locator('#newsletter-consent-note')).toContainText(/darte de baja/i);
});
