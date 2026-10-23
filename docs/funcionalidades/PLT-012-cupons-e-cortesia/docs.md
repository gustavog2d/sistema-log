# Cupons e contas de cortesia

> Para quem desenvolve e opera a plataforma.

## Para que serve

O super admin cria cupons de desconto (inclusive 100%, para contas de cortesia). Quem se cadastra informa o código e a assinatura nasce com o preço já reduzido.

## Como funciona

- `POST /api/v1/platform/coupons` (super admin): `code` (3 a 40, letras, números e hífen; guardado em maiúsculas), `percent_off` (1 a 100), `max_redemptions`, `valid_from`, `valid_until`, `description`. `PATCH /platform/coupons/{id}` altera `status`, limite (nunca abaixo dos usos), validade e descrição; código e percentual não mudam. `GET /platform/coupons` (equipe) mostra os usos.
- `coupon_code` no cadastro: o preço da assinatura é congelado com o desconto (arredondado para baixo em centavos) e vale enquanto a assinatura existir. O uso é contado e registrado (`coupon_redemptions`) na mesma transação do cadastro, com o cupom travado.
- Qualquer cupom indisponível (não existe, inativo, vencido, ainda não iniciado, esgotado) responde o mesmo 422 em `coupon_code`.
- Preço final zero (cortesia): `payment_method_token` é opcional; sem ele, nenhum cliente é criado no gateway, e as renovações geram fatura de valor zero sem chamar o gateway. Preço acima de zero continua exigindo o meio de pagamento.
- `POST /signup/coupon-check` (público, 20/min por IP) devolve o preço final e se o cartão é obrigatório, sem consumir o cupom.
- Auditoria: `coupon.created`, `coupon.updated`, `coupon.redeemed`.
- O papel de banco da API só lê `coupons`; não lê os usos.

## Limitações

- Cupom por plano ou ciclo, desconto só nos primeiros ciclos e cupom em assinatura existente não existem. Conta de cortesia sem cartão não tem como trocar o meio de pagamento (não há cliente no gateway). Sem tela.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Cupons, cadastro com cupom e cortesia |
