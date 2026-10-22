import { defineConfig, fontProviders, svgoOptimizer } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import compress from '@playform/compress';

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
    prefetchAll: false,
    defaultStrategy: 'hover',
  },
  integrations: [
    sitemap({
      // lastmod = fecha del build. Los precios y la disponibilidad vienen del
      // PMS en tiempo de ejecucion, asi que la fecha del build es la unica
      // senal fiable de "esto puede haber cambiado": sin ella Google no tiene
      // motivo para volver a rastrear las paginas de habitaciones.
      lastmod: new Date(),
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
      // Las paginas de utilidad no son contenido publico: son superficies
      // autenticadas (login, panel, perfil, mis reservas). Indexarlas no aporta
      // nada y expone una superficie del sitio.
      filter: (page) => !/^\/(?:[a-z]{2}\/)?(?:panel|login|profile|my-bookings)(?:\/|$)/.test(new URL(page).pathname),
    }),
    // Compress (HTML & JS minification)
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
        webp: { effort: 6, quality: 78 },
        avif: { effort: 3, quality: 62 },
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
  // Astro con i18n + fallback genera una ruta duplicada para la raiz de cada
  // locale con fallback (/fr) que choca con la ruta de archivo
  // (src/pages/fr/index.astro -> /fr). Gana la ruta de archivo —la correcta,
  // la que sirve la home en=fr— y el aviso es ruido: con "error" el build no
  // terminaria. Las URLs canonicas son con barra final (/fr/), que es lo que
  // ya publica el sitemap.
  prerenderConflictBehavior: 'ignore',
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
