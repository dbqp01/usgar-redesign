import { test, expect } from '@playwright/test';

test('muestra habitaciones y envía una consulta sin prometer disponibilidad ni cobrar', async ({ page }) => {
  const paymentRequests: string[] = [];
  page.on('request', (request) => { if (request.url().includes('mercadopago.com')) paymentRequests.push(request.url()); });
  await page.goto('/es/book/?checkin=2026-11-20&checkout=2026-11-22&guests=2');
  await expect(page).toHaveTitle(/Consulta tu estancia/i);
  await expect(page.getByText(/confirmará la disponibilidad/i).first()).toBeVisible();
  const links = page.locator('a[data-inquiry-room]');
  await expect(links).toHaveCount(4);
  const link = links.first();
  await expect(link).toBeVisible();
  await expect(link).toHaveAccessibleName(/Habitación Doble Superior/i);
  const url = new URL((await link.getAttribute('href'))!);
  expect(url.hostname).toBe('wa.me');
  expect(url.searchParams.get('text')).toContain('2026-11-20');
  expect(url.searchParams.get('text')).toContain('2026-11-22');
  expect(url.searchParams.get('text')).not.toContain('por indicar');
  await expect(page.locator('[data-payment-wrap]')).toHaveCount(0);
  expect(await page.evaluate(() => '__ROOM_INVENTORY' in window)).toBe(false);
  await expect(page.locator('[data-inquiry-page] a[href="/es/privacy/"]')).toBeVisible();
  expect(paymentRequests).toHaveLength(0);
});

for (const [path, title] of [
  ['/book/', 'Enquire about your stay'],
  ['/fr/book/', 'Demandez votre séjour'],
  ['/pt/book/', 'Consulte sua estadia'],
] as const) {
  test(`consulta disponible en ${path}`, async ({ page }) => {
    await page.goto(path);
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.locator('a[data-inquiry-room]')).toHaveCount(4);
  });
}

test('los enlaces de consulta funcionan sin JavaScript', async ({ browser }) => {
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  await page.goto('/es/book/');
  const link = page.locator('a[data-inquiry-room]').first();
  await expect(link).toBeVisible();
  const url = new URL((await link.getAttribute('href'))!);
  expect(url.hostname).toBe('wa.me');
  expect(url.searchParams.get('text')).toContain('por indicar');
  await context.close();
});
