# PLT-002 · Catálogo de planos e funcionalidades (entitlements)

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** — (equipe da plataforma)
- **Depende de:** PLT-001
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

O produto tem três planos (Essencial, Profissional e Estúdio), teste de 7 dias, ciclos mensal e anual com desconto no anual, e cada funcionalidade pode ser liberada, limitada ou oculta conforme o plano. As regras de cada plano e os preços **ainda não estão definidos**. Esta funcionalidade entrega o catálogo configurável (planos, funcionalidades e a relação entre eles) e o serviço que calcula o que cada plano permite, para que as regras entrem depois como dados, sem mudar código.

## Histórias de usuário

- Como super admin, quero criar e ajustar planos, preços e desconto anual, para definir a oferta sem depender de desenvolvimento.
- Como super admin, quero dizer, por plano, se uma funcionalidade está liberada, limitada ou oculta.
- Como visitante, quero ver os planos ativos e seus preços na página de cadastro.
- Como desenvolvedor, quero um serviço único que responda "o plano X permite a funcionalidade Y, com qual limite, visível ou oculta".

## Regras de negócio

- **RN-01** — `plans` (tabela de plataforma): `key` único (ex.: `essencial`), `name`, `position`, `status` (`draft`, `active`, `archived`), `trial_days` (padrão 7), `monthly_price_cents` (nulo até definir), `yearly_discount_percent` (0 a 100, nulo até definir), `currency` (padrão `BRL`). Dinheiro em centavos, inteiro.
- **RN-02** — O preço anual é calculado: `round(mensal × 12 × (100 − desconto) / 100)` centavos. Sem mensalidade ou sem desconto definidos, o preço anual não existe.
- **RN-03** — Um plano só fica `active` se tiver `monthly_price_cents` e `yearly_discount_percent` definidos. Plano `archived` é definitivo: não volta a `draft` nem a `active`. A `key` do plano e da funcionalidade não muda depois de criada. O banco também recusa plano ativo sem preço.
- **RN-04** — Entram como dados iniciais os três planos em `draft`, sem preço e sem regras: Essencial, Profissional e Estúdio.
- **RN-05** — `features` (catálogo): `key` único (ex.: `agenda.publica`), `name`, `description`, `kind` (`toggle` ou `limit`), `default_enabled`, `default_limit` (nulo = ilimitado), `default_visible`. Dados iniciais: as chaves previstas em `docs/05` (agenda pública, integração com Google Calendar, orçamento, relatórios com exportação, máximo de usuários), com padrão **liberado, ilimitado e visível**, para o sistema funcionar enquanto as regras não existem.
- **RN-06** — `plan_features` guarda a regra de um plano para uma funcionalidade: `enabled`, `limit` (nulo = ilimitado) e `visible`. **Sem linha, vale o padrão da funcionalidade.**
- **RN-07** — Resultado para cada funcionalidade de um plano (`Entitlement`): `enabled` (bool), `limit` (inteiro ou nulo) e `visible`. Estados: **liberada** (`enabled`), **limitada** (`enabled` com `limit`), **bloqueada visível** (não `enabled` e `visible`, a interface mostra e oferece upgrade) e **oculta** (não `enabled` e não `visible`, a interface não mostra). Funcionalidade `toggle` não aceita `limit`; ao virar `toggle`, os limites já gravados nas regras são zerados. Uma funcionalidade liberada sempre sai como visível, mesmo que a regra guardada diga o contrário.
- **RN-08** — Apenas `super_admin` altera planos, funcionalidades e regras; `suporte` só lê. A listagem pública mostra só planos `active`, com preços e funcionalidades visíveis, e não expõe campos internos.
- **RN-09** — Toda alteração gera registro de auditoria (`platform_audit_logs`) com o antes e o depois dos campos de negócio.
- **RN-10** — Exceções por assinante (cortesia, add-on, piloto) e a ligação de um assinante a um plano entram com a assinatura (PLT-003/004/009); o serviço já aceita um resultado de exceção para sobrepor o do plano.
- **RN-11** — Nenhuma regra de plano fica no código: nada de `if plano == 'x'`.
- **RN-12** — Valores numéricos têm teto compatível com o banco (preço até R$ 1.000.000,00; limites até 1.000.000; posição até 32767), para nunca virar erro 500. Erro inesperado sai como Problem Details sem detalhes fora do modo de depuração.
- **RN-13** — A rota pública tem limite de 60 requisições por minuto por IP e `Cache-Control: public, max-age=60`. Alterações simultâneas no mesmo plano ou regra são serializadas (linha travada e *upsert*).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-04 | Banco migrado e populado | Listar planos (super admin) | Existem Essencial, Profissional e Estúdio em `draft`, sem preço |
| CA-02 | RN-02 | Plano com mensal R$ 100,00 e desconto 20% | Ler o plano | `yearly_price_cents` = 96000 |
| CA-03 | RN-02 | Plano sem preço ou sem desconto | Ler o plano | `yearly_price_cents` é nulo |
| CA-04 | RN-03 | Plano sem preço | Ativar | 422 Problem Details |
| CA-05 | RN-03 | Plano com preço e desconto | Ativar | `status` = `active` |
| CA-06 | RN-05, RN-06 | Funcionalidade sem regra no plano | Calcular entitlement | Usa o padrão da funcionalidade |
| CA-07 | RN-06, RN-07 | Regra: liberada com limite 5 | Calcular | `enabled` verdadeiro, `limit` 5 |
| CA-08 | RN-07 | Regra: bloqueada e visível | Calcular | `enabled` falso, `visible` verdadeiro |
| CA-09 | RN-07 | Regra: bloqueada e oculta | Calcular | `enabled` falso, `visible` falso |
| CA-10 | RN-07 | Funcionalidade `toggle` com `limit` | Salvar regra | 422 |
| CA-11 | RN-08 | Usuário `suporte` | Tentar alterar plano, funcionalidade ou regra | 403 Problem Details; leitura permitida |
| CA-12 | RN-08 | Visitante sem login | Listar planos públicos | Só planos `active`, sem campos internos; planos `draft` ausentes |
| CA-13 | RN-09 | Alteração de plano | Consultar auditoria | Registro com antes e depois |
| CA-14 | RN-01 | Dados inválidos (desconto 101, preço negativo, chave repetida) | Salvar | 422 |
| CA-15 | RN-10 | Exceção por assinante informada ao serviço | Calcular | A exceção prevalece sobre o plano |
| CA-16 | RN-08 | Sem token | Qualquer rota de gestão | 401 |

