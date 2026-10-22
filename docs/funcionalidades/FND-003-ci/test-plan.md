# FND-003 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

CI é infraestrutura: os testes são execuções reais do pipeline no GitHub, observadas pela API pública de Actions.

## Matriz critério × camada

| Caso | Critério | Camada | Como se prova | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | Execução real | PR/push dispara os 5 jobs | passando |
| T-02 | CA-02 | Execução real | Todos os jobs verdes na `develop` | passando |
| T-03 | CA-03 | Negativo | Branch descartável com arquivo PHP fora do padrão: job `api` vermelho (passo Pint) | passando |
| T-04 | CA-04 | Negativo | Branch descartável com variável não usada: job `web` vermelho | passando |
| T-05 | CA-05 | Negativo | Branch descartável alterando o `openapi.yaml` sem regenerar: job `contract` vermelho | passando |
| T-06 | CA-06 | Documental | `composer audit` e `pnpm audit` rodam e o limite de severidade está no workflow (vulnerabilidade alta real não é simulada) | passando |
| T-07 | CA-07 | Inspeção | `concurrency` com `cancel-in-progress` no workflow | passando |
| T-08 | CA-08 | Documental | `docs.md` com a proteção de branch | passando |
| T-09 | RN-02 | Execução local | Os mesmos comandos do workflow passam no clone limpo (já feito no FND-002) | passando |

## Casos obrigatórios

Não se aplicam permissão, plano, tenant, idempotência, fuso nem acessibilidade (sem funcionalidade de produto).

## Dados de teste

Branches descartáveis `ci-negativo-*`, apagadas depois dos testes negativos.

## Fora do plano

Deploy, Semgrep, ZAP, Playwright e k6.
