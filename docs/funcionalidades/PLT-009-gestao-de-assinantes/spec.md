# PLT-009 · Gestão de assinantes no super admin

- **Status:** aprovada (matriz de `docs/06-perfis-e-permissoes.md`; decisões técnicas delegadas por Gustavo em 2026-10-22)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-001, PLT-002, PLT-005, PLT-006, PLT-010
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A equipe da plataforma precisa enxergar os assinantes, tratar exceções (liberar uma funcionalidade fora do plano, estender um teste, suspender na mão) e ajudar quem está com dúvida entrando na conta dele, sempre com rastro e aviso à dona.

## Regras de negócio

- **RN-01** — `GET /platform/tenants` (super admin e suporte): lista com paginação por cursor (`limit` 1–50, padrão 20), do mais novo para o mais antigo, com busca `q` (nome ou endereço) e filtro `status` (da assinatura: `trialing`, `active`, `suspended`, `canceled`). Cada item: id, nome, endereço (slug), nicho, criado em, plano, ciclo, status, preço, fim do teste/período e `cancel_at_period_end`.
- **RN-02** — `GET /platform/tenants/{id}` (equipe): o item da lista mais a dona (nome e e-mail), nº de pessoas ativas, meio de pagamento (bandeira e final), cupom usado, últimas 5 faturas e as funcionalidades efetivas do negócio (com a origem: exceção, plano ou padrão). Nunca senha, token ou referência do gateway.
- **RN-03** — Exceções por assinante (só super admin): `PUT /platform/tenants/{id}/entitlements/{feature}` (`enabled`, `limit` se a funcionalidade for de limite, `visible`) e `DELETE` para voltar à regra do plano. A exceção vale em tudo que consulta o `EntitlementResolver` (por exemplo o limite de usuários da equipe) e tem prioridade sobre o plano.
- **RN-04** — Suspender na mão (só super admin): `POST /platform/tenants/{id}/suspend` com `reason` (5 a 200 caracteres) coloca a assinatura em `suspended` (só leitura, como na falta de pagamento) e guarda o motivo; `POST …/unsuspend` volta para `active`, ou para `trialing` se a assinatura nunca foi paga (a cobrança acontece no vencimento, como sempre). A suspensão com motivo (da equipe) não se desfaz pagando: `PUT /subscription/payment-method` responde 409 `suspended_by_support`; a dona ainda pode cancelar. Só vale para assinaturas `trialing`/`active` (suspender) ou `suspended` (reativar); cancelada: 409.
- **RN-05** — Estender o teste (só super admin): `POST /platform/tenants/{id}/extend-trial` com `days` de 1 a 30 soma ao fim do teste; só em `trialing`; 409 nos outros casos.
- **RN-06** — Entrar como assinante: `POST /platform/tenants/{id}/impersonate` (super admin e suporte) com `reason` (10 a 200 caracteres) devolve um token de acesso da dona do negócio, válido por 30 minutos, marcado como acesso de suporte (`impersonated_by`). Cada uso: registro de auditoria com a pessoa da equipe, o negócio e o motivo; aviso por e-mail à dona (`support_access`, sem o motivo). `GET /auth/me` mostra `impersonating: true`.
- **RN-07** — Limites do acesso de suporte: não pode trocar senha, mexer em 2FA, sair de todos os acessos nem gerir a assinatura (meio de pagamento, cancelamento, faturas): essas rotas respondem 403 `impersonation_forbidden`. O resto funciona com o papel da dona. O token cai se o acesso da dona cair (senha trocada, pessoa desativada) como qualquer outro.
- **RN-08** — Toda ação (suspender, reativar, estender, exceção, entrar) vai para a auditoria da plataforma com antes/depois, quem fez e (nas que têm) o motivo.
- **RN-09** — Tudo nesta funcionalidade fica nos hosts da plataforma e exige login da equipe; o suporte só lê e entra como assinante; as demais ações são do super admin. Negócio inexistente: 404.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Vários negócios | Listar com `limit`, `q`, `status` | Página certa, novos primeiro, cursor, filtros; `limit` fora de 1–50 dá 422 |
| CA-02 | RN-02 | Negócio com dona, equipe, cupom e faturas | Detalhe | Dados completos sem segredos; funcionalidades com origem |
| CA-03 | RN-03 | Super admin | Cria e remove exceção | Efetivo muda; vale no limite de usuários da equipe; remoção volta ao plano |
| CA-04 | RN-03 | Funcionalidade de liberar/bloquear com `limit`; limite negativo | PUT | 422 |
| CA-05 | RN-04 | Assinatura ativa | Suspender com motivo | `suspended`, só leitura na hora, motivo guardado; sem motivo dá 422 |
| CA-06 | RN-04 | Suspensa na mão | Reativar | Volta ao status certo; escrita liberada |
| CA-07 | RN-04 | Cancelada | Suspender/reativar | 409 |
| CA-08 | RN-05 | Em teste | Estender 7 dias | Fim do teste +7; outros status 409; dias fora de 1–30 dá 422 |
| CA-09 | RN-06 | Suporte | Entrar com motivo | Token da dona (30 min), `impersonated_by` gravado, auditoria, e-mail à dona; sem motivo 422 |
| CA-10 | RN-07 | Token de suporte | Trocar senha, 2FA, logout-all, gerir assinatura | 403 `impersonation_forbidden`; `me` mostra `impersonating` |
| CA-11 | RN-07 | Token de suporte; dona desativa-se / troca a senha | Usar o token | Deixa de valer |
| CA-12 | RN-09 | Suporte; sem login; host de assinante | Ações do super admin; qualquer rota | 403; 401; 404 |
| CA-13 | RN-08 | Qualquer ação | — | Auditoria com quem, o quê, antes/depois |
| CA-14 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`. Rotas em `/api/v1/platform/tenants…` (lista, detalhe, `suspend`, `unsuspend`, `extend-trial`, `entitlements/{feature}`, `impersonate`).

## Dados

`tenant_entitlement_overrides` (exceções por negócio), `subscriptions.suspended_reason` e `user_access_tokens.impersonated_by`. O papel de plataforma escreve as exceções e a suspensão; o acesso de suporte é emitido com o papel de autenticação.

## Fora do escopo

Tela (PLT-001b), exclusão de negócio, troca de plano à força, e-mail de aviso de suspensão manual (o PLT-010 cobre a suspensão por cobrança), busca por e-mail da dona.
