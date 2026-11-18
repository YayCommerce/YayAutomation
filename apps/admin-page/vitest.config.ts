import path from 'path';
import { defineConfig } from 'vitest/config';

// YAYMATIK_EDITION=lite (pnpm test:lite): the free edition, without src/pro and with the free plugin's PHP meta.
const lite = process.env.YAYMATIK_EDITION === 'lite';

export default defineConfig({
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@edition': path.resolve(__dirname, lite ? './src/lite' : './src/pro'),
      '@test-meta': path.resolve(
        __dirname,
        lite ? './src/test/fixtures/meta-lite.json' : './src/test/fixtures/meta.json',
      ),
    },
  },
  test: {
    environment: 'happy-dom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    // Each edition's own tests: src/lite and src/pro are never both loaded.
    exclude: lite ? ['src/pro/**'] : ['src/lite/**'],
  },
});
