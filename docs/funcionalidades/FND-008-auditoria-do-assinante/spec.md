# FND-008 · Auditoria do assinante

- **Status:** aprovada (herança do Tatulog "Log de auditoria com valores antes e depois em todas as entidades"; decisões técnicas delegadas por Gustavo em 2026-10-22)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** PLT-015
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Quem gere o negócio precisa saber quem criou, mudou ou apagou um cliente, um agendamento, um pagamento ou uma despesa, e o que havia antes. Este item entrega o mecanismo de registro (usado por todas as entidades de negócio das próximas entregas) e a tela de histórico.

## Regras de negócio

- **RN-01** — Cada criação, edição e exclusão de uma entidade auditada grava **um** registro com: pessoa, entidade (`entity_type`), id da entidade, ação (`created`, `updated`, `deleted`), valores **antes** e **depois** só dos campos auditados que mudaram (criação: só `after`; exclusão: só `before`) e o horário (UTC).
- **RN-02** — Entidade auditada declara seus campos auditados e o nome do tipo (`Audited` no modelo). Campos sensíveis (senhas, tokens, segredos) nunca entram no registro.
- **RN-03** — Edição que não muda nenhum campo auditado não grava registro.
- **RN-04** — A pessoa é a autenticada na requisição (`id` e nome no momento, guardado como cópia para continuar legível se a pessoa for removida). Ações de processos internos (fila, comandos) ficam sem pessoa.
- **RN-05** — O registro é **somente de acréscimo**: a API não edita nem apaga registros (o papel do banco da API não tem UPDATE nem DELETE na tabela). A limpeza por retenção (PLT-016) usa o papel de plataforma.
- **RN-06** — Cada negócio só enxerga os próprios registros (RLS). Toda escrita acontece dentro da mesma transação da mudança: se a mudança falha, o registro não existe; se o registro falha, a mudança não acontece.
- **RN-07** — `GET /audit-logs` (dona e gerente) lista os registros, do mais novo para o mais antigo, com paginação por cursor (`limit` de 1 a 50, padrão 20) e filtros `entity_type` e `entity_id` (uuid). Profissional e recepção recebem 403. Funciona também com a assinatura suspensa (é leitura).
- **RN-08** — Tela `/historico` (dona e gerente): lista com filtro por tipo, mostra ação, quem, quando e a mudança campo a campo (antes → depois), com "carregar mais". Um componente de histórico por entidade é reaproveitado pelas telas das próximas entregas.
- **RN-09** — Valores guardados em formato JSON simples (texto, número, booleano ou nulo); datas em ISO 8601 UTC; dinheiro em centavos. A tela formata para o usuário.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Entidade auditada | Criar | 1 registro `created` com `after` dos campos auditados e sem `before` |
| CA-02 | RN-01, RN-03 | Entidade existente | Editar campos auditados; editar só campo não auditado | 1 registro `updated` só com os campos que mudaram (antes e depois); nenhum registro |
| CA-03 | RN-01 | Entidade existente | Excluir | 1 registro `deleted` com `before` |
| CA-04 | RN-02 | Modelo com campo sensível | Mudar o campo | O valor nunca aparece no registro |
| CA-05 | RN-04 | Pessoa autenticada; processo sem pessoa | Mudar | `actor` com id e nome; `actor` nulo |
| CA-06 | RN-05 | Papel da API | Tentar UPDATE e DELETE direto na tabela | Recusado pelo banco |
| CA-07 | RN-06 | Dois negócios | Listar | Cada um vê só os seus; sem contexto de negócio nada é gravado |
| CA-08 | RN-06 | Falha na mudança ou na gravação | Transação | Nada fica gravado em nenhuma das duas |
| CA-09 | RN-07 | Vários registros | `GET /audit-logs` com e sem filtros e cursor | Ordem, filtros, cursor e `limit` (422 fora de 1-50, `entity_id` inválido) |
| CA-10 | RN-07 | Papéis | Listar | Dona e gerente 200; profissional e recepção 403; sem login 401; suspensa 200 |
| CA-11 | RN-08, RN-09 | Registros | Tela `/historico` | Lista, filtro, mudanças campo a campo, carregar mais, vazio e erro; sem violações no axe |
| CA-12 | contrato | Respostas reais | `GET /audit-logs` | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/audit-logs` | Lista registros (`entity_type`, `entity_id`, `limit`, `cursor`) | 401, 403, 422 |

## Dados

`audit_logs` (`tenant_id`, `user_id` opcional, `actor_name`, `entity_type`, `entity_id`, `action`, `before` e `after` em `jsonb`, `created_at`), RLS, sem UPDATE/DELETE para o papel da API.

## Fora do escopo

Auditoria de leitura; exportação do log; retenção do log (PLT-016); reversão automática de uma mudança.
