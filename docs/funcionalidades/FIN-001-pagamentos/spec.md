# FIN-001 · Pagamentos do atendimento

- **Status:** aprovada (modelo de domínio e herança do Tatulog; decisões técnicas delegadas por Gustavo em 2026-10-22; receita em todas as formas e limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** AGD-003
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Registrar o dinheiro que entra em cada atendimento (sinal, parcelas e valor final), saber quanto falta receber e quanto fica de comissão para o profissional. A receita do negócio (FIN-003) nasce destes registros, não do valor combinado.

## Regras de negócio

- **RN-01** — Pagamento: `appointment_id`, `type` (`deposit` sinal, `installment` parcela, `final` final), `method` (`cash` dinheiro, `pix`, `credit_card`, `debit_card`, `other`), `amount_cents` (inteiro de 1 a 100.000.000), `paid_at` (instante ISO 8601; padrão agora; não pode ser mais de 5 minutos no futuro) e `status` (`received` ou `voided`). O pagamento só existe quando o dinheiro entrou; cobrança futura não é pagamento.
- **RN-02** — Só recebe pagamento o agendamento do negócio com situação `pending`, `scheduled` ou `completed` (cancelado e falta: 409 `appointment_closed`) e com valor combinado maior que zero (422 `appointment_without_value`).
- **RN-03** — **O total recebido nunca passa do valor combinado:** a soma dos pagamentos `received` do agendamento mais o novo não pode superar `value_total_cents`; senão 422 `payment_exceeds_total` com `remaining_cents`. A conferência acontece com o agendamento travado, então dois pagamentos ao mesmo tempo não furam o limite.
- **RN-04** — **Estorno:** `POST /payments/{id}/void` marca o pagamento como `voided` (com `voided_at`); estornado não conta em nada e não volta. Pagamento não é apagado nem tem o valor alterado: para corrigir o valor, estorne e registre outro. `PATCH /payments/{id}` muda só `type`, `method` e `paid_at` de um pagamento recebido.
- **RN-05** — Mudar o `value_total_cents` do agendamento para menos que o já recebido dá 422 `value_below_received`.
- **RN-06** — Agendamento com pagamento recebido não vai para cancelado nem falta (409 `appointment_has_payments`: estorne antes) e agendamento com qualquer registro de pagamento (inclusive estornado) não é excluído (409 `appointment_has_payments`). Assim cancelado nunca carrega receita.
- **RN-07** — **Situação do pagamento do agendamento** (`payment_status`): `no_value` (sem valor), `unpaid` (nada recebido), `partial` (recebeu parte) e `paid` (recebeu tudo). O agendamento passa a trazer `received_cents` e `payment_status`.
- **RN-08** — **Comissão:** o agendamento guarda `commission_percent` copiado do profissional ao criar e ao trocar de profissional (mudar a comissão do profissional depois não altera o que já foi marcado). `commission_cents` = valor combinado × percentual, arredondado ao centavo. Agendamentos já existentes recebem o percentual atual do profissional na migração.
- **RN-09** — **Quem vê o quê:** valores de pagamento, recebido e situação do pagamento seguem a regra dos valores do agendamento (nulos para a recepção). A comissão só aparece para dona, gerente e o profissional dono do agendamento (nula para os outros). Registrar, corrigir e estornar pagamento é só de dona e gerente; o acesso de suporte só lê. A lista de pagamentos é de dona e gerente.
- **RN-10** — `POST /appointments/{id}/payments` exige `Idempotency-Key`: repetir o pedido devolve o mesmo pagamento sem duplicar.
- **RN-11** — Registro, correção e estorno entram no histórico (FND-008) com antes e depois.
- **RN-12** — Tela: no agendamento da `/agenda`, a situação do pagamento (Sem valor, Em aberto, Parcial, Pago) e o botão **Pagamentos** (dona e gerente) que abre o resumo (combinado, recebido, falta, comissão), a lista de pagamentos com estorno, e o formulário de novo pagamento (tipo, forma, valor, data). Mostra o erro de limite com o quanto falta. Estados vazio, carregando e erro; pt-BR; acessível.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-02 | Agendamento com valor | `POST /appointments/{id}/payments` com dados válidos e inválidos | 201; 422 por campo (tipo, forma, valor, data futura); agendamento de outro negócio 404; cancelado 409; sem valor 422 |
| CA-02 | RN-03 | Valor de 150,00 | Pagar 50, 100, depois 1 centavo | 201, 201, 422 `payment_exceeds_total` com `remaining_cents` 0; estornado libera o saldo |
| CA-03 | RN-03 | Duas gravações ao mesmo tempo | Somar dois pagamentos que juntos passam do valor | Só um entra |
| CA-04 | RN-04 | Pagamento recebido | Estornar; estornar de novo; PATCH | Estorno vale uma vez (409 depois); PATCH muda tipo, forma e data; valor não muda; estornado não edita |
| CA-05 | RN-05 | Agendamento com 100 recebidos | Baixar o valor para 80 e para 100 | 422 `value_below_received`; 200 |
| CA-06 | RN-06 | Agendamento com pagamento | Cancelar, marcar falta, excluir | 409 `appointment_has_payments`; após estorno cancela, mas excluir continua barrado |
| CA-07 | RN-07 | Quatro estados | Ler o agendamento | `received_cents` e `payment_status` certos; nulos para a recepção |
| CA-08 | RN-08 | Profissional com 40% | Agendar, trocar a comissão do profissional, trocar o profissional do agendamento | Percentual congelado; recalculado ao trocar de profissional; `commission_cents` arredonda |
| CA-09 | RN-09 | Quatro papéis e suporte | Ler e gravar | Comissão só para dona, gerente e o profissional dono; pagamentos só dona e gerente; suporte só lê |
| CA-10 | RN-10 | Mesma chave | Repetir o POST | Um só pagamento |
| CA-11 | RN-11 | Registrar, corrigir, estornar | Histórico | Registros com antes e depois |
| CA-12 | RN-02 | Dois negócios | Qualquer rota | Cada um vê e altera só o seu |
| CA-13 | RN-09 | Assinatura suspensa | Leitura e escrita | Leitura 200; escrita 403 com `code` |
| CA-14 | RN-12 | Tela | Resumo, lista, novo, estorno, erros | Comportamento e mensagens certos; sem violações no axe |
| CA-15 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/appointments/{id}/payments` | Pagamentos e resumo | dona, gerente |
| POST | `/appointments/{id}/payments` | Registra (`Idempotency-Key`) | dona, gerente |
| PATCH | `/payments/{id}` | Corrige tipo, forma e data | dona, gerente |
| POST | `/payments/{id}/void` | Estorna | dona, gerente |

## Dados

`payments` com chave estrangeira composta `(tenant_id, appointment_id)` (`ON DELETE RESTRICT`), checagens de valor, tipo, forma e situação; `appointments.commission_percent` (`numeric(5,2)`).

## Fora do escopo

Pagamento de orçamento (ORC), cobrança automática e conciliação com gateway (INT), comissão paga ao profissional (repasse), receita e dashboard (FIN-003), nota fiscal. Decisão a rever depois dos testes: falta com sinal retido hoje exige estornar o sinal (a falta não conta como receita).
