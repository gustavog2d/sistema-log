# PLT-001b · Plano de teste

- **Spec:** [spec.md](spec.md) (aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02 | Componente | `components/admin/admin-login.test.tsx` | passando |
| T-02 | CA-03, CA-15 | Servidor e unidade | `server/admin-session.test.ts`, `lib/safe-path.test.ts`, `components/admin/list-utils.test.ts` | passando |
| T-03 | CA-04, CA-14 | Componente | `components/admin/admin-frame.test.tsx` | passando |
| T-04 | CA-05, CA-06 | Componente | `components/admin/tenants-view.test.tsx`, `tenant-detail.test.tsx`, `dashboard-view.test.tsx` | passando |
| T-05 | CA-07, CA-08, CA-09 | Componente | `components/admin/tenant-detail.test.tsx` | passando |
| T-06 | CA-10 | Componente | `components/admin/coupons-view.test.tsx` | passando |
| T-07 | CA-11 | Componente | `components/admin/plans-view.test.tsx` | passando |
| T-08 | CA-12 | Componente | `components/admin/leads-view.test.tsx` | passando |
| T-09 | CA-13, CA-14 | Varredura | axe nos testes acima; `no-hardcoded-text.test.ts` (inclui `admin/`) | passando |
