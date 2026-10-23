---
name: tela-web
description: Padrão de tela no app web (Next.js) do sistema-log.
---

# tela-web

> Rascunho inicial. Refinar por PR.

> Exemplos de código serão adicionados após FND-002.

- Dados só pelo cliente gerado (`packages/api-client`) via TanStack Query.
- Estados obrigatórios: carregando, vazio, erro, sem permissão (403), fora do plano (convite a upgrade), suspenso (só leitura).
- Formulários com validação espelhando o contrato; erros da API (RFC 9457) mostrados no campo.
- Textos em i18n; vocabulário do nicho vindo das configurações do tenant.
- Acessível (labels, foco, contraste) e responsivo, mobile primeiro.
