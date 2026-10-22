# CI (integração contínua)

> Para quem desenvolve no repositório.

## Para que serve

Roda automaticamente, no GitHub Actions, as mesmas verificações que você roda localmente, a cada push em `develop`, `master` ou numa branch de funcionalidade (`<ID>-<slug>`) e a cada PR para `develop` ou `master`.

## Jobs

| Job | O que verifica |
| --- | --- |
| `api` | Pint, Larastan nível máximo e Pest (PostgreSQL 16 e Redis reais) |
| `web` | Prettier, ESLint sem avisos, TypeScript strict e Vitest |
| `contract` | O cliente em `packages/api-client` está igual ao gerado do `openapi.yaml` |
| `structure` | Estrutura do monorepo, agentes, skills e segredos (`scripts/verify-structure.sh`) |
| `audit` | `composer audit` e `pnpm audit --prod` (alta ou crítica) |

## Como reproduzir localmente

Veja os comandos no `CLAUDE.md`. O CI roda os mesmos.

## Fazer o CI bloquear o merge (ação do dono do repositório)

O CI só bloqueia se a proteção de branch exigir os jobs. No GitHub: **Settings → Branches → Add branch ruleset** (ou *branch protection rule*) para `develop` e para `master`:

1. Marque **Require a pull request before merging**.
2. Marque **Require status checks to pass before merging** e adicione exatamente: `api`, `web`, `contract`, `structure`, `audit`.
3. Marque **Require branches to be up to date before merging**.
4. Marque **Do not allow bypassing the above settings**, se quiser que valha também para administradores.

## Perguntas frequentes

**O job `audit` ficou vermelho sem eu ter mexido em dependência.** Uma vulnerabilidade nova foi publicada. Atualize a dependência afetada ou, se não houver correção, registre a exceção no PR.

**O job `contract` falhou.** Rode `pnpm api-client:generate` e commite o resultado.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Primeiro pipeline: api, web, contract, structure e audit |
