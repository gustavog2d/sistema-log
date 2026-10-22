# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em ISO 8601.

## [Não lançado]

### Adicionado

- PLT-002: catálogo de planos (Essencial, Profissional e Estúdio) e de funcionalidades, regras por plano (liberada, limitada, bloqueada visível ou oculta), preço mensal e anual com desconto, listagem pública de planos ativos e serviço de entitlements.
- PLT-001: login da equipe da plataforma (super admin e suporte) com senha e TOTP obrigatório, tokens Sanctum de desafio e de acesso, bloqueio progressivo, auditoria e comando `platform:create-admin`.
- FND-005: base multi-tenant (resolução por subdomínio, RLS, papel de runtime sem BYPASSRLS, suíte de isolamento).
- FND-003: CI no GitHub Actions (api, web, contract, structure, audit).
- FND-002: esqueleto do monorepo (`apps/api` Laravel, `apps/web` Next.js, `packages/api-client`, `packages/ui-tokens`), ambiente local em Docker Compose, `GET /api/v1/health`, scripts de verificação, agentes e skills em `.claude/`.
- FND-001: ADRs 0001 (stack) e 0004 (hospedagem em Docker) aceitos; 0003 (gateway) proposto.
