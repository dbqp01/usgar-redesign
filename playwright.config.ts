import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  forbidOnly: !!process.env.CI,
  use: {
    baseURL: 'http://127.0.0.1:4321',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 4321',
    // Playwright owns the process; Astro must not detach in an agent environment.
    env: { ASTRO_DEV_BACKGROUND: '0' },
    url: 'http://127.0.0.1:4321',
  },
});
