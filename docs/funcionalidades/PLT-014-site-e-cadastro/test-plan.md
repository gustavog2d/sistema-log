# PLT-014 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | Componente | `components/site/landing.test.tsx` | passando |
| T-02 | CA-02 a CA-05 | Componente | `components/site/contact-form.test.tsx` | passando |
| T-03 | CA-06, CA-07 | Componente | `components/site/signup-flow.test.tsx` | passando |
| T-04 | CA-08 a CA-13, CA-16 | Componente | `components/site/signup-flow.test.tsx` | passando |
| T-05 | CA-14 | Componente | `components/site/find-studio.test.tsx` | passando |
| T-06 | CA-15 | Acessibilidade | os testes acima com axe | passando |
| T-07 | CA-17 | Varredura | `components/ui/no-hardcoded-text.test.ts` (inclui `site/`) | passando |
| T-08 | utilitários | Unidade | `lib/lib.test.ts` | passando |

## Casos obrigatórios

Cobertos: planos e preços da API, contato com origem/consentimento/anti-robô, campo isca, passos do cadastro com validação e foco, disponibilidade do endereço com atraso, cupom e cortesia, cartão de teste, idempotência, erros por passo, senha fora de URL e armazenamento, acessibilidade (axe) de cada passo e página, varredura de texto fixo.

Verificação manual (2026-10-23, Chrome, `http://localhost:3000`): landing com os planos reais da API; cadastro completo (negócio, você, plano, cartão de teste, termos) criou o negócio `estudio-tinta-viva`, a dona e a assinatura em teste, mostrou `/cadastro/pronto` e o e-mail de boas-vindas chegou no Mailpit; `POST /bff/leads` gravou um lead. Achado da verificação: o `fetch` do Node ignora o cabeçalho `Host`, e a API descobre o negócio por ele; a ponte passou a usar `hostAwareFetch` (`src/server/upstream.ts`, com teste).
