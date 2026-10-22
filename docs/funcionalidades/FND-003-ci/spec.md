# FND-003 · CI (integração contínua)

- **Status:** aprovada (Gustavo, 2026-10-22: delegou as decisões técnicas ao agente; consulta só para regra de negócio)
- **Fase:** 0 · Fundação
- **Plano / feature flag:** — (infraestrutura de desenvolvimento)
- **Depende de:** FND-002
- **Responsável pela spec:** agente Produto · **Aprovação:** Gustavo

## Problema e objetivo

Hoje a qualidade só é verificada por quem roda os scripts localmente. O backlog pede um pipeline que bloqueie o merge quando algo falha. Objetivo: todo PR para `develop` ou `master` roda lint, análise estática, testes e auditoria de dependências no GitHub Actions, e o resultado fica visível no PR.

**Escopo desta entrega: só a parte de CI.** O deploy em homologação do backlog original fica fora, porque o servidor ainda não existe (ADR 0004: provedor do servidor em aberto). Vira um item novo, FND-007.

## Histórias de usuário

- Como Gustavo, quero ver no PR se lint, análise estática e testes passaram, para aprovar com segurança.
- Como desenvolvedor, quero que o CI rode exatamente o que rodo localmente, para não haver surpresa.
- Como Gustavo, quero ser avisado de vulnerabilidades conhecidas nas dependências.

## Regras de negócio

- **RN-01** — O workflow roda em `pull_request` para `develop` e `master`, e em `push` para `develop`, `master` e as branches de funcionalidade (`<ID>-<slug>`), para dar retorno antes de abrir o PR.
- **RN-02** — Job `api`: Pint (`--test`), Larastan nível máximo, Pest contra PostgreSQL 16 e Redis reais (service containers), com PHP 8.4.
- **RN-03** — Job `web`: Prettier (`--check`), ESLint sem avisos, `tsc` strict e Vitest, com Node 22 e pnpm da versão fixada no `package.json`.
- **RN-04** — Job `contract`: regenerar `packages/api-client` a partir do `openapi.yaml` e falhar se houver diferença.
- **RN-05** — Job `structure`: rodar `scripts/verify-structure.sh`.
- **RN-06** — Job `audit`: `composer audit` e `pnpm audit --prod`; `pnpm audit` falha em vulnerabilidade alta ou crítica; `composer audit` falha em qualquer vulnerabilidade conhecida (não tem filtro de severidade).
- **RN-07** — O workflow usa permissões mínimas (`contents: read`), cancela execuções antigas do mesmo PR e fixa as ações por versão maior.
- **RN-08** — O pipeline não usa segredos nesta fase.
- **RN-09** — Para o CI realmente bloquear o merge, `develop` e `master` precisam de proteção de branch exigindo os jobs acima. Isso é configuração do GitHub, documentada em `docs.md`; sem ela o CI só informa.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | PR aberto para `develop` | O GitHub processa o PR | Os jobs `api`, `web`, `contract`, `structure` e `audit` rodam |
| CA-02 | RN-02, RN-03 | Código atual da `develop` | O pipeline roda | Todos os jobs passam |
| CA-03 | RN-02 | PR com violação de estilo PHP | O pipeline roda | O job `api` falha |
| CA-04 | RN-03 | PR com aviso de ESLint | O pipeline roda | O job `web` falha |
| CA-05 | RN-04 | PR que muda o `openapi.yaml` sem regenerar o cliente | O pipeline roda | O job `contract` falha |
| CA-06 | RN-06 | Dependência com vulnerabilidade alta | O pipeline roda | O job `audit` falha |
| CA-07 | RN-07 | Dois pushes seguidos no mesmo PR | O segundo chega | A execução anterior é cancelada |
| CA-08 | RN-09 | `docs.md` | Leio a seção de proteção de branch | Há o passo a passo com os nomes exatos dos jobs |

## Permissões

Não se aplica.

## API

Nenhuma.

## Dados

Nenhum.

## Eventos, auditoria e notificações

O GitHub notifica o autor do PR sobre falhas. Nada no produto.

## Impacto no mobile

Nenhum agora. O job de mobile entra com o `apps/mobile` na Fase 5.

## Fora do escopo

- Deploy em homologação e produção (novo item FND-007, depende do provedor do servidor).
- Playwright, k6, Semgrep e OWASP ZAP (entram quando houver telas de negócio e homologação).
- Dependabot (item pequeno à parte, pode entrar junto com a proteção de branch).
- Cobertura mínima de 90% (sem regra de negócio ainda; entra no primeiro item de domínio).

## Riscos e perguntas em aberto

- `composer audit` ou `pnpm audit` podem acusar vulnerabilidades já presentes; se acontecer, atualizar as dependências antes de ativar como bloqueante.
- Pushar `.github/workflows` exige que o token de acesso do GitHub tenha o escopo `workflow`.
- Sem `gh`, a proteção de branch (RN-09) precisa ser feita por Gustavo na interface do GitHub.
