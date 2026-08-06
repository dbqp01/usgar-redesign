import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    globals: true,
    environment: 'node',
    include: ['tests/Unit/**/*.{test,spec}.ts'],
  },
});
