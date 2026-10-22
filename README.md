# sistema-log

> **Nome provisório.** "sistema-log" é o nome de trabalho e pode mudar, assim como os nomes de planos, módulos, perfis e termos de tela. Veja [docs/glossario.md](docs/glossario.md).

SaaS de agenda, orçamento e financeiro para profissionais que atendem com hora marcada: tatuadores, trancistas, micropigmentadoras, cabeleireiras, manicures e outros. É a evolução do Tatulog (só para tatuadores) para uma plataforma multi-nicho, API-first, multi-tenant e com assinatura recorrente. A primeira versão é web; apps iOS e Android vêm depois, sobre a mesma API.

## Primeiro passo no VS Code

No terminal do VS Code, na raiz do projeto, rode:

```bash
bash setup/instalar.sh
```

Isso cria `.claude/` (rascunhos dos agentes e skills) e `.vscode/` (extensões recomendadas). Depois pode apagar a pasta `setup/`.

## Estado atual

**Fase de planejamento.** Ainda não há código. O repositório contém só a documentação, os templates e os rascunhos de agentes e skills. A próxima etapa é a Fase 0 (Fundação): fechar as [decisões em aberto](docs/14-decisoes-em-aberto.md) como ADRs e montar monorepo, CI e homologação.

**Regra de ouro:** nenhuma linha de código antes de a funcionalidade ter especificação, documentação e plano de teste aprovados.

## Mapa da documentação

| #   | Documento                                                           | Conteúdo                                           |
| --- | ------------------------------------------------------------------- | -------------------------------------------------- |
| 00  | [Visão e premissas](docs/00-visao-e-premissas.md)                   | O que é o produto, premissas, nomes provisórios    |
| 01  | [Público e nichos](docs/01-publico-e-nichos.md)                     | Nichos núcleo, adjacentes e futuros                |
| 02  | [Herança do Tatulog](docs/02-heranca-tatulog.md)                    | O que levar e o que corrigir                       |
| 03  | [Arquitetura](docs/03-arquitetura.md)                               | API-first, multi-tenant, subdomínios, web e apps   |
| 04  | [Stack](docs/04-stack.md)                                           | Linguagens, frameworks e ferramentas               |
| 05  | [Planos e assinaturas](docs/05-planos-e-assinaturas.md)             | Entitlements, ciclo de vida, cobrança recorrente   |
| 06  | [Perfis e permissões](docs/06-perfis-e-permissoes.md)               | Super admin, dono, gerente, profissional, recepção |
| 07  | [Modelo de domínio](docs/07-modelo-de-dominio.md)                   | Entidades e regras centrais                        |
| 08  | [Infraestrutura e segurança](docs/08-infraestrutura-e-seguranca.md) | Docker no começo, AWS como alvo, ambientes, LGPD   |
| 09  | [Padrão de qualidade](docs/09-padrao-de-qualidade.md)               | Spec, documentação e testes por funcionalidade     |
| 10  | [Fluxo de agentes](docs/10-fluxo-de-agentes.md)                     | Papéis e aprovações                                |
| 11  | [Skills](docs/11-skills.md)                                         | Skills que padronizam cada etapa                   |
| 12  | [Roadmap](docs/12-roadmap.md)                                       | Fases 0 a 5 e critérios de avanço                  |
| 13  | [Backlog inicial](docs/13-backlog.md)                               | Itens das Fases 0 e 1                              |
| 14  | [Decisões em aberto](docs/14-decisoes-em-aberto.md)                 | O que precisa virar ADR                            |

Outros:

- [Glossário](docs/glossario.md)
- [ADRs](docs/arquitetura/adr/)
- [Templates de funcionalidade](docs/funcionalidades/_templates/)
- [Especificação funcional do Tatulog](docs/referencias/tatulog-especificacao-funcional.md) (sistema atual, para referência e migração)
- Agentes e skills do Claude Code em [.claude/](.claude/) (rascunhos)

## Estrutura prevista do repositório

```text
apps/
  api/        # Laravel (Fase 0+)
  web/        # Next.js (Fase 1+)
  mobile/     # React Native + Expo (Fase 5)
packages/
  api-client/ # cliente TypeScript gerado do OpenAPI
  ui-tokens/  # tokens de design compartilhados
docs/         # esta documentação
.claude/      # agentes e skills
```

## Como visualizar os diagramas

Os diagramas estão em Mermaid. No VS Code, instale a extensão **Markdown Preview Mermaid Support** (`bierner.markdown-mermaid`) e abra o preview do Markdown (`Cmd+Shift+V`).

## Origem

Gerado a partir dos documentos de planejamento de 20/10/2026:

- [sistema-log — Planejamento do Projeto](https://claude.ai/artifact/LpPjHeNPC9Sr7BJYhfjBCZ)
- [TattooLog — Especificação Funcional para Reconstrução](https://claude.ai/artifact/3zGE9jcZxWepN4KNYsUjPr)

A partir de agora, a fonte de verdade é este repositório.
