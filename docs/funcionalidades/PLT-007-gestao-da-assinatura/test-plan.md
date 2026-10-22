# PLT-007 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-16 | API | `Feature/Billing/ManagementAccessTest.php` | passando |
| T-02 | CA-02 a CA-07 | API | `Feature/Billing/PaymentMethodTest.php` | passando |
| T-03 | CA-08 a CA-13 | API | `Feature/Billing/CancellationTest.php` | passando |
| T-04 | CA-14, CA-15 | API | `Feature/Billing/InvoicesTest.php` | passando |
| T-05 | CA-17 | Contrato | `Feature/Billing/ManagementContractTest.php` | passando |

## Casos obrigatórios

- [x] Sucesso do fluxo principal; [ ] regularização e falha sem trocar o meio antigo; [ ] isolamento de tenant; [ ] resposta bate com o `openapi.yaml`; [ ] permissão por papel

Simultaneidade (duas regularizações ou cancelamentos ao mesmo tempo): não há teste automatizado, porque exigiria processos paralelos; a garantia é a linha da assinatura travada com `FOR UPDATE` em toda ação, mais a chave de idempotência.