## Permissões

| Ação | Super admin | Suporte | Visitante |
| --- | --- | --- | --- |
| Ver planos, funcionalidades, regras e entitlements | Sim | Sim | Só planos ativos (público) |
| Criar e alterar planos, funcionalidades e regras | Sim | Não | Não |

## API

Contrato completo em `openapi.yaml`.

| Método | Rota | Descrição |
| --- | --- | --- |
| GET | `/api/v1/plans` | Planos ativos (público) |
| GET | `/api/v1/platform/plans` | Todos os planos |
| POST | `/api/v1/platform/plans` | Cria plano |
| GET | `/api/v1/platform/plans/{plan}` | Detalhe com preços |
| PATCH | `/api/v1/platform/plans/{plan}` | Altera plano |
| GET | `/api/v1/platform/plans/{plan}/entitlements` | Resultado calculado por funcionalidade |
| PUT | `/api/v1/platform/plans/{plan}/features/{feature}` | Define a regra do plano para a funcionalidade |
| DELETE | `/api/v1/platform/plans/{plan}/features/{feature}` | Volta ao padrão da funcionalidade |
| GET | `/api/v1/platform/features` | Catálogo de funcionalidades |
| POST | `/api/v1/platform/features` | Cria funcionalidade |
| PATCH | `/api/v1/platform/features/{feature}` | Altera funcionalidade |

## Dados

- Tabelas de plataforma novas: `plans`, `features`, `plan_features` (sem `tenant_id`, sem RLS; entram na lista de tabelas de plataforma, de propósito). O papel de runtime escreve nelas porque a gestão acontece pela API autenticada da plataforma.
- Dados iniciais por seeder idempotente (planos e funcionalidades da RN-04 e RN-05).
- Migration compatível com a versão anterior: sim (só adições).

## Eventos, auditoria e notificações

Auditoria (RN-09): `plan.created`, `plan.updated`, `feature.created`, `feature.updated`, `plan_feature.set`, `plan_feature.reset`.

## Impacto no mobile

O app lerá os entitlements do assinante (`GET /me/entitlements`, no PLT-003/004) usando o mesmo cálculo; nenhuma quebra de contrato.

## Fora do escopo

- Ligar um assinante a um plano, assinatura, cobrança e cupons (PLT-003, PLT-004, PLT-012).
- `GET /me/entitlements` e middleware `feature:` nas rotas de negócio (entram com a assinatura, quando existir plano por tenant).
- Exceções persistidas por assinante (PLT-009).
- Interface de gestão (PLT-001b e seguintes).

## Riscos e perguntas em aberto

- Preços, desconto anual e regras por plano seguem indefinidos; o catálogo nasce sem eles de propósito.
- Padrão "liberado e ilimitado" das funcionalidades enquanto não há regra: revisar quando o produto definir os planos.
