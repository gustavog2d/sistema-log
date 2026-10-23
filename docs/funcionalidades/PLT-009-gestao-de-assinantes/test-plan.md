# PLT-009 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-12 | API | `Feature/Admin/TenantListTest.php` | passando |
| T-02 | CA-03, CA-04 | API | `Feature/Admin/TenantEntitlementTest.php` | passando |
| T-03 | CA-05 a CA-08, CA-13 | API | `Feature/Admin/TenantActionsTest.php` | passando |
| T-04 | CA-09, CA-10, CA-11 | API | `Feature/Admin/ImpersonationTest.php` | passando |
| T-05 | CA-14 | Contrato | `Feature/Admin/TenantAdminContractTest.php` | passando |

## Casos obrigatórios

Cobertos: lista e detalhe sem segredos, busca tratada como texto, exceção que vale no limite de usuários, suspensão e reativação, extensão do teste, acesso de suporte (token marcado, limites de rotas, aviso e auditoria, queda junto com o acesso da dona), permissão por papel da equipe, 404 e 401, resposta no contrato.
