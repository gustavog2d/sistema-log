# ADR 0003 · Gateway de pagamento

- **Status:** proposto (webhooks e Pix Automático conferidos; faltam tarifas, nota fiscal e sandbox)
- **Data:** 2026-10-22
- **Decisores:** Gustavo

## Contexto

A plataforma cobra assinatura mensal e anual de profissionais brasileiros. O sistema nunca guarda dados de cartão, os webhooks são idempotentes e o gateway fica atrás da interface `BillingGateway` ([docs/05](../../05-planos-e-assinaturas.md)). Critérios: cartão recorrente, Pix Automático, boleto, taxas, nota fiscal, qualidade dos webhooks, sandbox e API de assinaturas.

## Opções consideradas

Asaas, Iugu, Pagar.me, Mercado Pago e Stripe.

## Levantamento preliminar (NÃO verificado)

Fontes são blogs e comparativos de terceiros, com números divergentes entre si. Nenhuma trata de webhooks. Servem só para formar uma lista curta.

| Gateway | Indicativo de tarifas | Observação |
| --- | --- | --- |
| Asaas | Pix/boleto ~R$ 1,99; cartão ~2,99% + R$ 0,49; sem mensalidade | Citado como especializado em assinaturas; foco em PME brasileira |
| Iugu | Pix ~0,99%; cartão 2,49% a 3,34% | Citado como especializado em assinaturas; mensalidade "sob consulta" |
| Pagar.me | Cartão 2,99% a 4,19% | Faixa baixa depende de volume |
| Mercado Pago | Cartão ~3,98% a 4,99% | Repasse mais lento (14 a 30 dias) |
| Stripe | Cartão ~3,49% a 3,99% + R$ 0,39; R$ 55 por contestação | Mais forte em API e documentação; Pix Automático e boleto a confirmar |

Fontes: [Mind Group (jul/2026)](https://mindconsulting.com.br/2026/07/gateways-pagamento-online-brasil-comparativo-2026/), [Asaas × iugu (Quasa)](https://quasa.io/pt/media/asaas-ou-iugu-tarifa-publicada-vence-ate-o-volume-pedir-proposta), [Pix Automático para SaaS](https://forjadesistemas.com.br/blog/pix-automatico-recorrencia-saas-proprio-2026/).

## Levantamento na documentação oficial (2026-10-22)

- **Asaas** ([webhooks](https://docs.asaas.com/docs/webhooks-2), [eventos de assinatura](https://docs.asaas.com/docs/eventos-para-assinaturas), [Pix Automático](https://docs.asaas.com/docs/pix-automatico)): entrega *at least once*, com `id` do evento para idempotência; a documentação orienta persistir o evento, responder 200 rápido e processar em fila (o que bate com o nosso desenho). Pix Automático com retentativas (até 3 em 7 dias, definidas na criação da autorização) e eventos de assinatura (`SUBSCRIPTION_CREATED/UPDATED/INACTIVATED/DELETED`). Tarifas não conferidas.
- **iugu** ([cobrança recorrente](https://dev.iugu.com/docs/cobran%C3%A7a-recorrente-assinaturas), [Pix Automático](https://dev.iugu.com/docs/cobrar-com-pix-automatico-por-api)): planos e assinaturas por API, gatilhos como `invoice.status_changed`. Há Pix Automático por API, mas no Pix comum da assinatura a renovação só segue depois de a fatura ser paga. Tarifas não encontradas na documentação.
- **Stripe** ([assinatura com Pix](https://docs.stripe.com/billing/subscriptions/pix), [preços BR](https://stripe.com/en-br/pricing)): suporta Pix Automático e boleto em assinaturas, mas o Pix está **somente por convite** para negócios no Brasil; boleto listado a R$ 3,45 por boleto pago (página de preços possivelmente desatualizada).
- **Pagar.me e Mercado Pago:** não consultados na documentação oficial; sem sinal que os coloque à frente.

## Decisão

Pendente. Pela documentação, **Asaas** é o candidato mais forte (assinaturas, Pix Automático com retentativas e webhooks idempotentes documentados), seguido de **iugu**; **Stripe** perde força enquanto o Pix for por convite no Brasil. Antes de aceitar, falta confirmar nas páginas oficiais: tarifas atuais, emissão de nota fiscal, sandbox, prazo de repasse e regras de contestação, e rodar uma prova de conceito do ciclo de assinatura no sandbox do Asaas.

## Consequências

- Como o gateway fica atrás de `BillingGateway`, a escolha não é irreversível. Ainda assim, PLT-004 não pode ser especificado sem este ADR aceito.
