# Catálogo de planos e funcionalidades

> Para quem desenvolve e opera a plataforma.

## Para que serve

Define a oferta: os planos (Essencial, Profissional e Estúdio), o preço mensal, o desconto do anual e o que cada plano libera, limita ou esconde. Tudo é dado configurável; nenhuma regra de plano fica no código.

## Modelo

- **Plano:** `key`, nome, ordem, status (`draft`, `active`, `archived`), dias de teste (7), preço mensal em centavos e percentual de desconto anual. O preço anual é calculado: `mensal × 12 × (100 − desconto) / 100`.
- **Funcionalidade:** `key` (ex.: `agenda.publica`), tipo `toggle` (liberar/bloquear) ou `limit` (com número) e os padrões (liberada, ilimitada, visível).
- **Regra do plano:** liga um plano a uma funcionalidade: `enabled`, `limit` e `visible`. Sem regra, vale o padrão da funcionalidade.

## Os quatro estados de uma funcionalidade num plano

| Estado | `enabled` | `limit` | `visible` | Na interface |
| --- | --- | --- | --- | --- |
| Liberada | sim | vazio | — | aparece e funciona |
| Limitada | sim | número | — | aparece com o limite |
| Bloqueada visível | não | — | sim | aparece bloqueada, com convite ao upgrade |
| Oculta | não | — | não | não aparece |

## Como usar (API da plataforma)

Autenticação: ver o login da equipe (PLT-001). Leitura para `super_admin` e `suporte`; escrita só para `super_admin`.

1. Defina preço e desconto: `PATCH /api/v1/platform/plans/{plano}` com `monthly_price_cents` e `yearly_discount_percent`.
2. Defina as regras: `PUT /api/v1/platform/plans/{plano}/features/{funcionalidade}` com `enabled`, `limit` e `visible`. `DELETE` volta ao padrão.
3. Confira: `GET /api/v1/platform/plans/{plano}/entitlements`.
4. Ative o plano: `PATCH` com `"status": "active"` (exige preço mensal e desconto definidos).

A página de cadastro lê os planos ativos em `GET /api/v1/plans` (público).

## No código

Use `App\Plans\EntitlementResolver::forPlan($plano, $excecoesDoAssinante)`. Ele devolve uma lista de `Entitlement` (feature, enabled, limit, visible, source). Não compare com chaves de plano: o teste `NoPlanRulesInCodeTest` falha se encontrar `'essencial'`, `'profissional'` ou `'estudio'` em `app/`.

## Dados iniciais

`CatalogSeeder` (idempotente, roda na subida do ambiente) cria os três planos em rascunho, sem preço e sem regras, e as cinco funcionalidades previstas, todas liberadas, ilimitadas e visíveis até o produto definir as regras. Não sobrescreve o que a equipe já alterou.

## Limitações

- Listagens sem paginação por cursor: o catálogo é pequeno e limitado (exceção consciente à convenção).
- A varredura `NoPlanRulesInCodeTest` só pega os três nomes de lançamento em `app/`; não pega planos criados depois nem comparação por nome. Vale revisão humana.
- Uma funcionalidade liberada sempre aparece (visível), mesmo se a regra tiver `visible: false`.
- Plano arquivado é definitivo; a chave de plano e de funcionalidade não muda depois de criada.
- Ainda não há assinante ligado a plano (PLT-003/004), nem exceções por assinante (PLT-009), nem `GET /me/entitlements`.
- Padrão "liberado e ilimitado" é provisório.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-24 | Catálogo, regras por plano, preço anual calculado, listagem pública e auditoria |
