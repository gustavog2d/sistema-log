# 09 · Padrão de qualidade por funcionalidade

Cada funcionalidade só entra em desenvolvimento com três documentos aprovados (especificação, documentação de uso e plano de teste) e só é dada como pronta quando os testes do plano rodam verdes no CI. Os documentos ficam no repositório, versionados junto com o código.

```text
docs/
  glossario.md
  arquitetura/
    adr/0001-stack.md, 0002-multi-tenant.md, ...
  funcionalidades/
    _templates/        # copie daqui para começar uma funcionalidade
    PLT-003-assinatura/
      spec.md          # o que e por quê, regras, critérios de aceite, API
      test-plan.md     # casos de teste e em qual camada cada um roda
      docs.md          # como usar (vira ajuda no produto)
      openapi.yaml     # trecho do contrato desta funcionalidade
  api/openapi.yaml     # contrato completo, montado no CI
```

Os templates estão em [funcionalidades/_templates/](funcionalidades/_templates/).

## Camadas de teste

| Camada | Ferramenta | O que cobre | Quando roda |
| --- | --- | --- | --- |
| Unidade | Pest, Vitest | Regras de negócio puras: disponibilidade, cálculo de receita, entitlements | Todo commit |
| Integração / API | Pest (feature tests) | Cada endpoint: sucesso, validação, permissão por papel, plano sem a funcionalidade | Todo PR |
| Isolamento de tenant | Pest, suíte própria | Usuário do tenant A nunca lê nem altera dado do tenant B, em toda rota | Todo PR, bloqueante |
| Contrato | Validação contra OpenAPI | Respostas reais batem com o contrato usado pela web e pelo app | Todo PR |
| Componentes | Vitest + Testing Library | Formulários, estados de erro, acessibilidade básica | Todo PR |
| Ponta a ponta | Playwright | Fluxos críticos: cadastro → pagamento sandbox → subdomínio → login; agendar; orçamento → agendamento | PR e homologação |
| Carga | k6 | Agenda pública e disponibilidade com muitos tenants | Antes de cada marco |
| Segurança | Semgrep, audits, OWASP ZAP | Vulnerabilidades no código, dependências e na aplicação rodando | PR e homologação |

## Definição de pronto

- [ ] Cada critério de aceite tem ao menos um teste automatizado, rastreável pelo ID da regra.
- [ ] Cobertura das regras de negócio (camada de domínio) de no mínimo 90%; total do projeto não cai.
- [ ] Suíte de isolamento de tenant cobre as rotas novas.
- [ ] OpenAPI atualizado e cliente TypeScript regenerado sem diferença pendente.
- [ ] `docs.md` escrito e changelog atualizado.
- [ ] Revisão de código e de segurança aprovadas; Gustavo aprova o merge.
