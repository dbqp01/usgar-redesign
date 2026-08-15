// Probe: navbar con logo solo (sin wordmark) — tamaño del logo, ausencia del
// texto USGAR al lado, altura del navbar intacta.
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.goto('http://localhost:4321/', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  const nav = await page.evaluate(() => {
    const link = document.querySelector('header a[aria-label="USGAR Hotels Home"]');
    const logo = link?.querySelector('svg');
    const r = logo?.getBoundingClientRect();
    const header = document.querySelector('header');
    return {
      logoW: Math.round(r?.width || 0),
      logoH: Math.round(r?.height || 0),
      linkText: link?.textContent?.trim() || '',
      hasWordmarkSpan: !!link?.querySelector('span'),
      headerH: Math.round(header?.getBoundingClientRect().height || 0),
    };
  });

  console.log(JSON.stringify(nav, null, 2));
  const ok = nav.logoW > 30 && nav.logoH > 30 && nav.hasWordmarkSpan === false && nav.linkText === '';
  console.log(ok ? 'PASS: logo solo, agrandado, sin wordmark' : 'FAIL');
  await page.screenshot({ path: 'C:/Users/akim/Desktop/usgar-redesign/tests/e2e/navbar-logo.png', clip: { x: 0, y: 0, width: 600, height: 120 } });
  await browser.close();
  process.exit(ok ? 0 : 1);
})().catch((e) => { console.error('CRASH:', e); process.exit(1); });
