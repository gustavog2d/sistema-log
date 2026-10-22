# <ID> · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: )
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 | Unidade / API / E2E | `apps/api/tests/...` | a escrever / falhando / passando |

## Casos obrigatórios (marque os que se aplicam)

- [ ] Sucesso do fluxo principal
- [ ] Validação de cada campo obrigatório e limite
- [ ] Permissão: cada papel sem acesso recebe 403
- [ ] Plano: assinante sem a funcionalidade recebe 403 `feature_not_in_plan`
- [ ] Limite do plano atingido
- [ ] **Isolamento de tenant:** usuário do tenant A não lê, altera nem lista dado do tenant B
- [ ] Assinatura suspensa: escrita bloqueada, leitura permitida
- [ ] Idempotência (quando há `Idempotency-Key`)
- [ ] Concorrência (ex.: dois agendamentos no mesmo horário)
- [ ] Fuso horário e virada de dia/mês
- [ ] Auditoria registrada com antes e depois
- [ ] Resposta bate com o `openapi.yaml`
- [ ] Acessibilidade básica das telas

## Dados de teste

<Factories/seeds necessários.>

## Fora do plano

<O que não será testado e por quê.>
