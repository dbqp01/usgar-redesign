// scripts/audit-seo-content.js - Audit de CONTENIDO SEO/accesibilidad sobre dist/.
//
// Complementa scripts/audit-seo-schema.js (que solo valida sintaxis JSON-LD).
// Este script comprueba que el artefacto construido cumple los invariantes de
// contenido: 404 propia, descripciones e imagenes sociales por plantilla, un
// unico H1 por pagina, numberOfRooms coherente con el inventario real, sitemap
// sin paginas de utilidad, una sola fuente de verdad para el check-in, CSP y
// MIME .avif en el .htaccess, y una sola coordenada para "encontrarnos".
//
// Uso: npm run build && npm run audit:content
// Salida: 1 si hay ERROR (bloquea), 0 si solo hay avisos.

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(__dirname, '..');
const distDir = path.join(root, 'dist');

const errors = [];
const warnings = [];
const err = (code, msg) => errors.push(`[${code}] ${msg}`);
const warn = (code, msg) => warnings.push(`[${code}] ${msg}`);

function walk(dir, acc = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(full, acc);
    else acc.push(full);
  }
  return acc;
}

if (!fs.existsSync(distDir)) {
  console.error(' Error: dist/ no existe. Ejecuta `npm run build` primero.');
  process.exit(1);
}

// ---------------------------------------------------------------- 404 propia
// Astro solo genera 404.html si existe src/pages/404.astro. Sin el, el servidor
// (hPanel) sirve su pagina de error por defecto: pierde el diseno, carga
// trackers de terceros y expone rutas internas del servidor.
if (!fs.existsSync(path.join(distDir, '404.html'))) {
  err('404', 'dist/404.html no existe. Sin src/pages/404.astro el servidor sirve su 404 por defecto (hPanel), con trackers y rutas internas filtradas.');
}

// ------------------------------------------------- contenido por pagina (dist)
// vendor/ se copia al dist por el postbuild pero public/.htaccess lo bloquea
// (RewriteRule ^(?:app|vendor|cron|logs|storage|runtime) - [F,L,NC]); sus HTML de
// ejemplo no son paginas del sitio.
const htmlFiles = walk(distDir).filter(
  (f) => f.endsWith('.html') && !f.replace(/\\/g, '/').includes('/vendor/')
);
const settings = JSON.parse(fs.readFileSync(path.join(root, 'src/content/settings/settings.json'), 'utf8'));
const defaultDescriptions = new Set(
  Object.entries(settings)
    .filter(([k]) => k.startsWith('siteDescription_'))
    .map(([, v]) => v)
);

