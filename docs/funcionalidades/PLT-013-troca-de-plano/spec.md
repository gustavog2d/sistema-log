# PLT-013 · Troca de plano

- **Status:** aprovada (regra de negócio de Gustavo: upgrade imediato com cobrança proporcional; downgrade no próximo ciclo, bloqueado se a conta passar do limite do novo plano; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-002, PLT-004, PLT-007, PLT-008
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A dona precisa trocar de plano sem falar com ninguém: subir de plano e usar os recursos na hora (pagando só a diferença do que falta do período), ou descer de plano sem perder o que já pagou (a mudança vale na próxima cobrança), sem nunca ficar com mais usuários do que o novo plano permite.

## Regras de negócio

- **RN-01** — Só a dona (`owner`) troca de plano; rotas em `subscription/` **sem** `subscription.exempt` (suspensa ou cancelada fica somente leitura), com login, sem acesso de suporte. A troca mantém o **ciclo** (mensal ou anual); trocar de ciclo fica para outra entrega. O plano novo precisa estar ativo e ser diferente do atual (422 em `plan`).
- **RN-02** — Preço do plano novo: o preço do ciclo atual do plano novo, com o **mesmo percentual de cupom** da assinatura, se houver (cupom de 100% mantém a cortesia). O preço da assinatura passa a ser esse valor (congelado até a próxima troca).
- **RN-03** — Em **teste** a troca vale na hora, para subir ou descer, sem cobrança; o fim do teste cobra o preço do plano novo.
- **RN-04** — **Ativa, preço novo maior que o atual (upgrade):** vale na hora. Cobra-se agora a diferença proporcional ao que falta do período: `diferença × segundos restantes ÷ segundos do período`, arredondado para o centavo, com o meio de pagamento da assinatura. Cobrança aprovada: grava fatura paga do valor cobrado (período de agora até o fim do período atual), troca plano e preço e mantém as datas. Cobrança recusada: 402 `payment_failed`, **nada muda** e a assinatura não é suspensa. Cobrança de valor zero não chama o gateway nem gera fatura.
- **RN-05** — **Ativa, preço novo menor ou igual (downgrade):** agenda a troca para o fim do período atual (`pending_plan`); a dona continua com o plano atual até lá e paga o preço atual até lá. Só uma troca pendente por vez (nova troca substitui a pendente). Preço igual vale na hora, sem cobrança.
- **RN-06** — **Limites:** antes de trocar (e de novo ao aplicar a troca agendada), cada funcionalidade com limite do plano novo (já com as exceções do negócio) é comparada ao uso. Uso hoje: `usuarios.max` = pessoas ativas + convites pendentes; funcionalidades sem medição ainda contam 0. Se o uso passa do limite: 409 `plan_limit_exceeded` com `violations` (`feature`, `limit`, `usage`) e nada muda.
- **RN-07** — **Troca agendada na renovação:** `billing:run` aplica a troca antes de cobrar (cobra o preço novo). O preço da troca agendada fica **congelado no momento do agendamento** (mudanças de preço do plano ou do cupom depois dele não valem). Se, na hora, o uso passar do limite do plano novo ou o plano novo não estiver mais ativo, **a troca não é aplicada**: a assinatura segue no plano atual, a troca pendente é limpa e a auditoria registra `subscription.plan_change_skipped`. Cancelamento pedido tem prioridade: a assinatura termina sem aplicar a troca.
- **RN-08** — `DELETE /subscription/plan-change` desfaz a troca pendente (409 se não há); funciona também com cancelamento pedido, mas não com assinatura suspensa ou cancelada (403). Um upgrade imediato que substitui uma troca pendente a limpa e registra `cleared_pending` na auditoria. Plano sem preço para o ciclo é recusado (422).
- **RN-09** — Suspensa: `PUT`/`DELETE` dão 403 `subscription_suspended` (bloqueio de escrita; regularize antes) e a simulação dá 409 com o mesmo `code`; cancelada: 403/409 `subscription_canceled`; com cancelamento pedido: 409 `cancel_pending` (desfaça o cancelamento antes).
- **RN-10** — `GET /subscription/plan-change/preview?plan=` mostra, sem gravar: tipo (`now` ou `next_cycle`), preço novo, valor a cobrar agora, data em que vale e as violações de limite. `GET /subscription` passa a trazer `pending_plan_change` (plano, preço, data) ou `null`.
- **RN-11** — Auditoria (sem pessoa): `subscription.plan_changed`, `subscription.plan_change_scheduled`, `subscription.plan_change_canceled`, `subscription.plan_change_applied`, `subscription.plan_change_skipped`. Repetir o `PUT` com a mesma `Idempotency-Key` devolve a mesma resposta sem cobrar de novo.
- **RN-12** — Cada negócio só enxerga e altera a própria assinatura; 30 pedidos por minuto por IP (limite já usado na gestão da assinatura).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Papéis que não são dona; sem login; acesso de suporte | Rotas novas | 403; 401; 403 |
| CA-02 | RN-01 | Plano inexistente, inativo ou igual ao atual | `PUT /subscription/plan` | 422 em `plan`; nada muda |
| CA-03 | RN-03 | Em teste | Subir e descer de plano | Vale na hora, sem cobrança nem fatura; preço novo; teste mantido |
| CA-04 | RN-04 | Ativa, meio do período | Upgrade | Cobra a diferença proporcional; fatura paga; plano e preço novos; datas mantidas |
| CA-05 | RN-04 | Ativa; `tok_fail_on_charge` | Upgrade | 402 `payment_failed`; plano, preço e status iguais; sem fatura |
| CA-06 | RN-05 | Ativa | Downgrade | Plano atual mantido; `pending_plan_change` com data do fim do período; sem cobrança |
| CA-07 | RN-06 | Mais pessoas do que o limite do plano novo | Downgrade e upgrade para plano menor em teste | 409 `plan_limit_exceeded` com `violations`; nada muda |
| CA-08 | RN-07 | Troca agendada, período vencido | `billing:run` | Aplica o plano novo e cobra o preço novo |
| CA-09 | RN-07 | Troca agendada, uso acima do limite na renovação | `billing:run` | Mantém o plano atual, limpa a pendência, cobra o preço atual e audita `plan_change_skipped` |
| CA-10 | RN-07 | Troca agendada e cancelamento pedido | `billing:run` | Cancela sem aplicar a troca |
| CA-11 | RN-08 | Troca pendente; sem troca pendente | `DELETE /subscription/plan-change` | Limpa; 409 |
| CA-12 | RN-09 | Suspensa; cancelada; cancelamento pedido | `PUT /subscription/plan` e simulação | 403 (escrita) e 409 (simulação) com `code` correto; 409 `cancel_pending` |
| CA-13 | RN-02 | Assinatura com cupom de 20%; com cortesia | Troca de plano | Preço novo com o mesmo percentual; cortesia segue sem cobrança |
| CA-14 | RN-10 | Qualquer situação | Preview | Tipo, valor, data e violações corretos; nada gravado |
| CA-15 | RN-10 | Com e sem troca pendente | `GET /subscription` | `pending_plan_change` preenchido ou nulo |
| CA-16 | RN-11 | Mesma `Idempotency-Key` | `PUT` duas vezes | Uma só cobrança; mesma resposta |
| CA-17 | RN-11 | Eventos | Troca, agendamento, cancelamento, aplicação, pulo | Auditoria com os eventos e sem dados do cartão |
| CA-18 | RN-12 | Dois negócios | Rotas novas | Cada um altera só a sua |
| CA-19 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/subscription/plan-change/preview?plan=` | Simulação (sem gravar) | 401, 403, 422 |
| PUT | `/subscription/plan` | Troca de plano (`Idempotency-Key`) | 401, 402, 403, 409, 422 |

| DELETE | `/subscription/plan-change` | Desfaz a troca pendente | 401, 403, 409 |
| GET | `/subscription` | Passa a trazer `pending_plan_change` | — |

## Dados

`subscriptions.pending_plan_id` (uuid nulo, FK de `plans`) e `subscriptions.pending_price_cents` (inteiro nulo). Migration só adiciona colunas (compatível com a versão anterior).

## Fora do escopo

Troca de ciclo (mensal ↔ anual); e-mails de aviso da troca (registrados como evolução do PLT-010); tela web (entregue no mesmo item, em seguida); medição de uso de funcionalidades que ainda não existem (agenda, orçamento etc.).
