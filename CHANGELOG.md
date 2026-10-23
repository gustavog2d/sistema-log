# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/). Datas em ISO 8601.

## [Não lançado]

### Adicionado

- PLT-008: equipe do negócio (API): convite por e-mail com link de 7 dias, aceite com senha própria, troca de papel, desativação com queda imediata dos acessos, reativação e limite `usuarios.max` do plano.
- PLT-007: gestão da assinatura pela dona (API): trocar o meio de pagamento, regularizar assinatura suspensa com cobrança na hora, pedir e desfazer o cancelamento (vale no fim do teste ou do período pago, sem cobrança) e listar faturas.
- PLT-006: login do assinante (API) com e-mail e senha, token opaco por negócio (RLS), sair e sair de todos, recuperação e troca de senha, 2FA (TOTP) opcional, bloqueio por falhas e papel de banco `app_auth`.
- PLT-004: cobrança do fim do teste e das renovações com o meio de pagamento de teste (`billing:run`, a cada 5 minutos), faturas com RLS, suspensão imediata em caso de falha e simulação de tempo com `--as-of`.
- PLT-005: bloqueio por status da assinatura (somente leitura quando suspensa ou cancelada, código `subscription_suspended`/`subscription_canceled`) e `GET /api/v1/subscription`.
- PLT-003: cadastro do assinante com teste de 7 dias (API): negócio, dona, vínculo com RLS, meio de pagamento de teste, assinatura em teste com preço congelado, idempotência e papel de banco de plataforma.
- PLT-002: catálogo de planos (Essencial, Profissional e Estúdio) e de funcionalidades, regras por plano (liberada, limitada, bloqueada visível ou oculta), preço mensal e anual com desconto, listagem pública de planos ativos e serviço de entitlements.
- PLT-001: login da equipe da plataforma (super admin e suporte) com senha e TOTP obrigatório, tokens Sanctum de desafio e de acesso, bloqueio progressivo, auditoria e comando `platform:create-admin`.
- FND-005: base multi-tenant (resolução por subdomínio, RLS, papel de runtime sem BYPASSRLS, suíte de isolamento).
- FND-003: CI no GitHub Actions (api, web, contract, structure, audit).
- FND-002: esqueleto do monorepo (`apps/api` Laravel, `apps/web` Next.js, `packages/api-client`, `packages/ui-tokens`), ambiente local em Docker Compose, `GET /api/v1/health`, scripts de verificação, agentes e skills em `.claude/`.
- FND-001: ADRs 0001 (stack) e 0004 (hospedagem em Docker) aceitos; 0003 (gateway) proposto.
