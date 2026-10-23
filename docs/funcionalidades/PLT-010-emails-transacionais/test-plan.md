# PLT-010 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-08, CA-09 | API | `Feature/Notifications/EventEmailsTest.php` | passando |
| T-02 | CA-02, CA-03 | Comando | `Feature/Notifications/TrialEndingTest.php` | passando |
| T-03 | CA-04, CA-05, CA-06, CA-07, CA-12 | API | `Feature/Notifications/BillingEmailsTest.php` | passando |
| T-04 | CA-10, CA-13, CA-14 | API | `Feature/Notifications/NotifierSafetyTest.php` | passando |
| T-05 | CA-11 | Conteúdo | `Feature/Notifications/ContentTest.php` | passando |

## Casos obrigatórios

Cobertos: um envio por acontecimento (repetição não reenvia), sem envio indevido (teste longe do fim, cancelamento pendente, cortesia), falha da fila sem desfazer a ação, conteúdo sem segredo e com o nome do produto da configuração, log sem e-mail.
