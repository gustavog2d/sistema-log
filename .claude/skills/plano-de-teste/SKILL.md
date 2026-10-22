---
name: plano-de-teste
description: Escreve o test-plan.md e o esqueleto dos testes de uma funcionalidade do sistema-log.
---

# plano-de-teste

> Rascunho inicial. Refinar por PR.

1. Use `docs/funcionalidades/_templates/test-plan.md`.
2. Monte a matriz: cada CA-xx → caso T-xx → camada → arquivo de teste.
3. Marque os casos obrigatórios que se aplicam (permissão, plano, isolamento de tenant, suspensão, idempotência, concorrência, fuso).
4. Nomeie os testes com o ID do critério (`it('CA-03: ...')`) para rastreabilidade.
5. Escreva os testes antes do código; confirme que falham pelo motivo certo.
