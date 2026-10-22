import { defineConfig } from 'orval';

const contract = (name: string, spec: string) => ({
  input: `../../docs/funcionalidades/${spec}/openapi.yaml`,
  output: {
    mode: 'single' as const,
    target: `./src/generated/${name}.ts`,
    client: 'fetch' as const,
    clean: false,
    override: { mutator: { path: './src/fetcher.ts', name: 'customFetch' } },
  },
});

// Um arquivo gerado por contrato de funcionalidade (o contrato único montado no CI vem depois).
export default defineConfig({
  health: contract('health', 'FND-002-monorepo-e-agentes'),
  tenant: contract('tenant', 'FND-005-base-multi-tenant'),
  platform: contract('platform', 'PLT-001-login-super-admin'),
  plans: contract('plans', 'PLT-002-catalogo-de-planos'),
  signup: contract('signup', 'PLT-003-cadastro-do-assinante'),
  subscription: contract('subscription', 'PLT-005-bloqueio-por-status'),
});
