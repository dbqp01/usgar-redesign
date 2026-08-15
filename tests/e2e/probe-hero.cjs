// Probe: estado real del hero en el dev server — visibilidad de título,
// subtítulo, botones; clases; transform/opacity aplicados por la animación.
const { chromium } = require('@playwright/test');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  await page.goto('http://localhost:4321/', { waitUntil: 'networkidle' });
  // Esperar a que la animación de entrada corra (preloader ~900ms + tweens)
  await page.waitForTimeout(3000);

  const hero = await page.evaluate(() => {
    const q = (sel) => document.querySelector(sel);
    const style = (el) => {
      if (!el) return null;
      const cs = getComputedStyle(el);
      const r = el.getBoundingClientRect();
      return {
        opacity: cs.opacity,
        visibility: cs.visibility,
        display: cs.display,
        transform: cs.transform,
        w: Math.round(r.width),
        h: Math.round(r.height),
        top: Math.round(r.top),
        text: (el.textContent || '').trim().slice(0, 60),
        cls: el.className.toString().slice(0, 80),
      };
    };
    const h1 = q('#hero-title-reveal');
    return {
      title: style(h1),
      subtitle: style(q('#hero p')),
      buttons: style(q('#hero .flex-col.sm\\:flex-row')),
      heroContent: style(q('#hero .relative.z-10')),
      hasWipe: !!q('#hero-wipe'),
      heroCls: q('#hero')?.className,
      titleLines: h1 ? Math.round(h1.getBoundingClientRect().height / parseFloat(getComputedStyle(h1).lineHeight)) : null,
    };
  });

  console.log(JSON.stringify(hero, null, 2));
  console.log('--- page errors:', errors.length ? errors.slice(0, 3) : 'none');

  // Screenshot del hero para evidencia visual
  await page.screenshot({ path: 'C:/Users/akim/Desktop/usgar-redesign/tests/e2e/hero-current.png' });
  await browser.close();
})().catch((e) => { console.error('CRASH:', e); process.exit(1); });
