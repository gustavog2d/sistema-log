# PLT-011 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-04, CA-05, CA-06, CA-12 | API | `Feature/Leads/LeadCaptureTest.php` | passando |
| T-02 | CA-03 | API | `Feature/Leads/CaptchaTest.php` | passando |
| T-03 | CA-07, CA-08, CA-09, CA-11 | API | `Feature/Leads/LeadAdminTest.php` | passando |
| T-04 | CA-10 | Comando | `Feature/Leads/LeadRetentionTest.php` | passando |
| T-05 | CA-13 | Contrato | `Feature/Leads/LeadContractTest.php` | passando |

## Casos obrigatórios

Cobertos: captura com origem e consentimento, validações, anti-robô (token, Google recusa, nota baixa, serviço fora), campo isca, repetição em 24 h, limite por hora, listagem com filtros e cursor, alteração e auditoria sem as notas, retenção, hash do IP fora da API, resposta no contrato.
