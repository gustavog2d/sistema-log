# CLI-001 · Plano de teste

- **Spec:** [spec.md](spec.md) (aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02 | Feature | `tests/Feature/Clients/ClientCrudTest.php` | passando |
| T-02 | CA-03, CA-08 | Feature | `tests/Feature/Clients/ClientAccessTest.php` | passando |
| T-03 | CA-04 | Feature | `tests/Feature/Clients/ClientListTest.php` | passando |
| T-04 | CA-05, CA-06, CA-07 | Feature | `tests/Feature/Clients/ClientQuickTest.php` | passando |
| T-05 | CA-10 | Contrato | `tests/Feature/Clients/ClientContractTest.php` | passando |
| T-06 | CA-09 | Componente | `apps/web/src/components/panel/clients-view.test.tsx` | passando |
