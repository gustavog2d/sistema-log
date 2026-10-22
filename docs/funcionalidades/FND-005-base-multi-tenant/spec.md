# FND-005 · Base multi-tenant

- **Status:** aprovada (decisões técnicas delegadas por Gustavo em 2026-10-22; ADR 0002 aceito)
- **Fase:** 0 · Fundação
- **Plano / feature flag:** —
- **Depende de:** FND-002, FND-003, ADR 0002
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Toda a plataforma depende de nunca misturar dados de assinantes. Esta funcionalidade entrega a base técnica que todas as outras usam: o conceito de tenant, a resolução do tenant por subdomínio, o isolamento no banco com Row Level Security (RLS) e a suíte de isolamento que bloqueia o PR quando uma rota ou tabela vaza dados. Sucesso: o teste "tenant A não lê nem altera dado do tenant B" passa para toda tabela e rota de tenant, e uma rota ou tabela nova sem declaração de isolamento quebra o CI.

## Histórias de usuário

- Como desenvolvedor, quero declarar uma tabela de negócio com uma linha e ganhar `tenant_id` e política RLS, para não esquecer o isolamento.
- Como desenvolvedor, quero que consultas de modelos de tenant sejam filtradas automaticamente, para que um erro meu não vaze dados.
- Como Gustavo, quero que o CI falhe se alguém criar rota ou tabela sem declarar o isolamento.
- Como usuário de um assinante, quero acessar meu negócio por `meunegocio.dominio`.

## Regras de negócio

- **RN-01** — `tenants` é tabela de plataforma (sem `tenant_id`, sem RLS): `id` (UUID), `name`, `slug` (único), `status` (`active`, `suspended`), `timezone` (padrão `America/Sao_Paulo`), `currency` (padrão `BRL`), `created_at`, `updated_at`.
- **RN-02** — O `slug` segue `^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$` (3 a 30 caracteres) e não pode estar na lista de reservados em configuração (`www`, `admin`, `api`, `app`, `mail`, `static`, `assets`, `status`, `docs`, `blog`, `suporte`, `help`). A lista é provisória e será revista no PLT-003.
- **RN-03** — O tenant é resolvido pelo subdomínio: host `{slug}.{base_domain}`, com `base_domain` em configuração (`APP_BASE_DOMAIN`). A resolução pelo token do mobile entra no PLT-006, quando houver autenticação; esta funcionalidade deixa a interface `TenantResolver` pronta para ele.
- **RN-04** — Rotas são declaradas em um de três grupos: `tenant` (exige tenant resolvido), `platform` (host da plataforma, proíbe tenant) ou `public` (sem tenant, sem dado de negócio). Rota fora dos três grupos falha no teste de registro.
- **RN-05** — Slug inexistente → 404 em Problem Details. Tenant `suspended` → mantém a resolução (a regra de somente leitura é do PLT-005).
- **RN-06** — Toda tabela de negócio tem `tenant_id` (UUID, FK para `tenants`, indexado) e política RLS `USING` e `WITH CHECK` por `current_setting('app.tenant_id', true)`. Sem o setting definido, nenhuma linha é visível nem gravável (falha fechada).
- **RN-07** — A API roda com um papel de banco **sem** superusuário e **sem** `BYPASSRLS` (`app_runtime`). Migrations rodam com outro papel (o dono das tabelas). Tabelas de tenant usam `FORCE ROW LEVEL SECURITY`.
- **RN-08** — O contexto de tenant é aplicado ao banco no início da requisição (`set_config('app.tenant_id', …)`) e **sempre** limpo ao final, mesmo em erro, para que uma conexão reaproveitada nunca carregue o tenant anterior.
- **RN-09** — Modelos de tenant usam o trait `BelongsToTenant`: escopo global por `tenant_id`, preenchimento automático do `tenant_id` ao criar e erro explícito se houver consulta sem tenant em contexto (falha fechada também na aplicação).
- **RN-10** — Existe um helper de migration (`$table->tenantId()` e `Schema::enableTenantRls('tabela')`) que cria a coluna, a FK, o índice e a política. Uma tabela com `tenant_id` sem política RLS faz o teste de esquema falhar.
- **RN-11** — O limite de conexão do pool: com PgBouncer em modo transação o `set_config` de sessão não é seguro. Fica registrado como restrição: não usar PgBouncer em modo transação sem trocar para `SET LOCAL` dentro de transação (decisão a revisar na migração para AWS).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-03 | Tenants `acme` e `beta` | `GET acme.{base}/api/v1/tenant/current` | 200 com os dados de `acme` |
| CA-02 | RN-05 | Nenhum tenant `fantasma` | Requisição ao host `fantasma.{base}` | 404 `application/problem+json` |
| CA-03 | RN-04 | Host da plataforma | Acessar rota `tenant` | 404/403 Problem Details (sem tenant) |
| CA-04 | RN-04 | Host de tenant | Acessar rota `platform` | 404 Problem Details |
| CA-05 | RN-04 | Todas as rotas registradas | Teste de registro roda | Cada rota está em `tenant`, `platform` ou `public`; rota sem grupo falha |
| CA-06 | RN-06 | Linhas dos tenants A e B | Consulta SQL com contexto A | Só linhas de A |
| CA-07 | RN-06 | Contexto A | `INSERT` com `tenant_id` de B | Rejeitado pelo banco (WITH CHECK) |
| CA-08 | RN-06 | Contexto A | `UPDATE`/`DELETE` em linha de B | 0 linhas afetadas |
| CA-09 | RN-06 | Nenhum contexto | Qualquer `SELECT` | 0 linhas |
| CA-10 | RN-07 | Conexão de runtime | `SELECT rolsuper, rolbypassrls` do papel | Ambos falsos |
| CA-11 | RN-08 | Requisição do tenant A termina (inclusive com erro) | Próxima requisição sem tenant na mesma conexão | 0 linhas visíveis |
| CA-12 | RN-09 | Modelo com `BelongsToTenant` | Consulta com contexto A | Só registros de A; `tenant_id` preenchido ao criar |
| CA-13 | RN-09 | Sem contexto | Consulta ao modelo | Exceção explícita |
| CA-14 | RN-10 | Todas as tabelas com `tenant_id` no esquema | Teste de esquema | Todas têm RLS ativo e forçado, e política `USING`/`WITH CHECK` |
| CA-15 | RN-02 | Slugs inválidos e reservados | Validação do slug | Rejeitados |

