# Cadastro do assinante e início do teste

> Para quem desenvolve e opera a plataforma.

## Para que serve

Permite que um profissional contrate sozinho: informa o negócio, escolhe o endereço, o nicho e o plano, cadastra o meio de pagamento e começa um teste de 7 dias. O pagamento só é cobrado no 7º dia, se não houver cancelamento.

## Fluxo (API)

1. `GET /api/v1/signup/slug-availability?slug=meuestudio` diz se o endereço está livre (`available`) ou o motivo: `invalid`, `reserved` ou `taken`.
2. `GET /api/v1/plans` lista os planos ativos com preços, para a escolha.
3. `POST /api/v1/signup` com o cabeçalho `Idempotency-Key` (obrigatório) e o corpo do cadastro cria, em uma única transação: negócio, dona, vínculo, meio de pagamento e assinatura em `trialing`.

Tudo isso só responde nos hosts da plataforma (`localhost`, `admin.localhost`); num subdomínio de assinante dá 404.

## Meio de pagamento de teste

Enquanto o gateway real não existe, `BILLING_GATEWAY=fake` usa um meio simulado. O campo `payment_method_token` aceita:

| Token | Efeito |
| --- | --- |
| `tok_ok` | Aceito; a cobrança futura é aprovada |
| `tok_fail_on_charge` | Aceito; a cobrança do 7º dia falhará (para testar a suspensão) |
| `tok_declined` | Recusado já no cadastro |

Qualquer outro valor é recusado. Nenhum cartão real é usado nem guardado: só referência, bandeira e final.

## Regras importantes

- O pagamento é obrigatório para começar o teste, mas **nada é cobrado** no cadastro.
- O preço do ciclo escolhido (mensal ou anual) fica gravado na assinatura: mudar o plano depois não altera quem já assinou.
- Repetir o mesmo pedido com a mesma `Idempotency-Key` devolve a mesma resposta (cabeçalho `Idempotent-Replayed: true`) sem criar nada. Chave igual com corpo diferente dá 422; um pedido idêntico ainda em andamento dá 409. A ordem das chaves do JSON não importa, e reservas abandonadas (mais de 5 minutos sem resposta) são liberadas.
- O e-mail é único na plataforma; a senha tem no mínimo 12 caracteres; os termos precisam ser aceitos (o aceite e a versão ficam registrados).
- Nichos de lançamento: `tatuagem`, `manicure`, `barbearia`, `estetica_automotiva` (lista em `config/tenancy.php`).

## Segurança do cadastro

- Só `application/json` (415 nos demais formatos); a chave de idempotência vale só para o IP que a criou.
- `BILLING_GATEWAY` precisa ser definido em cada ambiente; `fake` só roda em `local` e `testing`.
- E-mail apenas ASCII, endereço sem `xn--`, senha até 72 bytes.
- Limite por IP (10/min) e teto global (300/hora). Atrás de balanceador é preciso configurar `trustProxies` com os IPs dele, senão todos os clientes compartilham o mesmo IP (pendência de infraestrutura, em `docs/08`).

## Bancos e papéis

O cadastro escreve com o papel `app_platform` (conexão `pgsql_platform`), que só acessa as tabelas do cadastro e da cobrança. O papel da API (`app_runtime`) só lê `tenants`, `subscriptions` e `payment_methods`, lê `users` sem a senha e não acessa `idempotency_keys` nem `migrations`. Num ambiente já criado, recrie o volume do banco (`docker compose down -v && docker compose up -d --build --wait`) para ganhar o papel novo.

## Limitações

- Não há login do assinante (PLT-006), cobrança do 7º dia e suspensão (PLT-004/005), cancelamento (PLT-007), cupom (PLT-012) nem e-mail de boas-vindas (PLT-010).
- Se a gravação falhar depois de o gateway aceitar o cartão, o cliente criado no gateway é removido (melhor esforço, com registro do erro se a remoção falhar).
- O campo `coupon_code` é recusado (422) até existir o cupom (PLT-012).
- O cadastro responde 422 quando o e-mail já existe (revela que o e-mail está cadastrado).
- A versão dos termos é provisória.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-25 | Cadastro com teste de 7 dias, meio de pagamento de teste, idempotência e papel de plataforma |
