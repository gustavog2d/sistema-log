# AGD-002 · Plano de teste

- **Spec:** [spec.md](spec.md) (aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-04, CA-08, CA-09 | Feature | `tests/Feature/Professionals/ProfessionalCrudTest.php` | passando |
| T-02 | CA-03 | Feature | `tests/Feature/Professionals/ProfessionalAccessTest.php` | passando |
| T-03 | CA-05, CA-06, CA-08, CA-09 | Feature | `tests/Feature/Professionals/WorkingHoursTest.php` | passando |
| T-04 | CA-07, CA-08, CA-09 | Feature | `tests/Feature/Professionals/TimeOffTest.php` | passando |
| T-05 | CA-11 | Contrato | `tests/Feature/Professionals/ProfessionalContractTest.php` | passando |
| T-06 | CA-10 | Componente | `apps/web/src/components/panel/professionals-view.test.tsx` | passando |
