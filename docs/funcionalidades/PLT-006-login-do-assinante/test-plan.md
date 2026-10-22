# PLT-006 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

PostgreSQL real (`app_test`), papéis de produção (`app_runtime`, `app_auth`). O ID do critério aparece no nome de cada teste. Testes de e-mail com `Mail::fake()`.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-03, CA-19 | API | `Feature/TenantAuth/LoginTest.php` | escrito |
| T-02 | CA-04, CA-05, CA-06, CA-07, CA-08, CA-21 | API | `Feature/TenantAuth/TokenTest.php` | escrito |
| T-03 | CA-09, CA-20 | API | `Feature/TenantAuth/LoginTest.php` | escrito |
| T-04 | CA-10, CA-11, CA-13 | API | `Feature/TenantAuth/PasswordResetTest.php` | escrito |
| T-05 | CA-12, CA-13 | API | `Feature/TenantAuth/PasswordChangeTest.php` | escrito |
| T-06 | CA-14, CA-15 | API | `Feature/TenantAuth/TwoFactorTest.php` | escrito |
| T-07 | CA-16, CA-17, CA-18 | Banco | `Feature/TenantAuth/AuthSecurityTest.php` | escrito |
| T-08 | CA-22 | Contrato | `Feature/TenantAuth/AuthContractTest.php` | escrito |
| T-09 | isolamento | Esquema | `SchemaRlsTest` (`user_access_tokens` com RLS) | escrito |

## Casos obrigatórios

- [x] Sucesso; [x] validação; [x] **isolamento de tenant** (token de um negócio não vale em outro; RLS); [x] auditoria de acesso: eventos de segurança (bloqueio) via contadores; [x] idempotência/uso único dos tokens de redefinição e desafio; [x] resposta bate com o `openapi.yaml`
- [ ] Permissão por papel e plano: dependem de cada funcionalidade

## Fora do plano

Convite de usuários, e-mails com tema, impersonação, telas.
