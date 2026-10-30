import { existsSync, readFileSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

// Guardas SEO de artefacto: el sitemap no puede listar paginas noindex/stubs ni
// anunciar hreflang hacia redirects, /sitemap.xml debe ser XML real y lastmod no
// puede ser la hora del build. Los checks de dist se saltan si no hay build
// (CI corre test:unit antes de compilar); las guardas de fuente siempre corren.

const read = (file: string) => readFileSync(file, 'utf8');
const config = read('astro.config.mjs');
const hasDist = existsSync('dist/sitemap-0.xml') && existsSync('dist/index.html');
const attr = (tag: string, key: string) => {
  const m = tag.match(new RegExp(`\\b${key}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s>]+))`, 'i'));
  return m ? (m[1] ?? m[2] ?? m[3]) : undefined;
};

describe('sitemap config', () => {
  it('does not serve /sitemap.xml through an Astro meta-refresh redirect', () => {
    expect(config).not.toMatch(/['"]\/sitemap\.xml['"]\s*:/);
    expect(config).toMatch(/astro:build:done/);
  });

  it('does not stamp every URL with the build time as lastmod', () => {
    expect(config).not.toMatch(/lastmod\s*:\s*new Date\(\)/);
  });

  it('excludes booking success from the sitemap', () => {
    expect(config).toMatch(/book\\\/success/);
  });
});

describe.skipIf(!hasDist)('built artifact', () => {
  const pages = hasDist
    ? [...readFileSync('dist/sitemap-0.xml', 'utf8').matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => new URL(m[1]).pathname)
    : [];
  const stubs = new Set<string>();
  for (const file of hasDist ? ['fr/privacy', 'fr/terms', 'pt/privacy', 'pt/terms'] : []) {
    const html = read(`dist/${file}/index.html`);
    if (/http-equiv=["']?refresh/i.test(html)) stubs.add(`/${file}/`);
  }

  it('has four noindex booking success pages', () => {
    for (const locale of ['', 'es/', 'fr/', 'pt/']) {
      const html = read(`dist/${locale}book/success/index.html`);
      const robots = [...html.matchAll(/<meta\b[^>]*>/gi)]
        .map((m) => attr(m[0], 'name')?.toLowerCase() === 'robots' ? attr(m[0], 'content') : undefined)
        .find(Boolean);
      expect(robots, `${locale}book/success`).toMatch(/noindex/i);
    }
  });

  it('does not list success pages or redirect stubs', () => {
    expect(pages.filter((p) => /(?:^|\/)book\/success\/?$/.test(p))).toEqual([]);
    expect(pages.filter((p) => stubs.has(p))).toEqual([]);
  });

  it('does not point hreflang at redirect stubs', () => {
    const bad: string[] = [];
    for (const file of ['privacy', 'terms', 'es/privacy', 'es/terms']) {
      const dir = `dist/${file}/index.html`;
      if (!existsSync(dir)) continue;
      const html = read(dir);
      for (const tag of html.match(/<link\b[^>]*>/gi) ?? []) {
        if ((attr(tag, 'rel') ?? '').toLowerCase() !== 'alternate') continue;
        const href = attr(tag, 'href');
        if (href && stubs.has(new URL(href, 'https://usgarhoteles.com').pathname)) bad.push(`${file} -> ${href}`);
      }
    }
    expect(bad).toEqual([]);
  });

  it('serves /sitemap.xml as a real static XML sitemap index', () => {
    expect(statSync('dist/sitemap.xml').isFile()).toBe(true);
    const xml = read('dist/sitemap.xml');
    expect(xml).toMatch(/^\s*<\?xml/);
    expect(xml).toMatch(/<sitemapindex\b/);
    expect(xml).not.toMatch(/http-equiv=["']?refresh/i);
  });

  it('does not warn when lastmod is omitted rather than misleading', () => {
    const output = execFileSync(process.execPath, ['scripts/audit-seo-content.js'], { encoding: 'utf8' });
    expect(output).not.toContain('[SITEMAP-LASTMOD]');
  });

  it('does not use one build timestamp as lastmod for every URL', () => {
    const lastmods = [...read('dist/sitemap-0.xml').matchAll(/<lastmod>([^<]+)<\/lastmod>/g)].map((m) => m[1]);
    if (lastmods.length) expect(new Set(lastmods).size).toBeGreaterThan(1);
  });
});
