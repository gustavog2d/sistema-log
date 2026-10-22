# Bloqueio de acesso por status da assinatura

> Para quem desenvolve e opera a plataforma.

## Para que serve

Faz a API do assinante respeitar o status da assinatura: em teste ou ativa, tudo funciona; suspensa ou cancelada, só leitura. A interface apenas reflete o que a API decide.

## Como funciona

- Rotas do assinante que gravam dados usam o middleware `subscription.access`, depois de `tenant`: `Route::middleware(['tenant', 'subscription.access'])`.
- `GET`, `HEAD` e `OPTIONS` sempre passam. Qualquer outro método com a assinatura suspensa ou cancelada recebe **403** em Problem Details com `code`: `subscription_suspended` ou `subscription_canceled`. Negócio sem assinatura conta como suspenso.
- A decisão lê o status atual a cada requisição (sem cache): suspender bloqueia na hora, regularizar libera na hora.
- `GET /api/v1/subscription` (no subdomínio do negócio) mostra status, plano, ciclo, preço, fim do teste, próxima cobrança, `suspended_at` e `can_write`. É permitido mesmo suspenso, para a interface explicar o bloqueio.
- Rotas da plataforma e públicas não são afetadas.

## Limitações

- Sem login ainda (PLT-006): `GET /subscription` responde só pelo subdomínio; depois do login passa a exigir autenticação.
- A regularização (novo meio de pagamento) e o cancelamento são do PLT-007.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-26 | Middleware `subscription.access` e `GET /subscription` |
