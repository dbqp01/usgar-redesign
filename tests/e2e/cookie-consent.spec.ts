import { test, expect } from '@playwright/test';

/**
 * Aviso de cookies: solo almacenamiento estrictamente necesario.
 *
 * El aviso no debe fingir una elección (no hay cookies opcionales que
 * aceptar/rechazar), debe nombrar el almacenamiento real y no bloquear las
 * cookies necesarias. Los terceros condicionales (MercadoPago) solo se cargan
 * en /book, así que el aviso lo describe como algo que ocurre al reservar.
 */

const LOCALES = {
  en: { title: 'Cookies and local storage', action: 'Understood' },
  es: { title: 'Cookies y almacenamiento local', action: 'Entendido' },
  fr: { title: 'Cookies et stockage local', action: 'Compris' },
  pt: { title: 'Cookies e armazenamento local', action: 'Entendi' },
} as const;

test.describe('Cookie notice', () => {
  test('names the storage actually used and offers one honest action', async ({ page }) => {
    await page.goto('/');

    const banner = page.locator('#cookie-consent');
    await expect(banner).toBeVisible({ timeout: 5000 });

    // Región etiquetada para lectores de pantalla.
    const region = page.getByRole('region', { name: LOCALES.en.title });
    await expect(region).toBeVisible();
    await expect(region).toHaveAttribute('aria-describedby', 'cookie-consent-text');

    // Nombra el almacenamiento real (cookies de sesión/seguridad + local/session storage).
    const text = page.locator('#cookie-consent-text');
    for (const name of ['usgar_session', 'usgar_csrf']) {
      await expect(text).toContainText(name);
    }
    await expect(text).toContainText(/local storage/i);
    await expect(text).toContainText(/session storage/i);
    await expect(text).toContainText(/analytics|marketing/i);

    // Tercero condicional: MercadoPago solo interviene al reservar/pagar.
    await expect(text).toContainText(/MercadoPago/);
    await expect(text).toContainText(/payment|booking/i);
    await expect(region.getByRole('link', { name: /privacy/i })).toBeVisible();
    await expect(region.getByRole('link', { name: /terms/i })).toBeVisible();

    // Una sola acción de reconocimiento; nada de aceptar/rechazar lo que no se bloquea.
    const buttons = banner.getByRole('button');
    await expect(buttons).toHaveCount(1);
    await expect(buttons).toHaveText(LOCALES.en.action);
    await expect(buttons).toHaveAttribute('data-cookie-choice', 'acknowledged');
    await expect(banner.getByRole('button', { name: /decline|reject|refuse|rechazar/i })).toHaveCount(0);
    await expect(banner.getByRole('checkbox')).toHaveCount(0);
  });

  test('dismissal persists, is keyboard operable and leaves necessary storage alone', async ({ page, context }) => {
    await page.goto('/');
    const origin = new URL(page.url()).origin;
    await context.addCookies([
      { name: 'usgar_session', value: 'test-session', url: origin, httpOnly: true },
    ]);
    await page.evaluate(() => localStorage.setItem('theme', 'dark'));

    const banner = page.locator('#cookie-consent');
    await expect(banner).toBeVisible({ timeout: 5000 });

    const action = banner.getByRole('button');
    await action.focus();
    await expect(action).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(banner).toBeHidden();
    expect(await page.evaluate(() => localStorage.getItem('usgar-cookie-consent'))).toBe('acknowledged');

    // No bloquea ni borra lo necesario: cookie de sesión y preferencia de tema siguen ahí.
    const cookies = await context.cookies(origin);
    expect(cookies.find((c) => c.name === 'usgar_session')?.value).toBe('test-session');
    expect(await page.evaluate(() => localStorage.getItem('theme'))).toBe('dark');

    // Persiste al recargar y al navegar a otra página.
    await page.reload();
    await page.waitForTimeout(1800);
    await expect(banner).toBeHidden();
    await page.goto('/es');
    await page.waitForTimeout(1800);
    await expect(banner).toBeHidden();
  });

  test('speaks all four locales', async ({ page }) => {
    test.setTimeout(180_000);
    for (const [path, copy] of [['/', LOCALES.en], ['/es', LOCALES.es], ['/fr', LOCALES.fr], ['/pt', LOCALES.pt]] as const) {
      await page.goto(path);
      const banner = page.locator('#cookie-consent');
      await expect(banner).toBeVisible({ timeout: 8000 });
      await expect(page.getByRole('region', { name: copy.title })).toBeVisible();
      await expect(banner.getByRole('button')).toHaveText(copy.action);
    }
  });

  test('third parties stay conditional: MercadoPago only on the booking page', async ({ page }) => {
    test.setTimeout(180_000);
    const homeRequests: string[] = [];
    page.on('request', (req) => homeRequests.push(req.url()));
    await page.goto('/');
    await page.waitForLoadState('networkidle');
    expect(homeRequests.some((u) => /mercadopago\.com/.test(u))).toBe(false);
    expect(homeRequests.some((u) => /google-analytics|googletagmanager|facebook\.net|hotjar/.test(u))).toBe(false);

    const bookRequests: string[] = [];
    page.on('request', (req) => bookRequests.push(req.url()));
    await page.goto('/book');
    await page.waitForLoadState('networkidle');
    const manualInquiry = await page.locator('[data-inquiry-page]').count() > 0;
    expect(bookRequests.some((u) => /mercadopago\.com/.test(u))).toBe(!manualInquiry);
  });
});