const pages = [];
for (const file of htmlFiles) {
  const html = fs.readFileSync(file, 'utf8');
  const rel = '/' + path.relative(distDir, file).replace(/\\/g, '/').replace(/index\.html$/, '');
  const desc = (html.match(/<meta[^>]*name=["']?description["']?[^>]*content=["']([^"']*)["']/i) || [])[1]
    || (html.match(/<meta[^>]*content=["']([^"']*)["'][^>]*name=["']?description["']?/i) || [])[1];
  const ogImage = (html.match(/<meta[^>]*property=["']?og:image["']?[^>]*content=["']([^"']*)["']/i) || [])[1]
    || (html.match(/<meta[^>]*content=["']([^"']*)["'][^>]*property=["']?og:image["']?/i) || [])[1];
  const h1s = (html.match(/<h1[\s>]/gi) || []).length;
  const robots = (html.match(/<meta[^>]*name=["']?robots["']?[^>]*content=["']([^"']*)["']/i) || [])[1]
    || (html.match(/<meta[^>]*content=["']([^"']*)["'][^>]*name=["']?robots["']?/i) || [])[1];
  const canonical = (html.match(/<link[^>]*rel=["']?canonical["']?[^>]*href=["']([^"']*)["']/i) || [])[1]
    || (html.match(/<link[^>]*href=["']([^"']*)["'][^>]*rel=["']?canonical["']?/i) || [])[1];
  // Los stubs de redirect de Astro (fallback fr->en / pt->es) no son paginas de
  // contenido: llevan <meta http-equiv=refresh> + robots noindex por diseño.
  const isRedirectStub = /http-equiv=["']?refresh["']?/i.test(html);
  const schemas = [];
  for (const m of html.matchAll(/<script[^>]*type=["']?application\/ld\+json["']?[^>]*>([\s\S]*?)<\/script>/gi)) {
    try {
      const parsed = JSON.parse(m[1]);
      schemas.push(...(Array.isArray(parsed) ? parsed : [parsed]));
    } catch { /* audit-seo-schema.js ya valida la sintaxis */ }
  }
  pages.push({ rel, file, desc, ogImage, h1s, robots, canonical, schemas, html, isRedirectStub });
}

// Paginas publicas (las de utilidad no se indexan, pero se comprueba igual el H1)
const utilityPages = /^\/(panel|login|profile|my-bookings)(\/|$)/;
// La home puede usar la descripcion global del sitio: es lo correcto.
const isHome = (rel) => /^\/(es|pt)?\/?$/.test(rel);

// ---------------------------------------------------- descripcion por pagina
const usingDefault = pages.filter((p) => p.desc && defaultDescriptions.has(p.desc) && !isHome(p.rel));
if (usingDefault.length) {
  const byLocale = {};
  for (const p of usingDefault) {
    const loc = p.rel.startsWith('/es/') || p.rel === '/es/' ? 'es' : p.rel.startsWith('/fr/') || p.rel === '/fr/' ? 'fr' : p.rel.startsWith('/pt/') || p.rel === '/pt/' ? 'pt' : 'en';
    byLocale[loc] = (byLocale[loc] || 0) + 1;
  }
  err('META-DESC', `${usingDefault.length} paginas usan la descripcion global por defecto (sin texto propio): ${Object.entries(byLocale).map(([k, v]) => `${k}=${v}`).join(', ')}. Ejemplos: ${usingDefault.slice(0, 5).map((p) => p.rel).join(', ')}`);
}

// ------------------------------------------ paginas de utilidad: noindex
const utilityLeaks = pages.filter(
  (p) => utilityPages.test(p.rel) && p.robots && !/noindex/i.test(p.robots)
);
if (utilityLeaks.length) {
  err('NOINDEX', `${utilityLeaks.length} paginas de utilidad NO son noindex: ${utilityLeaks.map((p) => p.rel).join(', ')}`);
}
const utilityNoRobots = pages.filter((p) => utilityPages.test(p.rel) && !p.robots);
if (utilityNoRobots.length) {
  err('NOINDEX', `${utilityNoRobots.length} paginas de utilidad sin meta robots (quedan indexables): ${utilityNoRobots.map((p) => p.rel).join(', ')}`);
}


// ------------------------------------------------------- og:image por pagina
const ogImages = new Map();
for (const p of pages) {
  if (!p.ogImage) continue;
  ogImages.set(p.ogImage, (ogImages.get(p.ogImage) || 0) + 1);
}
if (ogImages.size === 1 && ogImages.has('https://usgarhoteles.com/og-image.jpg')) {
  err('OG-IMAGE', `las ${pages.length} paginas comparten la misma og:image (${[...ogImages.keys()][0]}). Cada plantilla deberia tener la suya.`);
}

// ------------------------------------------------------------- un solo H1
// Los stubs de redirect (fallback de locale) no son paginas de contenido.
const badH1 = pages.filter((p) => !p.isRedirectStub && p.h1s !== 1);
if (badH1.length) {
  err('H1', `${badH1.length} paginas no tienen exactamente un <h1>: ${badH1.slice(0, 8).map((p) => `${p.rel} (${p.h1s})`).join(', ')}${badH1.length > 8 ? '...' : ''}`);
}

// -------------------------------------------------- numberOfRooms vs inventario
const inventoryTotal = Object.values(settings.roomInventory || {}).reduce((a, b) => a + Number(b), 0);
for (const p of pages) {
  for (const s of p.schemas) {
    if (s['@type'] === 'Hotel' && s.numberOfRooms != null) {
      if (Number(s.numberOfRooms) !== inventoryTotal) {
        err('SCHEMA-ROOMS', `${p.rel}: numberOfRooms=${s.numberOfRooms} pero el inventario real tiene ${inventoryTotal} (${JSON.stringify(settings.roomInventory)}).`);
      }
    }
  }
}

// --------------------------------------------------------- sitemap sin utiles
const sitemapFile = path.join(distDir, 'sitemap-0.xml');
if (fs.existsSync(sitemapFile)) {
  const xml = fs.readFileSync(sitemapFile, 'utf8');
  const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  const leaked = locs.filter((u) => utilityPages.test(new URL(u).pathname));
  if (leaked.length) {
    err('SITEMAP', `${leaked.length} paginas de utilidad en el sitemap: ${leaked.slice(0, 6).join(', ')}`);
  }
  if (!/lastmod/.test(xml)) {
    warn('SITEMAP-LASTMOD', 'el sitemap no declara <lastmod>: Google no puede saber cuando cambio el precio de una habitacion.');
  }
}

// --------------------------------------------- check-in: una sola fuente de verdad
const checkinTimes = new Map();
const addCheckin = (source, value) => {
  if (!value) return;
  if (!checkinTimes.has(value)) checkinTimes.set(value, []);
  checkinTimes.get(value).push(source);
};
addCheckin('content/settings/settings.json', settings.checkinTime);
for (const loc of ['en', 'es', 'fr', 'pt']) {
  const f = path.join(root, `src/i18n/${loc}.json`);
  if (!fs.existsSync(f)) continue;
  const raw = fs.readFileSync(f, 'utf8');
  const m = raw.match(/"checkinHours"\s*:\s*"([^"]*)"/);
  const hh = m && m[1].match(/(\d{1,2}):(\d{2})/);
  if (hh) addCheckin(`src/i18n/${loc}.json (contact.checkinHours)`, `${hh[1].padStart(2, '0')}:${hh[2]}`);
}
for (const p of pages) {
  const m = p.html.match(/[Cc]heck-?in (?:from |desde |a partir de |à partir de )?(\d{1,2}:\d{2})/);
  if (m) addCheckin(`${p.rel} (texto renderizado)`, m[1]);
}
if (checkinTimes.size > 1) {
  err('CHECKIN', `hay ${checkinTimes.size} horarios de check-in distintos: ${[...checkinTimes.entries()].map(([v, s]) => `${v} (${s.length} fuentes: ${s.slice(0, 3).join(', ')})`).join(' | ')}`);
}

// ----------------------------------------------------- .htaccess: CSP, MIME, 404
const htaccess = fs.existsSync(path.join(root, 'public/.htaccess')) ? fs.readFileSync(path.join(root, 'public/.htaccess'), 'utf8') : '';
if (!/Content-Security-Policy/i.test(htaccess)) {
  // Aviso, no error: el .htaccess documenta que la CSP vive solo en el
  // Middleware PHP para que la allowlist (pagos/mapa/fuentes) no se duplique.
  // Anadirla aqui es una DECISION, no un bug: hay que replicar la allowlist
  // exacta o se rompen MercadoPago, Google Maps y las fuentes.
  warn('CSP', 'las paginas estaticas no llevan Content-Security-Policy (solo las respuestas PHP via Middleware). Decision pendiente: replicar la allowlist sin romper pagos/mapa/fuentes.');
}
if (!/AddType\s+image\/avif/i.test(htaccess)) {
  err('MIME-AVIF', 'public/.htaccess no mapea .avif a image/avif: el servidor lo sirve como text/plain.');
}
if (!/ErrorDocument\s+404/i.test(htaccess)) {
  err('ERROR-DOC', 'public/.htaccess no define ErrorDocument 404: el servidor sirve su propia pagina de error.');
}

// ------------------------------------------------- una sola coordenada de mapa
// Solo el CTA "como llegar" (maps/dir/?destination=) debe usar la coordenada del
// hotel. Los links maps/search/?query= son los POIs del entorno (mercado,
// plaza, estacion de tren) y son coordenadas distintas por diseño.
const hotelCoord = `${settings.latitude},${settings.longitude}`;
const wrongCoord = new Map();
for (const p of pages) {
  for (const m of p.html.matchAll(/maps\/dir\/?\?[^"'\s>]*?destination=(-?\d+\.\d+),(-?\d+\.\d+)/g)) {
    const key = `${m[1]},${m[2]}`;
    if (key !== hotelCoord) {
      if (!wrongCoord.has(key)) wrongCoord.set(key, new Set());
      wrongCoord.get(key).add(p.rel);
    }
  }
}
if (wrongCoord.size) {
  err('MAPA', `el CTA "como llegar" no usa la coordenada de settings.json (${hotelCoord}): ${[...wrongCoord.entries()].map(([c, s]) => `${c} en ${s.size} paginas`).join(' | ')}`);
}

// ------------------------------------------------------------------------ fin
console.log('==========================================================');
console.log(' AUDITORIA DE CONTENIDO SEO/ACCESIBILIDAD (dist/)');
console.log('==========================================================');
console.log(` Paginas examinadas: ${pages.length}`);
console.log('');
if (warnings.length) {
  console.log(' AVISOS:');
  for (const w of warnings) console.log('   ' + w);
  console.log('');
}
if (errors.length) {
  console.log(' ERRORES:');
  for (const e of errors) console.log('   ' + e);
  console.log('');
}
console.log(` RESUMEN: ${errors.length} errores, ${warnings.length} avisos`);
console.log('==========================================================\n');

process.exit(errors.length ? 1 : 0);
