# PLT-013 · Plano de teste

- **Spec:** [spec.md](spec.md) (aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-12, CA-18 | Feature | `tests/Feature/Billing/PlanChangeAccessTest.php` | passando |
| T-02 | CA-03, CA-04, CA-05, CA-13, CA-16, CA-17 | Feature | `tests/Feature/Billing/PlanChangeImmediateTest.php` | passando |
| T-03 | CA-06, CA-07, CA-11, CA-14, CA-15 | Feature | `tests/Feature/Billing/PlanChangeScheduledTest.php` | passando |
| T-04 | CA-08, CA-09, CA-10 | Feature | `tests/Feature/Billing/PlanChangeRenewalTest.php` | passando |
| T-05 | CA-19 | Contrato | `tests/Feature/Billing/PlanChangeContractTest.php` | passando |
| T-06 | CA-01..CA-14 (web) | Componente | `apps/web/src/components/panel/plan-change.test.tsx` | passando |
| T-07 | CA-02..CA-18 (bordas) | Feature | `tests/Feature/Billing/PlanChangeEdgeTest.php` | passando |
