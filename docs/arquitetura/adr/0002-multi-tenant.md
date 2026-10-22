# ADR 0002 · Modelo de isolamento multi-tenant

- **Status:** proposto
- **Data:** 2026-10-20
- **Decisores:** Gustavo

## Contexto

O Tatulog usa um banco MariaDB por tenant (stancl/tenancy). O sistema-log prevê milhares de assinantes em assinatura, com migrations frequentes e custo baixo por tenant.

## Opções consideradas

1. **Banco compartilhado, `tenant_id` em toda tabela + Row Level Security no PostgreSQL.**
2. Schema por tenant no PostgreSQL.
3. Banco por tenant (modelo atual).

## Decisão (proposta)

Opção 1. Filtro de tenant na aplicação (escopo global) **e** RLS no banco como segunda barreira. Suíte de isolamento obrigatória em todo PR.

## Consequências

- Positivas: uma migration por deploy, custo baixo, relatórios da plataforma simples, escala horizontal.
- Negativas: um bug de escopo pode vazar dados entre tenants (mitigado por RLS + testes bloqueantes); exportação/exclusão por tenant precisa de rotina própria.
- A fazer: definir como a conexão informa o tenant ao PostgreSQL (`SET app.tenant_id` por requisição) e testar com pool de conexões.
