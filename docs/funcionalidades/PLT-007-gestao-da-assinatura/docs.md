# Gestão da assinatura

> Para quem desenvolve e opera a plataforma.

## Para que serve

Deixa a dona do negócio cuidar da própria assinatura pela API: trocar o cartão, voltar a usar o sistema depois de uma suspensão, cancelar e ver as faturas.

## Como funciona

- Todas as rotas exigem login e o papel de dona (`owner`); funcionam também com a assinatura suspensa ou cancelada.
- `PUT /api/v1/subscription/payment-method` (com `Idempotency-Key`, corpo `payment_method_token`): em teste ou ativa só troca o meio de pagamento. Suspensa: cobra o plano na hora; aprovado, a assinatura volta a `active` com período novo e a escrita é liberada; recusado, responde 402 `payment_failed` e nada muda (o meio antigo fica). Cancelada responde 409.
- `POST /subscription/cancel`: em teste ou ativa registra o pedido (`canceled_at`) e o acesso continua até o fim do teste ou do período pago; no vencimento o `billing:run` encerra a assinatura sem cobrar. Durante o teste nunca há cobrança; depois da primeira cobrança não há estorno. Suspensa cancela na hora.
- `POST /subscription/reactivate` desfaz o pedido enquanto ele ainda não valeu.
- `GET /subscription/invoices?limit=&cursor=`: faturas do negócio, das mais novas para as mais antigas.
- `GET /subscription` mostra também `payment_method` (tipo, bandeira, final) e `cancel_at_period_end`. A API nunca lê a referência do gateway: usa a visão `payment_method_summaries`.
- Auditoria: `subscription.payment_method_changed`, `subscription.regularized`, `subscription.cancel_requested`, `subscription.canceled`, `subscription.reactivated`.

## Limitações

- Sem troca de plano (PLT-013), sem e-mails de aviso (PLT-010), sem tela (painel web).
- O gateway é o de teste; com o Asaas real (INT-001) a regularização pode precisar de confirmação assíncrona.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Troca de meio de pagamento, regularização, cancelamento e faturas |
