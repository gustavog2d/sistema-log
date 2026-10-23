# PLT-012 · Cupons e assinaturas de cortesia

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22: "cupom criado pelo super admin, com validade e limite de uso; 100% = conta de cortesia")
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-002, PLT-003
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Gustavo quer liberar contas de teste sem pagamento (cupom de 100%) e dar descontos proporcionais. O super admin cria cupons com validade e limite de uso; quem se cadastra informa o código e a assinatura nasce com o preço já reduzido.

## Histórias de usuário

- Como super admin, quero criar um cupom com percentual, validade e limite de usos, e desativá-lo quando quiser.
- Como futuro assinante, quero informar um cupom no cadastro e ver o preço final antes de confirmar.
- Como Gustavo, quero criar contas de cortesia (100%) que não exigem cartão.

## Regras de negócio

- **RN-01** — Cupom: `code` (3 a 40 caracteres, letras, números e hífen; guardado em maiúsculas; único sem diferenciar maiúsculas), `percent_off` de 1 a 100, `max_redemptions` (opcional; vazio = sem limite), `valid_from` e `valid_until` (opcionais), `description` (opcional, interna) e `status` (`active`/`inactive`). Código e percentual não mudam depois de criados.
- **RN-02** — Só o super admin cria e altera cupons; a equipe (suporte) pode listar. Alterar: `status`, `max_redemptions` (não abaixo dos usos já feitos), `valid_from`, `valid_until`, `description`. Cupom já usado não pode ser apagado (não há exclusão; desative).
- **RN-03** — Um cupom vale quando está `active`, dentro da validade (início e fim) e com usos abaixo do limite. Qualquer outro caso (não existe, inativo, fora da validade, esgotado) responde igual: 422 em `coupon_code` com "Cupom inválido ou indisponível.".
- **RN-04** — Aplicação: o preço da assinatura é congelado já com o desconto (`price_cents = preço do ciclo × (100 − percentual) / 100`, arredondado para baixo em centavos) e vale enquanto a assinatura existir. O uso é contado e gravado (cupom, negócio, assinatura, preço original e final) na mesma transação do cadastro; cadastro que falha não consome o cupom.
- **RN-05** — Cortesia (preço final 0): o cartão deixa de ser obrigatório; sem `payment_method_token` nenhum cliente é criado no gateway, a assinatura nasce em teste sem meio de pagamento e as renovações geram fatura de valor zero, sem chamar o gateway. Preço final maior que zero continua exigindo o meio de pagamento.
- **RN-06** — `POST /signup/coupon-check` (público, com limite de tentativas) recebe `code`, `plan` e `cycle` e devolve o desconto e o preço final para a tela mostrar; cupom indisponível dá o mesmo 422 da RN-03. Não consome o cupom.
- **RN-07** — O limite de usos vale mesmo com cadastros simultâneos: o cupom é travado durante a gravação do cadastro e o último uso nunca é concedido a dois.
- **RN-08** — Auditoria: `coupon.created`, `coupon.updated` e `coupon.redeemed` (sem dados pessoais).
- **RN-09** — `GET /platform/coupons` mostra, para cada cupom, os usos (`redeemed_count`) e o limite.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-02 | Super admin | Cria cupom | 201; código em maiúsculas; usos 0 |
| CA-02 | RN-01 | Código repetido (outra caixa), percentual 0 ou 101, limite 0, validade invertida | Criar | 422 |
| CA-03 | RN-02 | Suporte; sem login | Criar/alterar; listar | 403/401; suporte lista |
| CA-04 | RN-02 | Cupom usado 2 vezes | Baixar o limite para 1; mudar status, validade | 422; 200 |
| CA-05 | RN-04 | Cupom de 20% | Cadastro no plano de R$ 50 | Preço congelado R$ 40; uso contado; registro gravado |
| CA-06 | RN-03 | Inexistente, inativo, vencido, ainda não iniciado, esgotado | Cadastro | 422 igual; nada criado; uso não contado |
| CA-07 | RN-05 | Cupom de 100% | Cadastro sem cartão | 201; sem cliente no gateway; preço 0 |
| CA-08 | RN-05 | Cupom abaixo de 100% | Cadastro sem cartão | 422 em `payment_method_token` |
| CA-09 | RN-05 | Cortesia chegando ao vencimento | `billing:run` | Ativa; fatura de valor 0; gateway não chamado |
| CA-10 | RN-04 | Cadastro falha (e-mail repetido, cartão recusado) | Com cupom | Uso não consumido |
| CA-11 | RN-06 | Cupom válido; indisponível | `POST /signup/coupon-check` | 200 com preço final; 422 igual |
| CA-12 | RN-07 | Cupom com 1 uso restante | Dois cadastros em sequência | O segundo recebe 422 |
| CA-13 | RN-08 | Criar, alterar, usar | — | Eventos de auditoria gravados |
| CA-14 | RN-09 | Cupons | `GET /platform/coupons` | `redeemed_count` e `max_redemptions` corretos |
| CA-15 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/api/v1/platform/coupons` | Lista (equipe) | 401, 403 |
| POST | `/api/v1/platform/coupons` | Cria (super admin) | 401, 403, 422 |
| PATCH | `/api/v1/platform/coupons/{id}` | Altera (super admin) | 401, 403, 404, 422 |
| POST | `/api/v1/signup/coupon-check` | Mostra o preço com o cupom | 422, 429 |
| POST | `/api/v1/signup` | (já existe) aceita `coupon_code` | + 422 |

## Dados

`coupons` e `coupon_redemptions` (dados da plataforma, sem tenant). O papel de plataforma escreve; a API só lê. A resposta do cadastro passa a trazer `coupon` (código e percentual) quando houve cupom.

## Fora do escopo

Cupom por plano ou por ciclo, desconto temporário (só nos primeiros ciclos), cupom em assinatura já existente, tela do super admin.
