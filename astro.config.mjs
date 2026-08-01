import { defineConfig } from 'astro/config';
import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';
import critters from '@otterlord/astro-critters';
import compress from '@playform/compress';

export default defineConfig({
  site: 'https://hotelesusgar.com',
  output: 'static',
  prefetch: {
    prefetchAll: false,
    defaultStrategy: 'hover',
  },
  integrations: [
    sitemap(),
    critters(),
    compress({
      CSS: false, // Tailwind 4 ya minifica vía Lightning CSS
      HTML: true,
      JavaScript: true,
      Image: false, // Ya optimizadas con sharp
      SVG: false,
    }),
  ],
  image: {
    service: {
      entrypoint: 'astro/assets/services/sharp',
      config: {
        limitInputPixels: false,
        webp: { effort: 6, quality: 90 },
        avif: { effort: 6, quality: 85 },
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
