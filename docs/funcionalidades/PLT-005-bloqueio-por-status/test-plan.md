# PLT-005 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 a CA-06 | API | `Feature/Billing/SubscriptionAccessTest.php` | passando |
| T-02 | CA-07, CA-08, CA-09, CA-11 | API | `Feature/Billing/SubscriptionEndpointTest.php` | passando |
| T-03 | CA-10 | API | `SubscriptionAccessTest.php` (plataforma e públicas) | passando |
| T-04 | contrato | Contrato | `SubscriptionEndpointTest.php` | passando |
| T-05 | RN-04 | Registro | `SubscriptionAccessTest` (A1: toda rota não-GET do grupo `tenant` real tem `subscription.access`; e o teste que flagra uma rota esquecida) | passando |
| T-06 | contrato | Contrato | `SubscriptionEndpointTest` com validador de schema (tipos, enums, datas, obrigatórios) contra o 200, o 404 e o 403 com `code` | passando |

Teste real contra a API em execução (2026-10-22): `GET /subscription` no subdomínio de cada negócio antes e depois da cobrança (`can_write` verdadeiro e falso).

## Casos obrigatórios

- [x] Sucesso do fluxo principal; [x] suspensão e regularização sem cache; [x] isolamento de tenant; [x] resposta bate com o `openapi.yaml`
- [ ] Permissão por papel: depende do PLT-006

## Fora do plano

Regularização, cancelamento, telas.
