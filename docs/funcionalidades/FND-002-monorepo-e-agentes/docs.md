# Monorepo, agentes e ambiente local

> Texto para quem desenvolve no repositório (não é ajuda para o usuário final). Os comandos abaixo são os mesmos do `CLAUDE.md` da raiz.

## Para que serve

Entrega o esqueleto do monorepo e um ambiente local padronizado em Docker, para que as próximas funcionalidades comecem de uma base comum:

- `apps/api` (Laravel), `apps/web` (Next.js), `packages/api-client` (gerado do OpenAPI) e `packages/ui-tokens`. `apps/mobile` só entra na Fase 5.
- Um único endpoint, `GET /api/v1/health`, que informa o estado do banco e do Redis (503 em Problem Details se algum estiver fora).
- Uma página inicial na web com texto vindo da tradução pt-BR (`apps/web/src/i18n/pt-BR.json`).
- Agentes (`.claude/agents`) e skills (`.claude/skills`) ativos no Claude Code.

Ainda não há regra de negócio, `tenant`, autenticação nem CI (ver FND-003 e FND-005). O nome do produto vem de configuração (`app.name`), nunca do código.

## Quem pode usar

Qualquer pessoa que desenvolva no repositório. Não há papéis nem plano (`plan`) envolvidos.

## Como usar

### Pré-requisitos

- Docker com Docker Compose. Não é preciso instalar PHP, Node, pnpm, PostgreSQL nem Redis na máquina; tudo roda em containers.

### Subir o ambiente

```bash
cp .env.example .env                 # primeira vez; o .env não é versionado
docker compose up -d --build --wait
docker compose down                  # derruba (com -v apaga também o banco)
```

Sobem API, worker de fila, web, PostgreSQL 16, Redis e Mailpit. Portas padrão (alteráveis no `.env`):

| Serviço | Endereço |
| --- | --- |
| API | http://localhost:8000 (`/api/v1/health`) |
| Web | http://localhost:3000 |
| Mailpit | http://localhost:8025 |
| PostgreSQL | localhost:5432 |

### Rodar testes, lint e análise estática

API (dentro do serviço `api`):

```bash
docker compose exec api vendor/bin/pest                                # todos os testes
docker compose exec api vendor/bin/pest --filter="<nome do teste>"     # um teste só
docker compose exec api vendor/bin/pint                                # formata (--test só verifica)
docker compose exec api vendor/bin/phpstan analyse --memory-limit=1G   # Larastan nível máximo
```

Web e pacotes (serviço `web`):

```bash
docker compose exec web sh -c 'cd /workspace && pnpm test'             # Vitest
docker compose exec web sh -c 'cd /workspace && pnpm --filter web exec vitest run <arquivo> -t "<nome>"'  # um teste só
docker compose exec web sh -c 'cd /workspace && pnpm lint && pnpm typecheck'
docker compose exec web sh -c 'cd /workspace && pnpm format'           # Prettier
```

Verificação completa do FND-002:

```bash
bash scripts/verify-structure.sh && bash scripts/verify-compose.sh && bash scripts/verify-quality.sh
```

O Larastan analisa só `app`, `routes` e `bootstrap` (não `tests/`).

### Regenerar o cliente da API

O `packages/api-client` é gerado com orval a partir do contrato. Depois de mudar o `openapi.yaml`, rode:

```bash
docker compose exec web sh -c 'cd /workspace && pnpm api-client:generate'
```

Por enquanto o contrato está em `docs/funcionalidades/FND-002-monorepo-e-agentes/openapi.yaml`. Não edite o cliente gerado à mão; atualize o contrato primeiro e regenere.

## Perguntas frequentes

**Os testes da API falham dizendo que o banco `app_test` não existe.**
Os testes usam PostgreSQL real (não SQLite, por causa de RLS e constraints de exclusão). O `phpunit.xml` força `DB_DATABASE=app_test`, criado por `docker/postgres/init.sql`, que só roda quando o volume do banco é criado. Se o volume já existia antes desse script, o banco não foi criado. Solução, que apaga os dados locais:

```bash
docker compose down -v
docker compose up -d --build --wait
```

**Uma porta já está em uso.** Altere `API_PORT`, `WEB_PORT`, `MAILPIT_PORT` ou `DB_PORT` no `.env` e suba de novo.

**O `docker compose up --wait` demora na primeira vez.** A web instala as dependências ao iniciar; aguarde o healthcheck (até cerca de 90 s).

**Por que o `phpstan` não analisa os testes?** As closures do Pest usam `$this`, o que o Larastan não resolve.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Monorepo inicial com `apps/api`, `apps/web`, `packages/api-client` e `packages/ui-tokens`; ambiente local em Docker Compose; endpoint `GET /api/v1/health`; comandos de lint, análise estática e teste documentados no `CLAUDE.md`; agentes e skills instalados em `.claude/`. |
