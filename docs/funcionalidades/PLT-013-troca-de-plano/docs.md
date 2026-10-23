# PLT-013 · Troca de plano

A dona troca de plano em **Assinatura → Plano → Trocar de plano** (`/assinatura`). O ciclo (mensal ou anual) continua o mesmo.

## Para quem usa

1. Escolha o novo plano. O resumo mostra o que acontece antes de confirmar.
2. **Durante o teste:** vale na hora, sem cobrança; a primeira cobrança já usa o preço do plano novo.
3. **Assinatura ativa, plano mais caro (upgrade):** vale na hora. Você paga hoje só a diferença proporcional ao que falta do período; as datas não mudam. Se o pagamento for recusado, nada muda e a assinatura não é suspensa.
4. **Assinatura ativa, plano mais barato (downgrade):** fica agendado para o fim do período; até lá você continua no plano atual. Dá para desfazer a troca agendada.
5. **Limites:** se você usa mais do que o plano novo permite (hoje: pessoas ativas e convites pendentes contra o limite de usuários), a troca não é feita e o resumo mostra o que passa do limite.
6. Não dá para trocar com a assinatura suspensa (regularize o pagamento antes) nem com cancelamento pedido (desfaça o cancelamento antes).
7. Cupom: o novo preço mantém o mesmo percentual de desconto do cupom da assinatura; cortesia (100%) continua sem cobrança.

## Para quem desenvolve

| Método | Rota | Descrição |
| --- | --- | --- |
| GET | `/subscription/plan-change/preview?plan=` | Simula: `kind` (`now` ou `next_cycle`), `charge_now_cents`, `effective_at`, `violations` |
| PUT | `/subscription/plan` | Troca (`Idempotency-Key`); devolve o estado da assinatura e `plan_change` |
| DELETE | `/subscription/plan-change` | Desfaz a troca agendada |
| GET | `/subscription` | Inclui `pending_plan_change` |

- **Regras:** `App\Billing\PlanChanger` (preço com o percentual do cupom, proporção em segundos, cobrança com chave determinística, auditoria `subscription.plan_*`); `App\Billing\PlanLimits` confere o uso (`usuarios.max` = pessoas ativas + convites pendentes) com as exceções do negócio.
- **Renovação:** `SubscriptionBilling::process` chama `PlanChanger::applyPending` antes de cobrar; se o uso passou do limite, a troca é descartada (`subscription.plan_change_skipped`).
- **Dados:** colunas `subscriptions.pending_plan_id` e `pending_price_cents` (migration `2026_11_03`), mais `GRANT SELECT (id, tenant_id, expires_at) ON invitations TO app_platform` (recriar o volume do banco não é necessário: basta rodar a migration).
- **Web:** `components/panel/plan-change.tsx` (resumo antes de confirmar, chave de idempotência nova depois de uma recusa).
- **Testes:** `vendor/bin/pest tests/Feature/Billing/PlanChange*` e `pnpm --filter web exec vitest run src/components/panel/plan-change.test.tsx`.

## Limites conhecidos

- Troca de ciclo (mensal ↔ anual) e e-mails de aviso da troca ficam para outra entrega.
- Só `usuarios.max` tem medição de uso; as demais funcionalidades com limite contam 0 até existirem.

## Pendências registradas nas revisões

- **Antes de ligar o gateway real (INT-001):** a cobrança do upgrade acontece dentro da transação. Se a gravação falhar depois da cobrança, o cliente fica cobrado sem o plano novo; é preciso gravar uma intenção de cobrança antes de chamar o gateway (ou reconciliar/estornar) e fixar o valor da simulação na janela de nova tentativa. A chave do gateway já inclui o meio de pagamento, para a recusa não "grudar" depois de trocar o cartão.
- **Regra de negócio a confirmar:** o percentual do cupom vale para qualquer plano novo; com cupom de 100% (cortesia) a dona poderia subir para qualquer plano sem pagar. Hoje é o comportamento implementado.
- A chamada ao gateway segura a linha da assinatura durante a cobrança (contenção, não risco de dados).
