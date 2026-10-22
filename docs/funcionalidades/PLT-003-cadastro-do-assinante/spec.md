# PLT-003 · Cadastro do assinante e início do teste (API)

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-24; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** FND-005, PLT-002
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Um profissional precisa conseguir contratar sozinho: informar o negócio, escolher o endereço (subdomínio), o nicho e o plano, cadastrar o meio de pagamento e começar o teste de 7 dias. O pagamento só será cobrado no 7º dia, se ele não cancelar. Esta entrega é a **API** do cadastro. A cobrança no 7º dia, as renovações e a suspensão por falha são o PLT-004/PLT-005; o login do assinante é o PLT-006; as telas vêm depois.

O meio de pagamento é um **meio de teste simulado** por trás de uma interface própria (`PaymentGateway`); o gateway real (Asaas) entra por último.

## Histórias de usuário

- Como visitante, quero saber se o endereço que escolhi está livre, para não perder tempo no cadastro.
- Como profissional, quero me cadastrar, escolher o plano e cadastrar o pagamento, para começar meu teste de 7 dias.
- Como Gustavo, quero que um cadastro repetido (clique duplo, nova tentativa) não crie dois negócios.

## Regras de negócio

- **RN-01** — `POST /api/v1/signup` (público, nos hosts da plataforma) cria, em uma única transação: o negócio (`tenant`) com `niche`, a pessoa (`user`), o vínculo dela como dona (`membership`, com RLS), o meio de pagamento (`payment_method`, sem dados sensíveis: só referência, bandeira e final) e a assinatura em teste (`subscription`).
- **RN-02** — **O pagamento é obrigatório para iniciar o teste.** Sem meio de pagamento válido não há cadastro. O meio de pagamento é validado no cadastro, mas **nada é cobrado**.
- **RN-03** — A assinatura nasce `trialing`, com `trial_ends_at` = agora + `trial_days` do plano (7). Guarda uma cópia do preço do ciclo escolhido (`price_cents`, `currency`) para que mudar o plano depois não altere quem já assinou.
- **RN-04** — Plano e ciclo: só planos `active`; ciclo `monthly` ou `yearly`. O preço anual é o calculado do catálogo (mensal × 12 com o desconto).
- **RN-05** — O endereço (`slug`) segue a regra do FND-005 (formato, reservados) e é único. `GET /api/v1/signup/slug-availability?slug=` responde `available`, ou o motivo (`invalid`, `reserved`, `taken`).
- **RN-06** — Nicho: um de `tatuagem`, `manicure`, `barbearia`, `estetica_automotiva` (lista em configuração; novos nichos entram sem mudar código de regra).
- **RN-07** — Dona: nome, e-mail e senha (mínimo 12 caracteres). O e-mail é único na plataforma. Termos de uso e privacidade precisam ser aceitos (`accept_terms: true`), e o aceite (data e versão) é registrado.
- **RN-08** — Idempotência: o cabeçalho `Idempotency-Key` é obrigatório. Repetir o pedido com a mesma chave e o mesmo corpo devolve a **mesma resposta** sem criar nada de novo; a mesma chave com corpo diferente dá 422.
- **RN-09** — Escrita de plataforma (criar negócio, assinatura, meio de pagamento) usa um papel de banco próprio (`app_platform`). O papel da API (`app_runtime`) continua **sem** poder escrever em `tenants`, `subscriptions` e `payment_methods`.
- **RN-10** — Meio de pagamento de teste (somente com `BILLING_GATEWAY=fake`): `tok_ok` (aprovado), `tok_fail_on_charge` (aceito no cadastro; falhará na cobrança do 7º dia, para testar a suspensão) e `tok_declined` (recusado no cadastro). Qualquer outro token é recusado.
- **RN-11** — Limite de 10 requisições por minuto por IP no cadastro e 60 na consulta de endereço. Erros em Problem Details.
- **RN-12** — Auditoria do cadastro (`signup.completed`, com ids e plano; sem senha nem token de pagamento).
- **RN-13** — Cupom de desconto e assinatura de cortesia entram no PLT-012; o campo `coupon_code` ainda não é aceito (enviar dá 422).
- **RN-14** — O gateway é chamado antes de gravar. Se a gravação falhar depois (inclusive em corrida por endereço ou e-mail, que vira 422), o cliente criado no gateway é removido (compensação). A auditoria é gravada só depois do sucesso.
- **RN-15** — Uma reserva de `Idempotency-Key` sem resposta e com mais de 5 minutos é considerada abandonada e liberada; chaves com mais de um dia são apagadas. A ordem das chaves do JSON não muda a identidade do pedido.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-03 | Dados válidos, plano ativo, `tok_ok` | `POST /signup` | 201 com negócio, assinatura `trialing`, fim do teste em 7 dias e meio de pagamento (bandeira e final) |
| CA-02 | RN-01 | Cadastro concluído | Consulta ao banco | Existem tenant, user, membership dona, payment_method e subscription ligados |
| CA-03 | RN-02 | Sem `payment_method_token` ou com `tok_declined` | `POST /signup` | 422 e nada criado |
| CA-04 | RN-02 | Cadastro com sucesso | Consulta | Nenhuma cobrança foi feita |
| CA-05 | RN-04 | Plano inexistente, em rascunho ou arquivado; ciclo inválido | `POST /signup` | 422 |
| CA-06 | RN-04 | Ciclo anual | `POST /signup` | `price_cents` = preço anual do catálogo |
| CA-07 | RN-03 | Plano alterado depois do cadastro | Ler a assinatura | Mantém o preço do cadastro |
| CA-08 | RN-05 | Endereço livre, ocupado, reservado, inválido | `GET /slug-availability` | `available` ou `taken`, `reserved`, `invalid` |
| CA-09 | RN-05 | Endereço ocupado ou reservado | `POST /signup` | 422 e nada criado |
| CA-10 | RN-06 | Nicho fora da lista | `POST /signup` | 422 |
| CA-11 | RN-07 | E-mail já cadastrado; senha curta; termos não aceitos | `POST /signup` | 422 e nada criado |
| CA-12 | RN-07 | Cadastro concluído | Consulta | Aceite de termos registrado (data e versão); senha em hash |
| CA-13 | RN-08 | Mesma `Idempotency-Key` e mesmo corpo | Repetir | Mesma resposta, um único negócio |
| CA-14 | RN-08 | Mesma chave, corpo diferente | Repetir | 422 |
| CA-15 | RN-08 | Sem `Idempotency-Key` | `POST /signup` | 422 |
| CA-16 | RN-09 | Papel `app_runtime` | Inserir em `tenants`, `subscriptions` ou `payment_methods` | Negado pelo banco |
| CA-17 | RN-09 | Falha no meio do cadastro | Forçar erro após criar o negócio | Nada fica gravado (transação) |
| CA-18 | RN-10 | `tok_fail_on_charge` | `POST /signup` | 201 (a falha só ocorrerá na cobrança) |
| CA-19 | RN-11 | Mais de 10 pedidos por minuto do mesmo IP | `POST /signup` | 429 |
| CA-20 | RN-12 | Cadastro concluído | Auditoria | Evento sem senha nem token de pagamento |
| CA-21 | RN-01 | Host de um assinante | `POST /signup` | 404 |
| CA-22 | isolamento | Duas contas criadas | Contexto do tenant A | Só vê o próprio vínculo (RLS em `memberships`) |

