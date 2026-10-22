---
name: contrato-api
description: Convenções de API REST do sistema-log e como escrever o openapi.yaml de uma funcionalidade.
---

# contrato-api

> Rascunho inicial. Refinar por PR.

- Base `/api/v1`; recursos no plural, em inglês e kebab-case (`/appointments`, `/expense-categories`).
- JSON em camelCase? **Decidir em FND-001** e registrar aqui.
- Datas ISO 8601 com fuso; durações em minutos; dinheiro com moeda explícita.
- Paginação por cursor (`?cursor=&limit=`), resposta com `data` e `meta.nextCursor`.
- Erros RFC 9457 (`application/problem+json`) com `code` estável (ex.: `feature_not_in_plan`, `tenant_suspended`).
- `Idempotency-Key` obrigatório em criação de agendamento e pagamento.
- Nenhuma mudança que quebre contrato em `/v1`: só adicionar campos opcionais.
- Atualize o `openapi.yaml` da funcionalidade antes do código.
