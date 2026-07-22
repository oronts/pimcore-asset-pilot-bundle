import { defineConfig } from 'vitest/config'
import { fileURLToPath } from 'node:url'

export default defineConfig({
  resolve: {
    alias: {
      '@pimcore/studio-ui-bundle/api': fileURLToPath(new URL('./js/test/stubs/pimcore-api.ts', import.meta.url)),
      '@pimcore/studio-ui-bundle/modules/auth': fileURLToPath(new URL('./js/test/stubs/pimcore-auth.ts', import.meta.url)),
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./js/test/setup.ts'],
    include: ['js/**/*.test.{ts,tsx}', 'scripts/**/*.test.mjs'],
    clearMocks: true,
    restoreMocks: true,
  },
})
