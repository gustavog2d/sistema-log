# Cobrança do fim do teste e renovações

> Para quem desenvolve e opera a plataforma.

## Para que serve

Cobra a assinatura quando o teste de 7 dias acaba e a cada renovação (mensal ou anual). Se o pagamento não passa, o acesso é **suspenso na mesma hora**, sem carência. Por enquanto usa o meio de pagamento de teste (`fake`).

## Como funciona

`php artisan billing:run` processa as assinaturas vencidas (teste terminado ou período terminado). Roda sozinho a cada 5 minutos pelo agendador do Laravel (`php artisan schedule:work` em desenvolvimento; um cron `schedule:run` por minuto em produção).

- **Aprovada:** a assinatura fica `active`, com um período de 1 mês (ou 1 ano) começando no vencimento; nasce uma fatura `paid` com o preço do cadastro.
- **Recusada ou sem meio de pagamento:** a assinatura vira `suspended` na hora; a fatura fica `failed` com o motivo (`card_declined`, `no_payment_method`). Não há nova tentativa automática: regularizar é do PLT-007.
- **Falha técnica** (gateway fora do ar): nada muda; tenta de novo na próxima execução.
- **Preço zero** (cortesia): não chama o gateway; a fatura nasce `paid` de 0.
- Rodar duas vezes nunca cobra duas vezes (chave determinística por assinatura e período; linha da assinatura travada).
- Atraso de mais de um ciclo (sistema parado): cobra um período só, a partir da data da execução.
- Dia da cobrança preservado: o dia é o do fim do teste. 31/01 → 28/02 → 31/03 → 30/04 (um mês curto não muda o dia para sempre).

## Como testar o fluxo sem esperar 7 dias

```bash
# 1. Cadastre pelo POST /api/v1/signup (veja o PLT-003): tok_ok aprova, tok_fail_on_charge falha na cobrança.
# 2. Simule a passagem do tempo:
docker compose exec api php artisan billing:run --as-of="2026-12-01 10:00:00"
# 3. Veja o resultado em GET /api/v1/subscription (no subdomínio do negócio) ou nas tabelas invoices e subscriptions.
```

## Segurança e dados

- Faturas (`invoices`) têm `tenant_id` e RLS: a API só lê as do negócio em contexto, e só o papel de plataforma grava (informando o tenant na transação).
- A auditoria (`billing.charged`, `billing.failed`, `subscription.suspended`) não leva dados de pagamento e nunca desfaz uma cobrança já registrada se falhar.

## Limitações

- Sem webhooks nem conciliação (entram com o gateway real), sem e-mails (PLT-010), sem regularização nem cancelamento (PLT-007).
- Com o gateway real, a chamada de cobrança deve sair **de fora** da transação com a linha travada: reservar a fatura em estado `pending`, confirmar, cobrar e depois finalizar (hoje a fatura só tem `paid` e `failed`). A chave determinística (vencimento original) já impede a cobrança dupla enquanto isso não é feito. Item rastreado no backlog (INT-001).
- `--as-of` é recusado fora de `local` e `testing`.
- O comando termina com código diferente de zero se houver erro técnico (o agendador e o monitoramento enxergam).

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | `billing:run`, faturas, suspensão imediata e simulação de tempo |
