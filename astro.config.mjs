import { defineConfig, fontProviders, svgoOptimizer } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import compress from '@playform/compress';
import critters from 'astro-critters';

export default defineConfig({
  site: 'https://usgarhoteles.com',
  output: 'static',
  experimental: {
    // Optimizacion nativa de Astro (SVGO en build): el logo SVG importado
    // como componente (Navbar/Footer) se optimiza en cada build (2026-08-16).
    svgOptimizer: svgoOptimizer(),
  },
  redirects: {
    // Ahrefs/GSC piden /sitemap.xml (404 actual); el sitemap real es /sitemap-index.xml
    '/sitemap.xml': '/sitemap-index.xml',
  },
  fonts: [
    {
      provider: fontProviders.local(),
      name: 'Montserrat',
      cssVariable: '--font-montserrat',
      display: 'optional',
      options: {
        variants: [
          { weight: '300 700', style: 'normal', src: ['./src/assets/fonts/montserrat-latin.woff2'] },
          { weight: '300 700', style: 'normal', src: ['./src/assets/fonts/montserrat-latin-ext.woff2'] },
        ],
      },
    },
    {
      provider: fontProviders.local(),
      name: 'Playfair Display',
      cssVariable: '--font-playfair',
      display: 'optional',
      options: {
        variants: [
          { weight: '400 700', style: 'normal', src: ['./src/assets/fonts/playfair-latin.woff2'] },
          { weight: '400 700', style: 'normal', src: ['./src/assets/fonts/playfair-latin-ext.woff2'] },
          { weight: '400', style: 'italic', src: ['./src/assets/fonts/playfair-italic-latin.woff2'] },
          { weight: '400', style: 'italic', src: ['./src/assets/fonts/playfair-italic-latin-ext.woff2'] },
        ],
      },
    },
    {
      provider: fontProviders.local(),
      name: 'A Akhin Tahun',
      cssVariable: '--font-ak',
      display: 'optional',
      options: {
        variants: [
          { weight: '400', style: 'normal', src: ['./src/assets/fonts/AkhirTahun.woff2'] },
        ],
      },
    },
  ],
  prefetch: {
    prefetchAll: true,
    defaultStrategy: 'viewport',
  },
  integrations: [
    sitemap({
      i18n: {
        defaultLocale: 'en',
        locales: {
          en: 'en',
          es: 'es',
          fr: 'fr',
          pt: 'pt',
        },
      },
      // Sin noticias/videos/imágenes en el sitio: excluir esos namespaces
      // (xhtml queda activo: el sitemap usa alternates hreflang vía i18n)
      namespaces: { news: false, video: false, image: false },
    }),
    // Inline del CSS crítico (above-the-fold) + carga diferida del resto:
    // elimina las 2 peticiones CSS render-blocking del primer paint
    // allowRules [/\.dark/] — CRITICO: critters filtra TODO selector que no matchee
    // el DOM estático (sin clase .dark) y purga las utilities `dark:*` de Tailwind
    // (aunque pruneSource:false) → el toggle dark muere en prod.
    critters({
      pruneSource: false,
      allowRules: [/\.dark/],
    }),
    // Compress ULTIMO (mandato README PlayForm/Compress): minifica el CSS
    // crítico que critters acaba de inlinear
    compress({
      CSS: false, // Tailwind 4 ya minifica vía Lightning CSS
      HTML: true,
      JavaScript: true,
      Image: false, // Ya optimizadas con sharp
      SVG: false,
    }),
  ],
  image: {
    // Responsive images: breakpoints propios + layout constrained.
    // responsiveStyles queda false (default) — Tailwind 4 provee el estilado
    breakpoints: [480, 960, 1600],
    layout: 'constrained',
    service: {
      entrypoint: 'astro/assets/services/sharp',
      config: {
        limitInputPixels: false,
        webp: { effort: 6, quality: 90 },
        avif: { effort: 3, quality: 85 },
        jpeg: { mozjpeg: true, quality: 85 },
        png: { effort: 6, quality: 90 },
      },
    },
  },
  vite: {
    plugins: [tailwindcss()],
    server: {
      proxy: {
        '/api': {
          target: 'http://localhost:8000',
          changeOrigin: true
        }
      }
    }
  },
  // Official Astro v7 i18n Routing & Page Fallback configuration.
  // Page-level fallback (routing) is defined here; translation key fallback is managed in src/i18n/utils.ts
  i18n: {
    defaultLocale: 'en',
    locales: ['en', 'es', 'fr', 'pt'],
    routing: {
      prefixDefaultLocale: false,
      fallbackType: 'redirect'
    },
    fallback: {
      fr: 'en',
      pt: 'es'
    }
  }
});
