---
name: revisao-seguranca
description: Checklist de revisão de segurança de um PR do sistema-log.
---

# revisao-seguranca

> Rascunho inicial. Refinar por PR.

- [ ] Toda consulta nova respeita o tenant (escopo + RLS) e está na suíte de isolamento
- [ ] Autorização por policy em toda rota; entitlement do plano checado na API
- [ ] Entrada validada; sem SQL cru com interpolação
- [ ] Nenhum segredo, token ou dado de cartão no código ou em log
- [ ] Dados pessoais minimizados e fora dos logs (LGPD)
- [ ] Rate limiting em login, cadastro e rotas públicas
- [ ] Webhooks com assinatura verificada e processamento idempotente
- [ ] Uploads com tipo/tamanho validados e URLs assinadas
- [ ] Dependências novas sem vulnerabilidade conhecida

Parecer: aprovado / aprovado com ressalvas / reprovado, com motivo.
