// scripts/audit-csp.mjs
// Verifica la Content-Security-Policy de las paginas ESTATICAS.
//
// La cabecera vive en public/.htaccess (las paginas del build). Este script
// la extrae de ahi — la misma que se sube a produccion — sirve dist/ con ella
// y carga cada pagina en Chromium: si la allowlist esta incompleta, el
// navegador lo dice ("Refused to ...") y el script falla.
//
// Uso: npm run build && npm run audit:csp
// Salida: 1 si hay alguna violacion, 0 si ninguna.

import fs from 'fs';
import http from 'http';
import path from 'path';
import { fileURLToPath } from 'url';
import { chromium } from 'playwright';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const distDir = path.join(root, 'dist');

// --------------------------------------------------- la CSP que se va a subir
const htaccess = fs.readFileSync(path.join(root, 'public/.htaccess'), 'utf8');
const cspMatch = htaccess.match(/Header always set Content-Security-Policy\s+"([^"]+)"/i);
if (!cspMatch) {
  console.error('ERROR: public/.htaccess no define Content-Security-Policy.');
  process.exit(1);
}
const csp = cspMatch[1].replace(/\s+/g, ' ').trim();
console.log('CSP: ' + csp.slice(0, 120) + '...\n');

// ------------------------------------------------------- servidor de dist/
const TYPES = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css',
  '.svg': 'image/svg+xml', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg', '.png': 'image/png',
  '.webp': 'image/webp', '.avif': 'image/avif', '.woff2': 'font/woff2', '.mp4': 'video/mp4',
  '.json': 'application/json', '.xml': 'application/xml', '.txt': 'text/plain',
};

const server = http.createServer((req, res) => {
  let urlPath = decodeURIComponent(req.url.split('?')[0]);
  let file = path.join(distDir, urlPath);
  if (urlPath.endsWith('/')) file = path.join(file, 'index.html');
  if (!fs.existsSync(file) || fs.statSync(file).isDirectory()) {
    if (!fs.existsSync(file + '.html')) { res.writeHead(404); res.end('not found'); return; }
    file = file + '.html';
  }
  const headers = { 'Content-Type': TYPES[path.extname(file)] || 'application/octet-stream' };
  if (file.endsWith('.html')) headers['Content-Security-Policy'] = csp;
  res.writeHead(200, headers);
  fs.createReadStream(file).pipe(res);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const base = `http://127.0.0.1:${server.address().port}`;

// ------------------------------------------------------------- paginas a probar
const pages = [];
const walk = (dir) => {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) { if (entry.name !== 'vendor' && entry.name !== '_astro') walk(full); }
    else if (entry.name === 'index.html' || entry.name === '404.html') {
      // Los stubs de redirect de Astro (meta refresh) no son paginas: al
      // cargarlos el navegador navegua y destruye el contexto de la pagina.
      if (/http-equiv=["']?refresh["']?/i.test(fs.readFileSync(full, 'utf8'))) continue;
      pages.push('/' + path.relative(distDir, full).replace(/\\/g, '/').replace(/index\.html$/, ''));
    }
  }
};
walk(distDir);

// ------------------------------------------------------------------- recorrido
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const violations = [];
let checked = 0;

for (const p of pages) {
  const page = await context.newPage();
  const found = [];
  page.on('console', (m) => {
    const text = m.text();
    if (/Content Security Policy|Refused to/i.test(text)) found.push(text.slice(0, 300));
  });
  page.on('pageerror', (e) => {
    if (/Content Security Policy|Refused to/i.test(e.message)) found.push('pageerror: ' + e.message.slice(0, 300));
  });
  try {
    await page.goto(base + p, { waitUntil: 'domcontentloaded', timeout: 30000 });
    // Scroll hasta el final: dispara el mapa de Leaflet (tiles de Carto) y las
    // imagenes lazy, que son los recursos que mas se escapan de una allowlist.
    await page.evaluate(async () => {
      const step = window.innerHeight;
      for (let y = 0; y < document.body.scrollHeight; y += step) {
        window.scrollTo(0, y);
        await new Promise((r) => setTimeout(r, 120));
      }
    });
    await page.waitForTimeout(1200);
    checked++;
  } catch (e) {
    found.push('timeout/error al cargar: ' + e.message.split('\n')[0]);
  }
  if (found.length) violations.push({ page: p, found });
  await page.close();
}

await browser.close();
server.close();

console.log(`Paginas comprobadas: ${checked}/${pages.length}`);
if (violations.length) {
  console.log(`\nVIOLACIONES: ${violations.length}`);
  for (const v of violations) {
    console.log(`\n  ${v.page}`);
    for (const f of v.found) console.log('    - ' + f);
  }
  process.exit(1);
}
console.log('\nSin violaciones de CSP.');
