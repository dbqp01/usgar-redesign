// Probe visual: el recibo completo (data-result-success) existe en el DOM del
// wizard con todos los campos data-* del voucher, y el flujo de recuperación
// sigue intacto. No toca pagos reales (nunca llega a onSubmit del brick).
// Uso: PROBE_BASE=http://127.0.0.1:8090 node tests/e2e/probe-receipt.cjs
const { chromium } = require('@playwright/test');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const BASE = process.env.PROBE_BASE || 'http://127.0.0.1:8090';
const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`);
}

async function reachGuestStep(page) {
  await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  await page.goto(BASE + '/book', { waitUntil: 'networkidle' });
  await page.locator('[data-calendar-month="0"] [data-calendar-day]').first().waitFor({ state: 'attached', timeout: 20000 });
  const pop = page.locator('[data-calendar-popover]');
  if (await pop.evaluate((el) => el.classList.contains('hidden'))) {
    await page.locator('#checkin').click();
    await pop.waitFor({ state: 'visible', timeout: 5000 });
  }
  await page.locator('[data-calendar-day][data-past="false"]').first().click();
  await page.locator('[data-calendar-day][data-past="false"]').nth(1).click();
  await page.locator('[data-allocation-card]').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.locator('[data-select-rate="standard"]').first().click();
  await page.locator('[data-allocation-next]').click();
  await page.locator('[data-step-panel="2"]').waitFor({ state: 'visible', timeout: 10000 });
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  // === Escenario A: estructura del recibo en el DOM ===
  await reachGuestStep(page);

  // 1. El recibo completo existe en el DOM (hidden) con todos los campos.
  const fields = await page.evaluate(() => {
    const q = (sel) => !!document.querySelector(sel);
    return {
      successBox: q('[data-result-success]'),
      confCode: q('[data-result-conf-code]'),
      guestName: q('[data-result-guest-name]'),
      guestEmail: q('[data-result-guest-email]'),
      guestPhone: q('[data-result-guest-phone]'),
      roomName: q('[data-result-room-name]'),
      roomMeta: q('[data-result-room-meta]'),
      checkin: q('[data-result-checkin]'),
      checkout: q('[data-result-checkout]'),
      penTotal: q('[data-result-pen-total]'),
      usdEquiv: q('[data-result-usd-equiv]'),
      fullReceipt: q('[data-result-full-receipt]'),
      pickupNote: q('[data-result-pickup-note]'),
      printBtn: q('[data-result-print]'),
    };
  });
  const missing = Object.entries(fields).filter(([, v]) => !v).map(([k]) => k);
  check('A: recibo completo — todos los campos data-* existen', missing.length === 0, missing.length ? `faltan: ${missing.join(', ')}` : `${Object.keys(fields).length} campos`);

  // 2. El bloque success nace oculto (se muestra solo al pagar).
  const hiddenOnLoad = await page.evaluate(() => {
    const el = document.querySelector('[data-result-success]');
    return el ? el.classList.contains('hidden') : null;
  });
  check('A: bloque success nace oculto', hiddenOnLoad === true, String(hiddenOnLoad));

  // 3. i18n: openReceipt no vacío.
  const openReceiptText = await page.evaluate(() => {
    const el = document.querySelector('[data-result-full-receipt]');
    return el ? el.textContent.trim() : '';
  });
  check('A: i18n openReceipt presente', openReceiptText.length > 0, openReceiptText);

  // 4. La página /book/success carga con su estructura (recibo imprimible).
  await page.goto(BASE + '/book/success', { waitUntil: 'networkidle' });
  const successFields = await page.evaluate(() => ({
    loading: !!document.getElementById('loading-container'),
    success: !!document.getElementById('success-container'),
    confCode: !!document.getElementById('success-conf-code'),
    printBtn: !!document.querySelector('[data-verify-again]'),
  }));
  check('B: /book/success carga con contenedores', successFields.loading && successFields.success && successFields.confCode, JSON.stringify(successFields));

  // === Escenario C: recuperación tras fallo de createHold (regresión fix 714e772) ===
  await page.route('**/api/booking', (route) =>
    route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, error: { code: 'SERVER_ERROR', message: 'boom' } }) })
  );
  await reachGuestStep(page);
  await page.fill('#guest-name', 'Ana Torres');
  await page.fill('#guest-email', 'ana@example.com');
  await page.fill('#guest-phone', '+51 999 000 111');
  await page.click('[data-guest-next]');
  await sleep(4000);

  const recovery = await page.evaluate(() => {
    const guest = document.querySelector('[data-guest-step]');
    const guestVisible = guest && getComputedStyle(guest).display !== 'none' && guest.getBoundingClientRect().height > 0;
    const nextBtn = document.querySelector('[data-guest-next]');
    const nextVisible = nextBtn && getComputedStyle(nextBtn).display !== 'none';
    return { guestVisible, nextVisible };
  });
  check('C: tras fallo createHold el guest form vuelve visible (fix 714e772)', recovery.guestVisible === true && recovery.nextVisible === true, JSON.stringify(recovery));

  // 5. Sin page errors fatales.
  check('D: sin errores JS fatales', errors.length === 0, errors.slice(0, 2).join(' | '));

  await browser.close();
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} PASS`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error('PROBE CRASH:', e); process.exit(1); });
