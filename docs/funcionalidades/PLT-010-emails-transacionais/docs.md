# E-mails transacionais

> Para quem desenvolve e opera a plataforma.

## Para que serve

Avisa a dona (e quem mexeu na conta) do que acontece com a assinatura e com a segurança: boas-vindas, fim do teste chegando, pagamento aprovado, suspensão, regularização, cancelamento, troca de senha e 2FA desligado.

## Como funciona

- `App\Notifications\Notifier` decide o destinatário (a dona ativa do negócio ou a pessoa que fez a ação), grava uma linha em `notification_log` com uma chave de deduplicação única e só então coloca o e-mail na fila (`TransactionalMail`). Se a chave já existe, não envia de novo. Se a fila falhar, a chave é liberada e o erro vai para o log (sem o endereço); a ação de origem (cadastro, cobrança, troca de senha) nunca é desfeita.
- Gatilhos: cadastro (`welcome`), `billing:run` (`payment_succeeded`, `subscription_suspended`, `subscription_canceled` ao efetivar o cancelamento), `PUT /subscription/payment-method` (`subscription_regularized`), `POST /subscription/cancel` (`cancel_requested`, ou `subscription_canceled` se estava suspensa), troca e redefinição de senha (`password_changed`), desligar o 2FA (`two_factor_disabled`) e o comando `php artisan notifications:run [--as-of=DATA]`, agendado a cada 15 minutos, para o `trial_ending` (2 dias antes do fim, sem cancelamento pedido e com valor a cobrar).
- Conta de cortesia só recebe os avisos de boas-vindas e de segurança.
- Os textos ficam em `resources/views/emails/notifications/` (texto simples). O nome do produto vem de `config('app.name')`; o texto nunca leva senha, token, número de cartão ou referência do gateway.
- Em desenvolvimento os e-mails aparecem no Mailpit (http://localhost:8025); o worker precisa estar rodando para a fila.

- Avisos de segurança (troca de senha, 2FA) repetem no máximo uma vez a cada 5 minutos por pessoa; `password/change` e `2fa/disable` têm limite de 10 por hora por pessoa. O `2fa/disable` só avisa se o 2FA estava ligado. Os avisos de regularização e de cancelamento pedido são um por pedido. `notification_log` guarda 90 dias.
- O aviso de boas-vindas vai para um e-mail ainda não verificado: por isso o nome do negócio não entra no assunto e os nomes do cadastro não aceitam links, e-mails nem caracteres de controle.
- Datas aparecem no fuso do negócio (`tenants.timezone`). Os links usam `APP_TENANT_SCHEME` (https em produção).
- O worker tenta o envio 3 vezes; se a fila falhar depois de aceitar o aviso, ele se perde (o de fim do teste é refeito na rodada seguinte). Em produção, definir `MAIL_MAILER=smtp` (o padrão `log` grava o e-mail inteiro) e podar `failed_jobs`.

## Limitações

- Sem tema HTML, sem preferências de envio, sem aviso de falha de entrega. A hora do fim do teste usa o fuso de São Paulo.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Nove avisos transacionais com deduplicação |
