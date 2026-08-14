// Verificacion del fix (2026-08-14): tras fallo de createHold o Back del pago,
// el wizard DEBE volver al guest form (step 2 visible). Exit 0 = fix OK.
// Uso: node tests/e2e/probe-fix-recovery.cjs
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

  // === Escenario A: fallo de red en createHold ===
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.addInitScript(() => sessionStorage.setItem('usgar_loaded', 'true'));
  await page.route('**/api/booking', (route) => route.abort('connectionfailed'));
  await page.goto(BASE + '/book', { waitUntil: 'networkidle' });

  await page.locator('[data-calendar-month="0"] [data-calendar-day]').first().waitFor({ state: 'attached', timeout: 20000 });
  const pop = page.locator('[data-calendar-popover]');
  if (await pop.evaluate((el) => el.classList.contains('hidden'))) {
    await page.locator('#checkin').click();
    await pop.waitFor({ state: 'visible', timeout: 5000 });
  }
  const d1 = page.locator('[data-calendar-day][data-past="false"]').first();
  const d2 = page.locator('[data-calendar-day][data-past="false"]').nth(1);
  await d1.click();
  await d2.click();
  await page.locator('[data-allocation-card]').first().waitFor({ state: 'visible', timeout: 15000 });
  await page.locator('[data-select-rate="standard"]').first().click();
  await page.locator('[data-allocation-next]').click();
  await page.locator('[data-step-panel="2"]').waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#guest-name').fill('Probe Fix User');
  await page.locator('#guest-email').fill('probe.fix@example.com');
  await page.locator('#guest-phone').fill('+51 999 000 666');
  await page.locator('[data-guest-next]').click();
  await sleep(5000);

  const a = await page.evaluate(() => {
    const p2 = document.querySelector('[data-step-panel="2"]');
    const guest = document.querySelector('[data-guest-step]');
    const guestRect = guest?.getBoundingClientRect();
    return {
      panel2Hidden: p2?.classList.contains('hidden'),
      panel2Inert: p2?.hasAttribute('inert'),
      guestW: Math.round(guestRect?.width || 0),
      guestH: Math.round(guestRect?.height || 0),
      guestNextVisible: (() => {
        const el = document.querySelector('[data-guest-next]');
        const r = el?.getBoundingClientRect();
        return r ? r.width > 0 && r.height > 0 : false;
      })(),
    };
  });
  check('A: panel2 visible tras fallo', !a.panel2Hidden && !a.panel2Inert, JSON.stringify(a));
  check('A: guest form visible', a.guestW > 0 && a.guestH > 0, `${a.guestW}x${a.guestH}`);
  check('A: boton next clickeable', a.guestNextVisible);
  await page.close();

  // === Escenario B: Back del paso de pago (flujo feliz hasta el pad) ===
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
  await page2.locator('#guest-name').fill('Probe Fix User B');
  await page2.locator('#guest-email').fill('probe.fixb@example.com');
  await page2.locator('#guest-phone').fill('+51 999 000 777');
  await page2.locator('[data-guest-next]').click();
  await page2.locator('[data-payment-panel]').waitFor({ state: 'visible', timeout: 25000 });
  await sleep(2500); // dejar montar el brick

  const backBtn = page2.locator('[data-payment-back]');
  check('B: boton back presente', (await backBtn.count()) > 0);
  if (await backBtn.count()) {
    await backBtn.click();
    await sleep(2000);
    const b = await page2.evaluate(() => {
      const p2 = document.querySelector('[data-step-panel="2"]');
      const guest = document.querySelector('[data-guest-step]');
      const r = guest?.getBoundingClientRect();
      return {
        panel2Hidden: p2?.classList.contains('hidden'),
        panel2Inert: p2?.hasAttribute('inert'),
        guestW: Math.round(r?.width || 0),
        guestH: Math.round(r?.height || 0),
        guestNextVisible: (() => {
          const el = document.querySelector('[data-guest-next]');
          const rr = el?.getBoundingClientRect();
          return rr ? rr.width > 0 && rr.height > 0 : false;
        })(),
      };
    });
    check('B: panel2 visible tras back', !b.panel2Hidden && !b.panel2Inert, JSON.stringify(b));
    check('B: guest form visible tras back', b.guestW > 0 && b.guestH > 0, `${b.guestW}x${b.guestH}`);
    check('B: boton next clickeable tras back', b.guestNextVisible);
  }
  await page2.close();

  await browser.close();
  console.log(failures === 0 ? 'TODO VERDE' : `${failures} FALLOS`);
  process.exit(failures === 0 ? 0 : 1);
})();
