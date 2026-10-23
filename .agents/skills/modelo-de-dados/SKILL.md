---
name: modelo-de-dados
description: Como criar tabelas e migrations no sistema-log (tenant_id, RLS, índices, compatibilidade).
---

# modelo-de-dados

> Rascunho inicial. Refinar por PR.

- Toda tabela de negócio do assinante tem `tenant_id` (não nulo, indexado) e política RLS usando `current_setting('app.tenant_id')`.
- Tabelas de plataforma (tenants, planos, assinaturas) não têm RLS de tenant.
- Índices compostos começando por `tenant_id`.
- Horários em `timestamptz` (UTC); duração em minutos (`integer`).
- Conflito de agenda: constraint de exclusão (`EXCLUDE USING gist`) por profissional e intervalo.
- Migrations expandir → migrar dados → contrair, em deploys separados.
- Toda tabela nova entra na suíte de isolamento de tenant.
