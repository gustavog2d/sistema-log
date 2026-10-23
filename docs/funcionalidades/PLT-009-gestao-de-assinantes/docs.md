# Gestão de assinantes

> Para quem desenvolve e opera a plataforma.

## Para que serve

A equipe da plataforma enxerga os assinantes, trata exceções e ajuda quem tem dúvida entrando na conta, sempre com rastro e aviso à dona.

## Como funciona

- Rotas em `/api/v1/platform/tenants…` (hosts da plataforma, login da equipe). Suporte e super admin: `GET /tenants` (busca `q` por nome ou endereço, filtro `status`, `limit` 1 a 50 e cursor), `GET /tenants/{id}` e `POST /tenants/{id}/impersonate`. Só o super admin: `suspend` (com motivo), `unsuspend`, `extend-trial` (1 a 30 dias) e `PUT/DELETE /tenants/{id}/entitlements/{feature}`.
- Exceção de funcionalidade (`tenant_entitlement_overrides`) tem prioridade sobre o plano em tudo que usa o `EntitlementResolver` (hoje, o limite de usuários da equipe). Funcionalidade de liberar/bloquear não aceita `limit`.
- Suspender na mão marca a assinatura como `suspended` e guarda o motivo; reativar volta para `active` (ou `trialing` se a assinatura nunca foi paga, e a cobrança acontece no vencimento). Assinatura cancelada responde 409.
- Entrar como assinante: devolve um token da dona, de 30 minutos, marcado em `user_access_tokens.impersonated_by`. Gera auditoria (`tenant.impersonated`, com o motivo) e um e-mail de aviso à dona (sem o motivo). O token cai junto com o acesso da dona (senha trocada, pessoa desativada) e não vale em outro negócio. Não pode: trocar senha, mexer em 2FA, sair de todos os acessos nem gerir a assinatura (403 `impersonation_forbidden`); `GET /auth/me` mostra `impersonating`. Limite de 10 por hora por pessoa da equipe.
- Auditoria: `tenant.suspended`, `tenant.unsuspended`, `tenant.trial_extended`, `tenant.entitlement_set`, `tenant.entitlement_reset`, `tenant.impersonated`, sempre com quem fez e antes/depois.

- O acesso de suporte só lê a equipe (convidar, trocar papel e desativar são barrados), cada pedido feito com ele deixa um registro estruturado (`support_access.request`, sem dado pessoal) e ele só vale enquanto a pessoa da equipe estiver ativa. Se a gravação da auditoria falhar, o token recém-emitido é apagado. Suspensão da equipe (com motivo) não se desfaz pagando: a dona recebe 409 `suspended_by_support` e fala com o suporte.
- Decisão registrada: `tenant_entitlement_overrides` é dado da plataforma (sem RLS, escrito só pelo papel de plataforma); a API só lê, sempre filtrando pelo negócio do contexto. Funcionalidade de limite desligada por exceção (`enabled=false`) hoje equivale a "sem limite" no limite de usuários: a regra de bloqueio fica para quando o catálogo definir o que cada plano esconde.

## Limitações

- Sem tela (PLT-001b), sem exclusão de assinante, sem busca por e-mail da dona. A suspensão manual não manda e-mail.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Lista, detalhe, exceções, suspensão, extensão do teste e acesso de suporte |
