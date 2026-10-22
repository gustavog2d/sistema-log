# Base multi-tenant

> Para quem desenvolve no repositório.

## Para que serve

Garante que um assinante (tenant) nunca vê nem altera dados de outro. O isolamento tem duas barreiras: o filtro na aplicação e o Row Level Security (RLS) no PostgreSQL.

## Como funciona

1. A requisição chega em `{slug}.{APP_BASE_DOMAIN}`. O middleware `tenant` resolve o tenant pelo slug e o guarda em `TenantContext`.
2. O middleware também grava o tenant na conexão (`set_config('app.tenant_id', …)`). As políticas RLS leem esse valor. Ao fim da requisição, mesmo com erro, ele é limpo.
3. Sem tenant em contexto, o banco não mostra nenhuma linha (falha fechada).

## Papéis de banco

| Papel | Uso | RLS |
| --- | --- | --- |
| `app` (dono) | Migrations (`--database=pgsql_admin`) e preparo de testes | Ignora (superusuário local) |
| `app_runtime` | A API e os workers (`pgsql`) | **Aplica** (sem superusuário, sem BYPASSRLS) |

Em ambiente já criado, recrie o volume do banco para ganhar o papel `app_runtime`: `docker compose down -v && docker compose up -d --build --wait`.

## Como criar uma tabela de negócio

```php
Schema::create('appointments', function (Blueprint $table) {
    $table->id();
    $table->tenantId();      // coluna tenant_id, FK e índice
    // ...
});
Schema::enableTenantRls('appointments'); // RLS ligado, forçado, com USING e WITH CHECK
```

No modelo, use o trait `App\Tenancy\Concerns\BelongsToTenant`. Ele filtra por tenant, preenche o `tenant_id` ao criar e lança erro se não houver tenant em contexto.

O teste `SchemaRlsTest` falha se existir tabela fora da lista de plataforma sem `tenant_id` e RLS. Tabela de plataforma nova precisa entrar na lista `PLATFORM_TABLES` do teste, de propósito.

## Como declarar uma rota

Toda rota de `/api/v1` declara seu grupo por middleware: `tenant` (exige tenant), `platform` (só hosts da plataforma) ou `public` (sem tenant e sem dado de negócio). O teste `RouteRegistryTest` falha para rota sem grupo.

## Limitações conhecidas

- A resolução pelo token do mobile entra no PLT-006 (a interface `TenantResolver` está pronta).
- PgBouncer em modo transação não é compatível com `set_config` de sessão; se for usado, trocar para `SET LOCAL` em transação.
- A lista de subdomínios reservados (`config/tenancy.php`) é provisória.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Tenant, resolução por subdomínio, RLS, suíte de isolamento |
