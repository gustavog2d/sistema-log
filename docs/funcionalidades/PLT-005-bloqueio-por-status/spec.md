# PLT-005 · Bloqueio de acesso por status da assinatura

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-24; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-004
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

Quem não pagou deve perder o acesso na hora. Esta funcionalidade faz a API do assinante respeitar o status da assinatura: com teste ou assinatura ativa, tudo funciona; suspenso ou cancelado, só leitura. A interface apenas reflete o que a API decide. O assinante também consegue consultar o próprio status.

## Histórias de usuário

- Como Gustavo, quero que a suspensão bloqueie qualquer alteração de dados imediatamente.
- Como assinante suspenso, quero ainda ver meus dados e entender por que estou bloqueado.
- Como app/web, quero consultar o status da assinatura para mostrar um aviso.

## Regras de negócio

- **RN-01** — Estados e acesso: `trialing` e `active` têm acesso completo; `suspended` e `canceled` têm **somente leitura** (GET, HEAD e OPTIONS); qualquer outro método nas rotas do assinante é recusado.
- **RN-02** — Recusa: 403 em Problem Details com `code` = `subscription_suspended` ou `subscription_canceled`. Negócio sem assinatura é tratado como suspenso.
- **RN-03** — A decisão é tomada a cada requisição, lendo o status atual (nenhum cache): suspender bloqueia na hora, regularizar libera na hora.
- **RN-04** — A regra vale para todas as rotas do grupo `tenant` que gravam dados (middleware `subscription.access`). Rotas da plataforma e públicas não são afetadas.
- **RN-05** — `GET /api/v1/subscription` (rota do assinante, sempre permitida, mesmo suspenso) devolve: `status`, `plan` (chave e nome), `cycle`, `price_cents`, `currency`, `trial_ends_at`, `current_period_end`, `next_charge_at`, `suspended_at` e `can_write`.
- **RN-06** — Cada negócio só enxerga a própria assinatura.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Assinatura `trialing` ou `active` | Requisição de escrita numa rota do assinante | Permitida |
| CA-02 | RN-01, RN-02 | Assinatura `suspended` | POST, PUT, PATCH ou DELETE | 403 `subscription_suspended` |
| CA-03 | RN-01 | Assinatura `suspended` | GET | Permitido |
| CA-04 | RN-01, RN-02 | Assinatura `canceled` | Escrita | 403 `subscription_canceled`; leitura permitida |
| CA-05 | RN-02 | Negócio sem assinatura | Escrita | 403 como suspenso |
| CA-06 | RN-03 | Suspensa e depois regularizada | Escrita antes e depois | Recusada e depois permitida, sem reiniciar nada |
| CA-07 | RN-05 | Assinatura em teste | `GET /subscription` | Status, plano, preço, fim do teste e próxima cobrança; `can_write` verdadeiro |
| CA-08 | RN-05 | Assinatura suspensa | `GET /subscription` | 200 com `suspended_at` e `can_write` falso |
| CA-09 | RN-06 | Dois negócios | `GET /subscription` em cada host | Cada um vê só o seu |
| CA-10 | RN-04 | Rotas da plataforma e públicas | Requisição com o negócio suspenso | Não afetadas |
| CA-11 | RN-05 | Host da plataforma | `GET /subscription` | 404 |

## Permissões

Sem papéis ainda (autenticação do assinante no PLT-006); a rota depende só do tenant resolvido.

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/api/v1/subscription` | Status da assinatura do negócio | 404 |

## Dados

Nenhum novo (usa `subscriptions` e `plans`).

## Eventos, auditoria e notificações

Nenhum.

## Impacto no mobile

O app lê o mesmo endpoint e o mesmo código de erro.

## Fora do escopo

Regularização e cancelamento (PLT-007), login (PLT-006), telas.

## Riscos e perguntas em aberto

- Sem login ainda, a rota `GET /subscription` é pública no subdomínio do negócio; quando o PLT-006 existir ela passa a exigir login. Os dados são só do status da assinatura.
