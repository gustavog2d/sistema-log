import { defineConfig } from 'orval';

export default defineConfig({
  api: {
    input: '../../docs/funcionalidades/FND-002-monorepo-e-agentes/openapi.yaml',
    output: {
      mode: 'single',
      target: './src/generated/api.ts',
      client: 'fetch',
      clean: true,
      override: { mutator: { path: './src/fetcher.ts', name: 'customFetch' } },
    },
  },
});
