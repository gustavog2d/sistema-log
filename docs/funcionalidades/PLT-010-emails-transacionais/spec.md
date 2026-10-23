# PLT-010 · E-mails transacionais da plataforma

- **Status:** aprovada (decisões técnicas delegadas por Gustavo em 2026-10-22; lista de e-mails de `docs/05-planos-e-assinaturas.md`)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-004, PLT-006, PLT-007
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Quem assina precisa ser avisado do que acontece com a assinatura e com a segurança da conta: boas-vindas, fim do teste chegando, pagamento aprovado, pagamento recusado e suspensão, cancelamento, regularização, troca de senha e desativação do 2FA. Cada acontecimento manda um e-mail, uma vez.

## Regras de negócio

- **RN-01** — Os e-mails, o gatilho e o destinatário:

| Tipo | Quando | Para |
| --- | --- | --- |
| `welcome` | Cadastro concluído | Dona |
| `trial_ending` | Faltam 2 dias ou menos para o fim do teste (comando `notifications:run`, a cada 15 minutos), sem cancelamento pedido | Dona |
| `payment_succeeded` | Cobrança aprovada (primeira ou renovação) com valor maior que zero | Dona |
| `subscription_suspended` | Cobrança recusada: assinatura suspensa na hora, com o motivo e o caminho para regularizar | Dona |
| `subscription_regularized` | Regularização aprovada (PLT-007) | Dona |
| `cancel_requested` | Cancelamento pedido, informando até quando o acesso vale | Dona |
| `subscription_canceled` | Assinatura encerrada (cancelamento efetivado no vencimento, ou cancelada estando suspensa) | Dona |
| `password_changed` | Senha trocada ou redefinida | A própria pessoa |
| `two_factor_disabled` | 2FA desligado | A própria pessoa |

- **RN-02** — Cada e-mail sai **uma vez** por acontecimento: o registro `notification_log` tem chave de deduplicação única (tipo + assinatura + marco, como o vencimento ou o período). Rodar a cobrança ou o comando de aviso de novo, ou repetir um pedido, não reenvia.
- **RN-03** — O envio é feito pela fila, fora da transação e da resposta; falha de envio nunca desfaz a ação (cadastro, cobrança, troca de senha).
- **RN-04** — Conteúdo: texto simples em português, com o nome do produto vindo de configuração (nunca fixo no código), o nome do negócio e só o necessário (valor, data, motivo). Nunca senha, token, número de cartão ou referência do gateway. Cartão aparece só como bandeira e final.
- **RN-05** — Conta de cortesia (preço zero) não recebe `trial_ending` de cobrança nem `payment_succeeded`; recebe `welcome` e os de segurança.
- **RN-06** — O registro guarda tipo, negócio, chave e data; não guarda o endereço de e-mail.
- **RN-07** — A dona é a pessoa com papel `owner` ativo no negócio; sem dona ativa, nada é enviado (e a falha é registrada no log da aplicação, sem dados pessoais).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Cadastro novo | Concluir | 1 `welcome` para a dona, na fila |
| CA-02 | RN-01, RN-02 | Teste acabando em 2 dias | `notifications:run` duas vezes | 1 `trial_ending` |
| CA-03 | RN-01 | Teste longe do fim, ou cancelamento pedido, ou cortesia | `notifications:run` | Nenhum |
| CA-04 | RN-01, RN-02 | Cobrança aprovada | `billing:run` duas vezes | 1 `payment_succeeded` |
| CA-05 | RN-01 | Cobrança recusada | `billing:run` | 1 `subscription_suspended` e nenhum `payment_succeeded` |
| CA-06 | RN-01 | Regularização aprovada; recusada | PUT do meio de pagamento | 1 `subscription_regularized`; nenhum |
| CA-07 | RN-01 | Cancelamento pedido; efetivado; suspensa cancelada | Cancelar; `billing:run` | `cancel_requested`; `subscription_canceled`; `subscription_canceled` |
| CA-08 | RN-01 | Troca e redefinição de senha | Trocar | `password_changed` para a pessoa |
| CA-09 | RN-01 | 2FA desligado | Desligar | `two_factor_disabled` para a pessoa |
| CA-10 | RN-03 | Fila indisponível | Qualquer ação | Ação concluída; erro só no log |
| CA-11 | RN-04 | Todos os tipos | Renderizar | Sem senha, token, referência do gateway; com o nome do produto da configuração |
| CA-12 | RN-05 | Cortesia | Cobrança | Sem `payment_succeeded` |
| CA-13 | RN-06 | Qualquer envio | Ler `notification_log` | Sem e-mail |
| CA-14 | RN-07 | Negócio sem dona ativa | Disparar | Nada enviado, sem erro |

## API

Sem rotas novas. Comando: `php artisan notifications:run [--as-of=DATA]` (agendado a cada 15 minutos).

## Dados

Tabela `notification_log` (id, tenant_id, kind, dedupe_key único, created_at), dado da plataforma, escrita pelo papel de plataforma.

## Fora do escopo

Tema visual dos e-mails (HTML), preferências de envio, e-mails de marketing, WhatsApp e SMS, caixa de entrada de falhas de entrega.