## Permissões

Rotas públicas (sem login), restritas aos hosts da plataforma.

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/api/v1/signup/slug-availability` | Endereço livre? | 422, 429 |
| POST | `/api/v1/signup` | Cria negócio, dona, pagamento e teste | 409 (pedido idêntico em andamento), 422, 429 |

## Dados

- Novas tabelas de plataforma: `users` (identidade global: e-mail único, senha em hash), `subscriptions`, `payment_methods`, `idempotency_keys` (sem RLS; escrita só pelo papel `app_platform`).
- Nova tabela de assinante: `memberships` (tenant_id, user_id, role), com RLS e FORCE.
- `tenants` ganha `niche`. `users` guarda também o aceite dos termos.
- Papel de banco novo `app_platform` (sem superusuário, sem BYPASSRLS), conexão `pgsql_platform`.
- Migration compatível com a versão anterior: sim (só adições).

## Eventos, auditoria e notificações

`signup.completed` na auditoria. E-mail de boas-vindas fica para o PLT-010.

## Impacto no mobile

Nenhum. O app usará o mesmo cadastro.

## Fora do escopo

Login do assinante (PLT-006), cobrança do 7º dia, renovação e suspensão (PLT-004/005), cancelamento (PLT-007), cupons (PLT-012), e-mails (PLT-010), telas, gateway real.

## Riscos e perguntas em aberto

- O cadastro revela que um e-mail já existe (422). É o comportamento comum; a alternativa (e-mail de confirmação) é do PLT-006/010.
- `users` é identidade global; o mesmo e-mail em mais de um negócio só chega com o login (PLT-006).
- Versão dos termos de uso é provisória até existir o texto jurídico.
