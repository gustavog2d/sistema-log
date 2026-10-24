# FIN-001 · Pagamentos do atendimento

## Para quem usa

Na **Agenda**, cada agendamento mostra a situação do pagamento: *Sem valor*, *Em aberto*, *Parcial* ou *Pago*. Dona e gerente têm o botão **Pagamentos**, que abre o resumo (valor combinado, recebido, quanto falta e a comissão do profissional), a lista de pagamentos e o formulário para registrar um novo.

- **Registrar:** escolha o tipo (sinal, parcela ou final), a forma (PIX, dinheiro, cartão de crédito, cartão de débito ou outro), o valor e, se quiser, a data. Em branco vale agora. O total recebido **nunca passa do valor combinado**: se passar, a tela mostra quanto falta.
- **Corrigir um erro:** pagamento não se apaga nem muda de valor. **Estorne** (com confirmação) e registre de novo com o valor certo. Estornado não conta em nada.
- **Cancelar ou marcar falta:** só depois de estornar os pagamentos recebidos, para que cancelado nunca carregue receita. Agendamento que teve qualquer pagamento (mesmo estornado) não é excluído.
- **Valor do agendamento:** não pode ficar abaixo do que já foi recebido.
- **Comissão:** o percentual do profissional é copiado ao agendar e fica congelado (trocar a comissão do profissional depois não muda o que já foi marcado; trocar o profissional do agendamento usa a comissão do novo). Aparece para dona, gerente e o próprio profissional; a recepção não vê valores nem comissão.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/appointments/{id}/payments` (lista e `summary`) | dona e gerente |
| POST | `/appointments/{id}/payments` (`Idempotency-Key` obrigatório) | dona e gerente |
| PATCH | `/payments/{id}` (só `type`, `method`, `paid_at`) | dona e gerente |
| POST | `/payments/{id}/void` | dona e gerente |

- O agendamento (`/appointments`) passa a trazer `received_cents`, `payment_status` (`no_value`, `unpaid`, `partial`, `paid`), `commission_percent` e `commission_cents` (a comissão só para quem pode vê-la; os valores seguem a regra do `value_total_cents`).
- **Regras** em `App\Payments\PaymentLedger` (limite, situação do agendamento, estorno); contas em `AppointmentFinance`; erros com `code` em `PaymentRuleViolation` (`payment_exceeds_total` com `remaining_cents`, `appointment_closed`, `appointment_without_value`, `payment_voided`, `value_below_received`, `appointment_has_payments`).
- **Limite no banco:** o gatilho `payments_total_guard` trava a linha do agendamento e soma o recebido antes de aceitar um pagamento ou mudar o valor/situação dele; a API também trava (`lockForUpdate`) e traduz a violação (`23514`) no mesmo 422. O mesmo gatilho recusa pagamento de agendamento sem valor. O gatilho `appointments_finance_guard` faz o inverso: não deixa baixar o valor abaixo do recebido nem cancelar com dinheiro recebido, mesmo quando a edição concorre com um pagamento (a API traduz a violação em 409 `appointment_has_payments`).
- **Dados:** `payments` com chave composta `(tenant_id, appointment_id)` `ON DELETE RESTRICT`, checagens de tipo, forma, situação e valor; `appointments.commission_percent` (migração preenche os agendamentos existentes com o percentual atual do profissional).
- **Histórico:** `Payment` usa `Audited` (registro, correção e estorno com antes e depois).
- **Testes:** `vendor/bin/pest tests/Feature/Payments` e `pnpm --filter web exec vitest run src/components/panel/payments-dialog.test.tsx src/components/panel/agenda-view.test.tsx`.

## Limites conhecidos

- Só dona e gerente veem e mexem em pagamentos; a recepção e o profissional não.
- Falta com sinal retido: hoje exige estornar o sinal (a falta não conta como receita). Decisão a rever depois dos testes.
- Sem pagamento de orçamento, sem cobrança automática nem conciliação com gateway (INT), sem repasse da comissão ao profissional e sem receita consolidada (FIN-003).
- A edição do valor combinado pela tela do agendamento ainda é só pela API; a tela mostra e usa o valor existente.
- A chave de idempotência vale por pessoa, negócio e endereço de IP (herdado do middleware): se o IP mudar entre a tentativa e a repetição, a repetição conta como pedido novo. O limite de recebido continua valendo.
