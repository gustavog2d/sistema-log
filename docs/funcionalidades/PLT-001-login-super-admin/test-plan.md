# PLT-001 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

Testes de API em Pest com PostgreSQL real. O código TOTP dos testes é gerado com a mesma biblioteca da aplicação. O ID do critério aparece no nome de cada teste.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-02 | CA-02 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-03 | CA-03 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-04 | CA-04, CA-05 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-05 | CA-06 | API | `Feature/Platform/LoginSecurityTest.php` | passando |
| T-06 | CA-07 | API | `Feature/Platform/LoginSecurityTest.php` | passando |
| T-07 | CA-08 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-08 | CA-09 | API | `Feature/Platform/PlatformHostTest.php` | passando |
| T-09 | CA-10 | API | `Feature/Platform/AuthFlowTest.php` | passando |
| T-10 | CA-11 | Banco | `Feature/Platform/StorageTest.php` | passando |
| T-11 | CA-12 | API/Banco | `Feature/Platform/AuditTest.php` | passando |
| T-12 | CA-13 | Comando | `Feature/Platform/CreateAdminCommandTest.php` | passando |
| T-13 | CA-14 | API | `Feature/Platform/LoginSecurityTest.php` | passando |
| T-14 | RN-10 | API | `Feature/Platform/AuthFlowTest.php` (desativar revoga tokens) | passando |
| T-15 | contrato | Contrato | `Feature/Platform/ContractTest.php` | passando |
| T-17 | RN-04, RN-05 | API | `AuthFlowTest.php` (expiração de 5 min e 8 h, desafio de uso único, setup recusado com 2FA ativo, papel `suporte`) | passando |
| T-18 | CA-07 | API | `AuthFlowTest.php` (bloqueio via confirmação do 2FA) e `AuditTest.php` (`login.locked`, `recovery_code.used`) | passando |
| T-16 | isolamento | Esquema | `SchemaRlsTest` continua verde com as novas tabelas de plataforma | passando |

- Regressões encontradas em teste real com `curl` (cliente sem `Accept: application/json`): 401 e 403 em Problem Details, nunca redirect nem 500. Cobertas em `AuthFlowTest`.

## Casos obrigatórios

- [x] Sucesso do fluxo principal
- [x] Validação de campos obrigatórios e limites (e-mail, senha, código) em login, verify e confirm
- [x] Permissão: token de desafio sem acesso a rota protegida; token de acesso não executa as etapas do 2FA; token `setup` não verifica código. Usuário de assinante ainda não existe (PLT-006): o isolamento por host está em `PlatformHostTest` (CA-09)
- [x] Isolamento de tenant: rotas de plataforma respondem 404 no host de um assinante
- [x] Resposta bate com o `openapi.yaml`
- [ ] Plano, limite, idempotência, concorrência, fuso, acessibilidade: não se aplicam

## Dados de teste

Fábrica de `PlatformUser` (super admin e suporte), com e sem 2FA ativado.

## Fora do plano

Painel web; recuperação de senha; e-mails.
