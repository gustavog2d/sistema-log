import react from '@vitejs/plugin-react';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./vitest.setup.ts'],
    globals: true,
    // A suíte é grande e roda em contêiner: testes com digitação longa estouravam os 5 s sob carga.
    testTimeout: 20_000,
    hookTimeout: 20_000,
  },
});
