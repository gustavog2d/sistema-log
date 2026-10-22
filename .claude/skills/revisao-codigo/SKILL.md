---
name: revisao-codigo
description: Checklist de revisão de código de um PR do sistema-log.
---

# revisao-codigo

> Rascunho inicial. Refinar por PR.

- [ ] CI verde
- [ ] Cada CA-xx da spec tem teste e está implementado
- [ ] Contrato igual ao `openapi.yaml`; cliente TS regenerado
- [ ] Regra de negócio em Actions/serviços, não em controller ou componente
- [ ] Nomes de domínio neutros; nenhum nome de produto no código
- [ ] Migrations compatíveis com a versão anterior
- [ ] `docs.md` e changelog atualizados

Comentários objetivos, com arquivo e linha, separando bloqueante de sugestão.
