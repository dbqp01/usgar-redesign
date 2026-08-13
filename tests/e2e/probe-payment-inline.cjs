// Probe contra PRODUCCION (2026-08-13): verifica que el wizard de /book
// llega al paso 3 y que el contenedor del resultado inline del pago
// ([data-payment-result]) existe en el DOM — el flujo nuevo NO redirige a
// /book/success. NO completa un pago (prod no acepta tarjetas de prueba).
// Uso: node tests/e2e/probe-payment-inline.cjs
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  const consoleErrors = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
  page.on('pageerror', (e) => consoleErrors.push('PAGEERROR: ' + e.message));

  await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  await page.goto('https://usgarhoteles.com/book', { waitUntil: 'networkidle' });

  // Paso 1: fechas futuras en el calendario popover
  await page.locator('[data-calendar-month="0"] [data-calendar-day]').first().waitFor({ state: 'attached', timeout: 20000 });
  const popover = page.locator('[data-calendar-popover]');
  if (await popover.evaluate((el) => el.classList.contains('hidden'))) {
    await page.locator('#checkin').click();
    await popover.waitFor({ state: 'visible', timeout: 5000 });
  }
  const day = page.locator('[data-calendar-day][data-past="false"]').first();
  const day2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
  await day.click();
  await day2.click();

  // Tarjeta de habitacion + tarifa standard
  await page.locator('[data-allocation-card]').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.locator('[data-select-rate="standard"]').first().click();
  const nextBtn = page.locator('[data-allocation-next]');
  await nextBtn.waitFor({ state: 'visible', timeout: 10000 });
  await nextBtn.click();

  // Paso 2: guest
  await page.locator('[data-step-panel="2"]').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#guest-name').fill('Probe Prod User');
  await page.locator('#guest-email').fill('probe.prod@example.com');
  await page.locator('#guest-phone').fill('+51 999 000 111');
  await page.locator('[data-guest-next]').click();

  // Paso 3: placeholder de preparacion (createHold real) o panel de pago
  await page.locator('[data-payment-placeholder], [data-payment-panel]').first().waitFor({ state: 'visible', timeout: 20000 });

  const result = await page.evaluate(() => {
    const container = document.querySelector('[data-payment-result]');
    const placeholder = document.querySelector('[data-payment-placeholder]');
    const panel = document.querySelector('[data-payment-panel]');
    return {
      resultExists: !!container,
      resultHidden: container ? container.classList.contains('hidden') : null,
      placeholderVisible: placeholder ? !placeholder.classList.contains('hidden') : false,
      panelVisible: panel ? !panel.classList.contains('hidden') : false,
      hasVerifyButton: !!document.querySelector('[data-result-verify]'),
      hasSuccessCard: !!document.querySelector('[data-result-success]'),
      url: window.location.pathname,
      noRedirectToSuccess: !window.location.pathname.includes('/book/success'),
    };
  });

  console.log(JSON.stringify({ result, consoleErrors }, null, 2));
  await browser.close();
})();
