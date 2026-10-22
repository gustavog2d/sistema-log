# 04 · Stack recomendada

A recomendação é Laravel na API e TypeScript nos clientes (Next.js na web, React Native com Expo no mobile). Laravel aproveita os 15 anos de experiência do time em PHP, tem segurança madura de fábrica e um ecossistema pronto para SaaS. TypeScript nos clientes permite dividir o cliente da API, as validações e os tokens de design entre web e app.

| Camada | Escolha | Por quê |
| --- | --- | --- |
| API | PHP 8.4+ e Laravel (versão estável mais recente) | Domínio do time; Sanctum, filas, eventos, validação, hashing e proteções OWASP prontos |
| Banco | PostgreSQL 16+ | Row Level Security nativo, JSONB, constraints de exclusão para conflito de horário |
| Cache e filas | Redis + Laravel Horizon | Filas monitoráveis, retentativas, rate limiting |
| Web | Next.js + TypeScript (strict), Tailwind, shadcn/ui, TanStack Query | Página pública de agendamento com SEO; painel rápido; base de componentes acessível |
| Mobile (Fase 5) | React Native + Expo, TypeScript | Mesmo cliente da API e mesma lógica de validação da web; builds e publicação pelo EAS |
| Contrato | OpenAPI 3.1 + cliente gerado (orval ou similar) | A API e os clientes não divergem sem o CI acusar |
| Testes | Pest, Vitest + Testing Library, Playwright, k6 | Pirâmide completa: unidade, integração, contrato, ponta a ponta e carga |
| Qualidade | Larastan (nível máximo), Pint, ESLint, Prettier | Erros de tipo e estilo barrados antes do review |
| Segurança no CI | Dependabot, `composer audit`, `pnpm audit`, Semgrep, OWASP ZAP no staging | Vulnerabilidades em dependências e no código acusadas a cada PR |

**Organização do código:** um monorepo com `apps/api`, `apps/web`, `apps/mobile` (Fase 5), `packages/api-client`, `packages/ui-tokens` e `docs/`.

**Alternativa considerada:** TypeScript ponta a ponta (NestJS ou Next.js no back-end), como sugeria o prompt do Tatulog. Daria uma linguagem só, mas troca a experiência do time por uma curva de aprendizado justamente na parte mais sensível (cobrança, multi-tenant, segurança). Registrada no ADR [0001](arquitetura/adr/0001-stack.md).
