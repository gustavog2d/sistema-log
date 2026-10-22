# 11 · Skills do projeto

As skills transformam o padrão de qualidade em passos repetíveis: cada agente carrega a skill da sua etapa e produz sempre o mesmo formato. Ficam em `.claude/skills/<nome>/SKILL.md`, com templates e exemplos ao lado, e mudam por PR como qualquer código.

| Skill | Usada por | O que padroniza |
| --- | --- | --- |
| `escrever-spec` | Produto | Template da spec, numeração de regras (RN-xx), critérios Dado/Quando/Então, checklist de escopo |
| `contrato-api` | Arquiteto | Convenções REST: nomes, paginação por cursor, erros RFC 9457, idempotência, versionamento; atualizar o OpenAPI |
| `modelo-de-dados` | Arquiteto, Dev back-end | Migration com `tenant_id`, política RLS, índices, compatibilidade expandir/contrair |
| `adr` | Arquiteto | Registro de decisão: contexto, opções, decisão, consequências |
| `plano-de-teste` | QA | Matriz critério × camada de teste, casos obrigatórios (permissão, plano, isolamento de tenant) |
| `endpoint-laravel` | Dev back-end | Estrutura do módulo: rota, FormRequest, Policy, Action, Resource, evento, auditoria e testes |
| `tela-web` | Dev front-end, Designer | Página com cliente gerado, estados de carregando/vazio/erro, formulário com validação compartilhada, i18n |
| `revisao-seguranca` | Segurança | Checklist OWASP, isolamento de tenant, LGPD, segredos, rate limiting |
| `revisao-codigo` | Revisor | Aderência à spec, legibilidade, cobertura, sem regra de negócio no controller |
| `documentar-funcionalidade` | Documentação | `docs.md` no tom do produto, changelog, termos do glossário |
| `release` | DevOps | Checklist de deploy, migrations, flags, rollback, comunicado |

A primeira skill a refinar é `escrever-spec`, porque ela produz a entrada de todas as outras. As skills em `.claude/skills/` são rascunhos iniciais; `endpoint-laravel` e `tela-web` só ganham exemplos de código depois de FND-002.
