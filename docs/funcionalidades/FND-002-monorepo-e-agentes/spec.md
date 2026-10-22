# FND-002 · Monorepo, CLAUDE.md, agentes e skills iniciais

- **Status:** aprovada (Gustavo, 2026-10-22)
- **Fase:** 0 · Fundação
- **Plano / feature flag:** — (infraestrutura de desenvolvimento, sem relação com planos)
- **Depende de:** FND-001 (ADR 0001 aceito; ADR 0004 aceito)
- **Responsável pela spec:** agente Produto · **Aprovação:** Gustavo

## Problema e objetivo

Hoje o repositório só tem documentação. Sem esqueleto de código, comandos padronizados e agentes carregados, cada funcionalidade seguinte (FND-003 em diante) teria de decidir estrutura, ferramentas e convenções por conta própria. O objetivo é entregar um monorepo mínimo que roda localmente em Docker, com lint, análise estática e testes executáveis por um único comando por camada, e com agentes e skills ativos no Claude Code.

Sucesso: um desenvolvedor clona o repositório, roda os comandos documentados no `CLAUDE.md` e vê API e web sobem localmente e a suíte de testes (ainda vazia de regra de negócio) passa.

## Histórias de usuário

- Como desenvolvedor, quero subir o ambiente local com um comando, para começar a trabalhar sem instalar PHP, Postgres e Redis na máquina.
- Como desenvolvedor, quero um comando por ferramenta (lint, análise estática, teste), para rodar o mesmo que o CI rodará.
- Como Gustavo, quero agentes e skills ativos em `.claude/`, para que o fluxo de [docs/10](../../10-fluxo-de-agentes.md) funcione.
- Como agente, quero o `CLAUDE.md` com comandos reais de build e teste, incluindo como rodar um teste único.

## Regras de negócio

- **RN-01** — O monorepo segue a estrutura prevista: `apps/api`, `apps/web`, `packages/api-client`, `packages/ui-tokens`, `docs/`, `.claude/`. `apps/mobile` NÃO é criado agora (Fase 5).
- **RN-02** — Todo o ambiente local roda em containers via Docker Compose (ADR 0004): API, worker de fila, web, PostgreSQL 16+, Redis e Mailpit.
- **RN-03** — Nenhum nome de produto, domínio, e-mail ou cor fica no código; vêm de configuração (ver convenções do `CLAUDE.md`). Termos de interface ficam em arquivos de tradução (pt-BR).
- **RN-04** — A API nasce com PHP 8.4+, Laravel (versão estável mais recente), Pest, Larastan em nível máximo e Pint; a web com Next.js, TypeScript `strict`, Tailwind, ESLint, Prettier e Vitest (ADR 0001).
- **RN-05** — O banco de teste é PostgreSQL real (não SQLite), porque RLS e constraints de exclusão não existem em SQLite.
- **RN-06** — Este item NÃO implementa multi-tenant, autenticação nem regra de negócio (isso é FND-005 em diante). Só há um endpoint de saúde `GET /api/v1/health` para provar a pilha de ponta a ponta.
- **RN-07** — `.claude/` e `.vscode/` são criados a partir de `setup/` e a pasta `setup/` é removida depois, como prevê o README.
- **RN-08** — O `CLAUDE.md` é atualizado com os comandos reais (instalar, subir, lint, análise estática, teste completo, teste único) e deixa de dizer "não há build nem testes".
- **RN-09** — Segredos ficam fora do repositório: `.env.example` versionado, `.env` ignorado.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Repositório recém-clonado | Listo a raiz | Existem `apps/api`, `apps/web`, `packages/api-client`, `packages/ui-tokens`; não existe `apps/mobile` |
| CA-02 | RN-02 | Docker instalado | Rodo o comando de subir o ambiente | Sobem API, worker, web, Postgres, Redis e Mailpit, todos saudáveis |
| CA-03 | RN-06 | Ambiente no ar | Chamo `GET /api/v1/health` | 200 com o estado de banco e Redis |
| CA-04 | RN-06 | Ambiente no ar | Abro a web | Uma página inicial renderiza texto vindo do arquivo de tradução pt-BR |
| CA-05 | RN-04 | Ambiente no ar | Rodo lint e análise estática da API e da web | Terminam com sucesso, sem avisos |
| CA-06 | RN-04, RN-05 | Ambiente no ar | Rodo os testes da API e da web | Passam; os da API usam PostgreSQL real |
| CA-07 | RN-04 | Teste de exemplo | Rodo um único teste pelo comando documentado | Só aquele teste executa |
| CA-08 | RN-03 | Código dos apps | Busco o nome provisório do produto | Não aparece em código-fonte, só em configuração e docs |
| CA-09 | RN-07 | Instalação concluída | Listo a raiz | `.claude/agents` (10 agentes), `.claude/skills` (11 skills) e `.vscode/` existem; `setup/` não |
| CA-10 | RN-08 | `CLAUDE.md` atualizado | Leio a seção de comandos | Cada comando listado roda como descrito |
| CA-11 | RN-09 | Repositório | Procuro `.env` versionado | Só `.env.example` existe |

## Permissões

Não se aplica (sem usuários nem rotas autenticadas nesta funcionalidade).

## API

Contrato em `openapi.yaml` desta pasta (a escrever pelo agente Arquiteto).

| Método | Rota | Descrição | Erros específicos |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | Saúde da API, do banco e do Redis | 503 (Problem Details) se banco ou Redis indisponível |

O `packages/api-client` é gerado a partir desse contrato e a web consome o health pelo cliente gerado, provando o fluxo contrato → cliente → tela.

## Dados

Sem tabelas de negócio nesta funcionalidade (sem `tenant_id` nem RLS ainda; ver FND-005). Apenas as tabelas padrão do framework necessárias para filas e migrations. Migration compatível com a versão anterior: sim (nenhuma versão anterior).

## Eventos, auditoria e notificações

Nenhum.

## Impacto no mobile

Nenhum agora. O contrato `/api/v1` e o cliente gerado nascem já no formato que o app usará.

## Fora do escopo

- CI/CD (FND-003); infraestrutura de homologação (FND-004); multi-tenant e RLS (FND-005); design system (FND-006).
- Qualquer entidade do domínio, autenticação ou tela de negócio.
- `apps/mobile` e Terraform/AWS.

## Riscos e perguntas em aberto

- **Gerenciador de pacotes:** `pnpm` com workspaces (decidido).
- **Gerador do cliente:** orval ou similar (docs/04 diz "orval ou similar"). Orval (decidido).
- **Critério "lint e testes rodando no CI" (backlog):** o CI é FND-003, que depende deste item. Decidido: aqui provar que os comandos rodam localmente e em container; o CI os executa em FND-003.
- **Versões:** definir PHP, Laravel, Node e Postgres exatos na hora da implementação, pela última estável.
- **Agentes e skills:** os arquivos em `setup/` são rascunhos. Revisá-los neste item ou só instalá-los? Decidido: só instalar agora, revisar a `escrever-spec` antes da primeira spec da Fase 1 (como diz docs/11).
