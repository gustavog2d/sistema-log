# FND-006 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02 | Unidade | `packages/ui-tokens/src/tokens.test.ts` | passando |
| T-02 | CA-03, CA-04, CA-05 | Componente | `apps/web/src/components/ui/*.test.tsx` | passando |
| T-03 | CA-06, CA-07 | Componente | `apps/web/src/components/layout/*.test.tsx` | passando |
| T-04 | CA-08 | Varredura | `apps/web/src/components/ui/no-hardcoded-text.test.ts` | passando |
| T-05 | CA-09, CA-10, CA-11 | Servidor | `apps/web/src/server/bff.test.ts` | passando |
| T-06 | CA-12 | Servidor | `apps/web/src/server/host-routing.test.ts` | passando |
| T-07 | CA-13 | Unidade | `apps/web/src/lib/api.test.ts` | passando |

## Casos obrigatórios

Cobertos: paridade e contraste dos tokens, acessibilidade (axe) de todos os componentes e layouts, ligação rótulo/dica/erro, diálogo (foco, Tab, Esc), ausência de texto fixo, ponte com a API (cookie HttpOnly, CSRF, lista de caminhos, redirecionamento), roteamento por endereço e tradução de erros.

Verificação manual (2026-10-23): a página `/guia-de-estilo` abre em `http://localhost:3000` e não existe em endereço de assinante (404).
