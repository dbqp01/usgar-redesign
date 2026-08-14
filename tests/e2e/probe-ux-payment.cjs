// Verificacion UX 2026-08-14: boton verify oculto en pending + nota PEN visible.
// Uso: PROBE_BASE=http://127.0.0.1:8090 node tests/e2e/probe-ux-payment.cjs
const { chromium } = require('@playwright/test');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const BASE = process.env.PROBE_BASE || 'https://usgarhoteles.com';

let failures = 0;
const check = (name, cond, detail) => {
  console.log(`${cond ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`);
  if (!cond) failures += 1;
};

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  // Fallo de red en createHold -> panel pending con polling
  await page.route('**/api/booking', (route) => route.abort('connectionfailed'));
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
  await page.locator('#guest-name').fill('Probe UX User');
  await page.locator('#guest-email').fill('probe.ux@example.com');
  await page.locator('#guest-phone').fill('+51 999 000 888');
  await page.locator('[data-guest-next]').click();
  await sleep(4000);

  // Tras fallo de createHold: el wizard restaura el guest (fix previo).
  const guestRect = await page.evaluate(() => {
    const r = document.querySelector('[data-guest-step]')?.getBoundingClientRect();
    return r ? `${Math.round(r.width)}x${Math.round(r.height)}` : 'none';
  });
  check('guest form restaurado tras fallo', guestRect !== '0x0' && guestRect !== 'none', guestRect);
  await page.close();

  // === Flujo feliz hasta el pad: boton verify oculto en pending ===
  const page2 = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page2.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  await page2.goto(BASE + '/book', { waitUntil: 'networkidle' });
  await page2.locator('[data-calendar-month="0"] [data-calendar-day]').first().waitFor({ state: 'attached', timeout: 20000 });
  const pop2 = page2.locator('[data-calendar-popover]');
  if (await pop2.evaluate((el) => el.classList.contains('hidden'))) {
    await page2.locator('#checkin').click();
    await pop2.waitFor({ state: 'visible', timeout: 5000 });
  }
  await page2.locator('[data-calendar-day][data-past="false"]').first().click();
  await page2.locator('[data-calendar-day][data-past="false"]').nth(1).click();
  await page2.locator('[data-allocation-card]').first().waitFor({ state: 'visible', timeout: 15000 });
  await page2.locator('[data-select-rate="standard"]').first().click();
  await page2.locator('[data-allocation-next]').click();
  await page2.locator('[data-step-panel="2"]').waitFor({ state: 'visible', timeout: 10000 });
  await page2.locator('#guest-name').fill('Probe UX User 2');
  await page2.locator('#guest-email').fill('probe.ux2@example.com');
  await page2.locator('#guest-phone').fill('+51 999 000 999');
  await page2.locator('[data-guest-next]').click();
  await page2.locator('[data-payment-panel]').waitFor({ state: 'visible', timeout: 25000 });
  await sleep(2500);

  const padState = await page2.evaluate(() => {
    const verify = document.querySelector('[data-result-verify]');
    const penNote = document.querySelector('[data-pen-note]');
    const brick = document.getElementById('cardPaymentBrick_container');
    return {
      verifyHidden: verify ? verify.classList.contains('hidden') : 'NO_EXISTE',
      penNoteText: penNote ? penNote.textContent?.slice(0, 80) : 'NO_EXISTE',
      penNoteVisible: penNote ? !penNote.classList.contains('hidden') : false,
      brickIframes: brick ? brick.querySelectorAll('iframe').length : -1,
    };
  });
  check('boton verify OCULTO en el pad', padState.verifyHidden === true, JSON.stringify(padState.verifyHidden));
  check('nota PEN visible', padState.penNoteVisible === true, padState.penNoteText || '');
  check('nota menciona PEN y USD', /PEN/.test(padState.penNoteText || '') && /USD/.test(padState.penNoteText || ''), padState.penNoteText || '');
  check('brick montado (iframes)', padState.brickIframes > 0, `iframes=${padState.brickIframes}`);
  await page2.close();

  await browser.close();
  console.log(failures === 0 ? 'TODO VERDE' : `${failures} FALLOS`);
  process.exit(failures === 0 ? 0 : 1);
})();
