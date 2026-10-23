---
name: endpoint-laravel
description: Estrutura padrão de um endpoint no módulo Laravel do sistema-log.
---

# endpoint-laravel

> Rascunho inicial. Refinar por PR.

> Exemplos de código serão adicionados após FND-002.

Para cada endpoint:

- Rota em `routes/api_v1.php` com middleware de tenant, auth e `feature:<chave>` quando aplicável.
- `FormRequest` para validação.
- `Policy` para autorização por papel.
- `Action` (classe de caso de uso) com a regra de negócio; controller só orquestra.
- `Resource` para a resposta, igual ao `openapi.yaml`.
- Evento de domínio + auditoria (antes/depois).
- Testes: feature test por critério, teste de isolamento de tenant, teste de permissão e de plano.
