# PLT-008 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-03, CA-15, CA-16 | API | `Feature/Team/TeamAccessTest.php` | passando |
| T-02 | CA-02, CA-04, CA-05, CA-14 | API | `Feature/Team/InvitationTest.php` | passando |
| T-03 | CA-06, CA-07, CA-08 | API | `Feature/Team/AcceptInvitationTest.php` | passando |
| T-04 | CA-09 a CA-13 | API | `Feature/Team/MembersTest.php` | passando |
| T-05 | CA-17 | Contrato | `Feature/Team/TeamContractTest.php` | passando |

## Casos obrigatórios

Cobertos: fluxo principal, permissão por papel, limite do plano, isolamento de tenant, resposta no contrato, enumeração (convite inválido sempre igual) e limite de tentativas.
