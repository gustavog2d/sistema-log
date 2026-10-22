# FND-002 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | Script de verificação | `scripts/verify-structure.sh` | passando |
| T-02 | CA-02 | Script de verificação | `scripts/verify-compose.sh` (healthchecks) | passando |
| T-03 | CA-03 | API (feature) | `apps/api/tests/Feature/HealthTest.php` | passando |
| T-04 | CA-03 | API (feature) | mesmo arquivo: banco ou Redis fora do ar devolve 503 Problem Details | passando |
| T-05 | CA-04 | Componente (Vitest) | `apps/web/src/components/home.test.tsx` | passando |
| T-06 | CA-05 | Script de verificação | lint e análise estática terminam com código 0 | passando |
| T-07 | CA-06 | API / Web | suítes Pest e Vitest passam; Pest conectado ao PostgreSQL | passando |
| T-08 | CA-07 | Documental | comando de teste único documentado e executado uma vez | passando |
| T-09 | CA-08 | Script de verificação | busca pelo nome provisório fora de config e docs | passando |
| T-10 | CA-09 | Script de verificação | `.claude/` com 10 agentes e 11 skills; sem `setup/` | passando |
| T-11 | CA-10 | Documental | cada comando do `CLAUDE.md` executado (ver verify-*.sh); ensaiado em clone limpo em 2026-10-22 | passando |
| T-12 | CA-11 | Script de verificação | nenhum `.env` rastreado | passando |
| T-13 | RN-06 | Contrato | resposta de `/health` bate com `openapi.yaml` | passando |

## Casos obrigatórios (marque os que se aplicam)

- [x] Sucesso do fluxo principal (ambiente sobe, health responde, web renderiza)
- [ ] Validação de campos (sem entrada de usuário)
- [ ] Permissão por papel (sem papéis ainda)
- [ ] Plano (sem planos ainda)
- [ ] Limite do plano
- [ ] Isolamento de tenant (nasce em FND-005)
- [ ] Assinatura suspensa
- [ ] Idempotência
- [ ] Concorrência
- [ ] Fuso horário
- [ ] Auditoria
- [x] Resposta bate com o `openapi.yaml`
- [ ] Acessibilidade básica das telas (página inicial mínima; cobrir no FND-006)

## Dados de teste

Nenhum. O PostgreSQL de teste sobe vazio e é migrado a cada execução.

## Fora do plano

- Execução no CI (FND-003).
- Desempenho e carga.
- Segurança além de "sem segredo versionado" (varreduras entram no CI).
