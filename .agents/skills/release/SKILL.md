---
name: release
description: Checklist de release e deploy do sistema-log.
---

# release

> Rascunho inicial. Refinar por PR.

- [ ] CI verde em `main`; homologação validada (E2E + ZAP)
- [ ] Migrations revisadas (expandir/contrair) e backup recente confirmado
- [ ] Feature flags definidas para o que não deve aparecer ainda
- [ ] Plano de rollback escrito
- [ ] Aprovação manual de Gustavo no deploy de produção
- [ ] Monitorar Sentry e métricas por 30 minutos após o deploy
- [ ] Changelog publicado e comunicado aos assinantes, se houver mudança visível
