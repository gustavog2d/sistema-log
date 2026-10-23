# FND-008 · Plano de teste

- **Spec:** [spec.md](spec.md) (aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01..CA-05, CA-08 | Feature | `tests/Feature/Audit/AuditedModelTest.php` | passando |
| T-02 | CA-06, CA-07 | Feature | `tests/Feature/Audit/AuditIsolationTest.php` | passando |
| T-03 | CA-09, CA-10, CA-12 | Feature | `tests/Feature/Audit/AuditLogApiTest.php` | passando |
| T-04 | CA-11 | Componente | `apps/web/src/components/panel/audit-view.test.tsx`, `apps/web/src/lib/audit.test.ts` | passando |