## Permissões

Sem papéis ainda (autenticação vem no PLT-006).

## API

| Método | Rota | Descrição | Erros específicos |
| --- | --- | --- | --- |
| GET | `/api/v1/tenant/current` | Dados do tenant resolvido pelo host (grupo `tenant`) | 404 tenant inexistente |

Contrato em `openapi.yaml`.

## Dados

- Tabela de plataforma: `tenants` (RN-01).
- Papéis de banco: `app` (dono, usado em migrations) e `app_runtime` (runtime, sem BYPASSRLS), com privilégios padrão de DML nas tabelas do dono.
- Migration compatível com a versão anterior: sim (apenas adições).
- Tabela de sonda para os testes (`probe_items`): criada só nos testes, nunca nas migrations de produção.

## Eventos, auditoria e notificações

Nenhum agora. O log estruturado inclui `tenant_id` quando houver contexto (docs/08).

## Impacto no mobile

O app resolverá o tenant pelo token (PLT-006) usando a mesma interface `TenantResolver`; nenhuma quebra de contrato.

## Fora do escopo

- Autenticação, usuários, papéis, planos e assinatura (PLT-xxx).
- Cadastro e validação de slug no signup (PLT-003); aqui só a regra e a validação reutilizável.
- Domínio próprio do cliente.
- Resolução por token.

## Riscos e perguntas em aberto

- Lista de subdomínios reservados é provisória (revisar com o produto no PLT-003).
- `app_runtime` precisa existir em todos os ambientes (local, CI, homologação); o script de criação do papel fica em `docker/postgres/init.sql` e no workflow de CI.
