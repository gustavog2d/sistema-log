# PLT-015 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-23)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02 | Componente | `components/panel/login-form.test.tsx` | passando |
| T-02 | CA-03, CA-17 | Servidor | `server/session.test.ts` | passando |
| T-03 | CA-04, CA-05 | Componente | `components/panel/panel-frame.test.tsx` | passando |
| T-04 | CA-06, CA-07, CA-08 | Componente | `components/panel/subscription-view.test.tsx` | passando |
| T-05 | CA-09, CA-10 | Componente | `components/panel/team-view.test.tsx` | passando |
| T-06 | CA-11 | Componente | `components/panel/account-view.test.tsx` | passando |
| T-07 | CA-12, CA-13 | Componente | `components/panel/recovery.test.tsx` | passando |
| T-08 | CA-14, CA-18 | Componente e servidor | `components/panel/support-entry.test.tsx`, `server/bff.test.ts` | passando |
| T-09 | CA-15, CA-16 | Acessibilidade e varredura | axe nos testes acima; `no-hardcoded-text.test.ts` (inclui `panel/`) | passando |
