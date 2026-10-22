# PLT-002 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

Testes de API e de unidade em Pest, PostgreSQL real. O ID do critério aparece no nome de cada teste.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | API/Seeder | `Feature/Plans/CatalogSeedTest.php` | passando |
| T-02 | CA-02, CA-03 | Unidade | `Unit/YearlyPriceTest.php` | passando |
| T-03 | CA-04, CA-05 | API | `Feature/Plans/PlanManagementTest.php` | passando |
| T-04 | CA-06 a CA-09, CA-15 | Unidade/Integração | `Feature/Plans/EntitlementResolverTest.php` | passando |
| T-05 | CA-10, CA-14 | API | `Feature/Plans/PlanFeatureRuleTest.php`, `PlanManagementTest.php` | passando |
| T-06 | CA-11, CA-16 | API | `Feature/Plans/PlanPermissionsTest.php` | passando |
| T-07 | CA-12 | API | `Feature/Plans/PublicPlansTest.php` | passando |
| T-08 | CA-13 | API/Banco | `Feature/Plans/PlanAuditTest.php` | passando |
| T-09 | contrato | Contrato | `Feature/Plans/PlansContractTest.php` | passando |
| T-10 | isolamento | Esquema | `SchemaRlsTest` com as novas tabelas de plataforma | passando |
| T-11 | RN-11 | Varredura | `Feature/Plans/NoPlanRulesInCodeTest.php` (nenhuma comparação com chave de plano no código) | passando |

## Casos obrigatórios

- [x] Sucesso do fluxo principal
- [x] Validação de cada campo e limite
- [x] Permissão: `suporte` sem escrita; visitante só no público; sem token 401
- [x] Resposta bate com o `openapi.yaml`
- [x] Auditoria com antes e depois
- [ ] Plano do assinante, limite do plano atingido, isolamento de tenant: dependem de assinatura (PLT-003/004)

## Dados de teste

Seeder do catálogo; fábricas de plano e de funcionalidade; usuários `super_admin` e `suporte` com 2FA.

## Fora do plano

Interface; exceções por assinante persistidas; middleware `feature:`.
