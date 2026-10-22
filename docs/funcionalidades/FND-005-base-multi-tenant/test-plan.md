# FND-005 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

Todos os testes de isolamento rodam em PostgreSQL real. O cenário é montado com o papel dono (`pgsql_admin`) e os testes executam com o papel de runtime (`pgsql`), que respeita o RLS. O ID do critério aparece no nome de cada teste.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | API | `Feature/Tenancy/TenantResolutionTest.php` (CA-01) | passando |
| T-02 | CA-02 | API | mesmo arquivo (CA-02) | passando |
| T-03 | CA-03 | API | mesmo arquivo (CA-03) | passando |
| T-04 | CA-04 | API | mesmo arquivo (CA-04) | passando |
| T-05 | CA-05 | Registro de rotas | `Feature/Tenancy/RouteRegistryTest.php` (positivo e negativo) | passando |
| T-06 | CA-06 | Banco (RLS) | `Feature/Tenancy/RowLevelSecurityTest.php` | passando |
| T-07 | CA-07 | Banco (RLS) | mesmo arquivo | passando |
| T-08 | CA-08 | Banco (RLS) | mesmo arquivo | passando |
| T-09 | CA-09 | Banco (RLS) | mesmo arquivo | passando |
| T-10 | CA-10 | Banco | mesmo arquivo (papel sem superusuário e sem BYPASSRLS) | passando |
| T-11 | CA-11 | API | `Feature/Tenancy/ContextCleanupTest.php` (sucesso e erro 500) | passando |
| T-12 | CA-12 | Integração | `Feature/Tenancy/BelongsToTenantTest.php` (filtro, preenchimento, tenant_id divergente) | passando |
| T-13 | CA-13 | Integração | mesmo arquivo (consulta e criação sem contexto) | passando |
| T-14 | CA-14 | Esquema | `Feature/Tenancy/SchemaRlsTest.php` (positivo, negativo com tabela sem RLS, allowlist sem nomes obsoletos) | passando |
| T-15 | CA-15 | Unidade | `Unit/SlugTest.php` (válidos, inválidos incluindo `\n`, todos os reservados) | passando |
| T-16 | CA-16 | Banco | `RowLevelSecurityTest.php` (runtime lê `tenants` e não escreve) | passando |
| T-17 | RN-02 | API/Banco | `TenantResolutionTest.php` (reservado não resolve; CHECK do formato no banco) | passando |
| T-18 | RN-03 | API | `TenantResolutionTest.php` (maiúsculas, hosts aninhados e externos, contrato OpenAPI) | passando |
| T-19 | RN-05 | API | `TenantResolutionTest.php` (tenant suspenso continua resolvendo) | passando |
| T-20 | RN-06 | Esquema | `SchemaRlsTest.php` (índice em `tenant_id` pelo helper) | passando |

## Casos obrigatórios

- [x] Sucesso do fluxo principal
- [x] **Isolamento de tenant** (núcleo desta funcionalidade, em banco e em API)
- [x] Resposta bate com o `openapi.yaml`
- [ ] Permissão, plano, limite, idempotência, concorrência, fuso, auditoria, acessibilidade: não se aplicam ainda

## Dados de teste

Tenants `acme` e `beta`. Tabela de sonda `probe_items` criada e removida pelos próprios testes com o helper de produção.

## Fora do plano (registrado na revisão de segurança)

Resolução por token; PgBouncer; carga; jobs de fila sem contexto (pré-requisito do primeiro job); rate limit; `trustProxies` e `trustHosts`.
