import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  forbidOnly: !!process.env.CI,
  // El dev server de Astro compila las paginas bajo demanda: su primera
  // respuesta tarda 20-35 s en esta maquina. Con el timeout por defecto de
  // Playwright (30 s) los primeros tests de la suite se quedan sin margen y
  // fallan por tiempo, no por asercion. Este timeout solo amplia el margen
  // del test; los expects internos (5 s) siguen mandando.
  timeout: 60_000,
  use: {
    baseURL: 'http://127.0.0.1:4321',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 4321',
    // Playwright owns the process; Astro must not detach in an agent environment.
    env: { ASTRO_DEV_BACKGROUND: '0' },
    url: 'http://127.0.0.1:4321',
    // El poll de readiness compila la home; 120 s deja margen a un hardware lento.
    timeout: 120_000,
  },
});
