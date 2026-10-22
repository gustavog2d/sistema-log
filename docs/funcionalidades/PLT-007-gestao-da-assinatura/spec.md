# PLT-007 · Gestão da assinatura pela dona

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-004, PLT-005, PLT-006
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A dona do negócio precisa cuidar da própria assinatura sem falar com ninguém: trocar o meio de pagamento, regularizar uma assinatura suspensa (e voltar a trabalhar na hora), cancelar e ver as faturas. A troca de plano (upgrade e downgrade) fica para o PLT-013, porque as regras de limite por plano ainda não foram definidas.

## Histórias de usuário

- Como dona suspensa por falha de pagamento, quero informar outro cartão e voltar a usar o sistema na hora.
- Como dona, quero trocar o cartão antes da próxima cobrança.
- Como dona, quero cancelar sem custo durante o teste e, depois, até o fim do período já pago.
- Como dona, quero desistir do cancelamento antes que ele valha.
- Como dona, quero ver minhas faturas.

## Regras de negócio

- **RN-01** — Só quem tem o papel de dona (`owner`) gerencia a assinatura (403 para os demais papéis). As rotas exigem login e funcionam também com a assinatura suspensa ou cancelada (`subscription.exempt`), senão a regularização seria impossível.
- **RN-02** — `PUT /subscription/payment-method` (com `Idempotency-Key`) recebe `payment_method_token`. Em teste ou ativa, troca o meio de pagamento sem cobrar. Suspensa: cobra o valor do plano **na hora** com o novo meio; aprovada, a assinatura volta a `active` (período novo a partir de agora, `suspended_at` limpo, fatura paga) e a escrita é liberada; recusada, continua suspensa, o meio de pagamento antigo é mantido e a resposta é 402 com `code` = `payment_failed`. Cortesia (preço zero) é reativada sem cobrança. Cancelada: 409. Token recusado no cadastro: 422.
- **RN-03** — `POST /subscription/cancel`: em teste ou ativa, registra o pedido (`canceled_at`) e **mantém o acesso até o fim do teste ou do período já pago**; nada é cobrado depois disso (durante o teste nunca há cobrança; depois da primeira cobrança não há estorno). Suspensa: cancela na hora. Repetir o pedido não muda nada (200). Já cancelada: 409.
- **RN-04** — `POST /subscription/reactivate` desfaz um pedido de cancelamento ainda pendente (em teste ou ativa). Sem pedido pendente: 409. Cancelada: 409.
- **RN-05** — A cobrança (`billing:run`) não cobra uma assinatura com cancelamento pendente: no vencimento ela passa a `canceled`, sem fatura, e a auditoria registra o evento.
- **RN-06** — `GET /subscription/invoices` lista as faturas do negócio, das mais novas para as mais antigas, com paginação por cursor (`limit` de 1 a 50, padrão 20).
- **RN-07** — `GET /subscription` passa a mostrar também `payment_method` (tipo, bandeira e final; nunca a referência do gateway) e `cancel_at_period_end` (verdadeiro quando há cancelamento pendente).
- **RN-08** — Auditoria (`platform_audit_logs`, sem pessoa): `subscription.payment_method_changed`, `subscription.regularized`, `subscription.cancel_requested`, `subscription.canceled`, `subscription.reactivated`. Repetir o pedido com a mesma `Idempotency-Key` devolve a mesma resposta sem cobrar de novo; com outra chave, a assinatura já ativa (linha travada) não é cobrada outra vez.
- **RN-09** — Cada negócio só enxerga e altera a própria assinatura. Limites: 30 pedidos por minuto por IP nestas rotas.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Papéis `manager`, `professional` e `reception`; sem login | Qualquer rota nova | 403; 401 sem login |
| CA-02 | RN-02 | Assinatura em teste ou ativa | `PUT /subscription/payment-method` com `tok_ok` | 200; novo meio; nenhuma cobrança nem fatura |
| CA-03 | RN-02 | Assinatura suspensa | PUT com `tok_ok` | 200; `active`; período novo; fatura paga; escrita liberada |
| CA-04 | RN-02 | Assinatura suspensa | PUT com `tok_fail_on_charge` | 402 `payment_failed`; continua suspensa; meio antigo mantido; sem fatura |
| CA-05 | RN-02 | Token recusado (`tok_declined`) | PUT | 422 em `payment_method_token`; nada muda |
| CA-06 | RN-02 | Assinatura cancelada | PUT | 409 |
| CA-07 | RN-02, RN-08 | Mesma `Idempotency-Key` repetida na regularização | PUT duas vezes | Uma só cobrança; mesma resposta |
| CA-08 | RN-03 | Em teste | `POST /subscription/cancel` | 200; `cancel_at_period_end` verdadeiro; ainda escreve; no fim do teste vira `canceled` sem cobrança |
| CA-09 | RN-03, RN-05 | Ativa com cancelamento pendente | `billing:run` depois do fim do período | `canceled`, sem nova fatura |
| CA-10 | RN-03 | Suspensa | cancelar | `canceled` na hora |
| CA-11 | RN-03 | Cancelamento já pendente; cancelada | cancelar de novo | 200 sem mudança; 409 |
| CA-12 | RN-04 | Cancelamento pendente | `POST /subscription/reactivate` | 200; deixa de estar pendente; a cobrança segue normal |
| CA-13 | RN-04 | Sem pedido pendente; cancelada | reativar | 409 |
| CA-14 | RN-06 | Várias faturas e outro negócio | `GET /subscription/invoices` | Só as do negócio, novas primeiro; cursor funciona; `limit` fora de 1-50 dá 422 |
| CA-15 | RN-07 | Assinatura com meio de pagamento; com cancelamento pendente | `GET /subscription` | `payment_method` sem referência do gateway; `cancel_at_period_end`; `next_charge_at` nulo se há cancelamento pendente |
| CA-16 | RN-09 | Dois negócios | Qualquer rota nova | Cada um altera só o seu; token de um não vale no outro |
| CA-17 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/api/v1/subscription` | (já existe) com `payment_method` e `cancel_at_period_end` | 401, 404 |
| PUT | `/api/v1/subscription/payment-method` | Troca o meio de pagamento; regulariza se suspensa | 401, 402, 403, 409, 422, 429 |
| POST | `/api/v1/subscription/cancel` | Pede o cancelamento | 401, 403, 409 |
| POST | `/api/v1/subscription/reactivate` | Desfaz o pedido de cancelamento | 401, 403, 409 |
| GET | `/api/v1/subscription/invoices` | Faturas | 401, 403, 422 |

## Dados

Nenhuma tabela nova. `subscriptions.canceled_at` passa a significar "cancelamento pedido" enquanto o status é `trialing` ou `active`. O papel da API ganha leitura das colunas não secretas de `payment_methods` (tipo, bandeira, final); a referência do gateway continua só com o papel de plataforma.

## Eventos, auditoria e notificações

Eventos de auditoria na RN-08. E-mails ao assinante: PLT-010.

## Fora do escopo

Troca de plano (PLT-013), estorno, tela (painel web), cupons (PLT-012), gateway real (INT-001).
