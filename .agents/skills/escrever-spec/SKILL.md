---
name: escrever-spec
description: Escreve a especificação de uma funcionalidade do sistema-log a partir do backlog. Use ao criar ou revisar um spec.md.
---

# escrever-spec

> Rascunho inicial. Refinar por PR.

1. Copie `docs/funcionalidades/_templates/` para `docs/funcionalidades/<ID>-<slug>/`.
2. Leia o item em `docs/13-backlog.md` e os documentos de domínio, permissões e planos.
3. Escreva o problema em até 3 frases e as histórias de usuário por papel.
4. Liste regras como **RN-01, RN-02...**: uma regra por linha, testável, sem ambiguidade (números, limites, prazos explícitos).
5. Para cada regra, um ou mais critérios **CA-xx** no formato Dado / Quando / Então.
6. Preencha a matriz de permissões por papel e o plano/feature flag.
7. Liste o que está fora do escopo e as perguntas em aberto.

Checklist antes de pedir revisão:

- [ ] Toda regra tem critério de aceite
- [ ] Casos de erro e limites descritos
- [ ] Comportamento com assinatura suspensa e plano sem a funcionalidade
- [ ] Impacto no mobile considerado
- [ ] Termos novos no glossário
