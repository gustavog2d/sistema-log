# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

# sistema-log

Instruções para o Claude Code (e qualquer agente) neste repositório. A documentação e os commits são em português (pt-BR).

## Estado do projeto

Fase 0 (Fundação). Existe o esqueleto do monorepo (FND-002): `apps/api` (Laravel), `apps/web` (Next.js), `packages/api-client` (gerado do OpenAPI) e `packages/ui-tokens`, com um único endpoint `GET /api/v1/health`. Ainda não há regra de negócio, multi-tenant nem CI (FND-003, FND-005). `apps/mobile` só na Fase 5.

## Comandos

Tudo roda em containers (ADR 0004); o PHP do host é antigo e o pnpm não precisa estar instalado. Use Docker Compose a partir da raiz.

```bash
cp .env.example .env                 # primeira vez
docker compose up -d --build --wait  # API :8000, web :3000, Mailpit :8025, Postgres, Redis, worker
docker compose down                  # derruba (acrescente -v para apagar o banco)

# API (Laravel, dentro do serviço api)
docker compose exec api vendor/bin/pest                          # testes (PostgreSQL real, banco app_test)
docker compose exec api vendor/bin/pest --filter="<nome do teste>"  # um teste só
docker compose exec api vendor/bin/pint                          # formata (--test só verifica)
docker compose exec api vendor/bin/phpstan analyse --memory-limit=1G  # Larastan nível max

# Web e pacotes (serviço web; pnpm workspaces)
docker compose exec web sh -c 'cd /workspace && pnpm test'       # Vitest
docker compose exec web sh -c 'cd /workspace && pnpm --filter web exec vitest run <arquivo> -t "<nome>"'  # um teste só
docker compose exec web sh -c 'cd /workspace && pnpm lint && pnpm typecheck'
docker compose exec web sh -c 'cd /workspace && pnpm api-client:generate'  # regenera o cliente após mudar o openapi.yaml
docker compose exec web sh -c 'cd /workspace && pnpm format'     # Prettier (código; ignora *.md e docs)

# Verificação completa do FND-002
bash scripts/verify-structure.sh && bash scripts/verify-compose.sh && bash scripts/verify-quality.sh
```

Notas:

- **Multi-tenant (FND-005):** a API roda com o papel `app_runtime` (sujeito ao RLS); migrations rodam com `--database=pgsql_admin`. Toda rota de `/api/v1` declara `tenant`, `platform` ou `public`; toda tabela de negócio usa `$table->tenantId()` + `Schema::enableTenantRls()` e o trait `BelongsToTenant`. Detalhes em `docs/funcionalidades/FND-005-base-multi-tenant/docs.md`. Após esta mudança, recrie o volume (`down -v`).
- **Equipe da plataforma (PLT-001):** login com senha + TOTP em `/api/v1/platform/*` (tokens Sanctum de desafio e de acesso). Criar o primeiro super admin: `docker compose exec api php artisan platform:create-admin <email> "<nome>"` (pede a senha; em automação, variável `PLATFORM_ADMIN_PASSWORD`). Detalhes em `docs/funcionalidades/PLT-001-login-super-admin/docs.md`.
- **Cadastro e cobrança (PLT-003):** o cadastro (`POST /api/v1/signup`, com `Idempotency-Key`) escreve com o papel `app_platform` (conexão `pgsql_platform`); o papel da API só lê tenants, assinaturas e pagamentos. O meio de pagamento está atrás de `App\Billing\PaymentGateway`, hoje só o `fake` (tokens `tok_ok`, `tok_fail_on_charge`, `tok_declined`). O servidor de desenvolvimento lê só o `apps/api/.env`; o `start.sh` acrescenta chaves novas do `.env.example`. Detalhes em `docs/funcionalidades/PLT-003-cadastro-do-assinante/docs.md`.
- **Site (PLT-014):** páginas públicas em `apps/web/src/app` (`/`, `/cadastro`, `/cadastro/pronto`, `/entrar`, `/termos`, `/privacidade`) e componentes em `components/site`. Variáveis do web em desenvolvimento: `NEXT_PUBLIC_PAYMENT_MODE=fake` (cartões de teste) e `NEXT_PUBLIC_CAPTCHA_MODE=fake`; em produção, `NEXT_PUBLIC_RECAPTCHA_SITE_KEY`. A ponte usa `hostAwareFetch` (o `fetch` do Node ignora o cabeçalho `Host`). Detalhes em `docs/funcionalidades/PLT-014-*`.
- **Web (FND-006):** tokens em `packages/ui-tokens` (`index.ts` fonte, `tokens.css` espelho, teste de paridade e contraste); componentes em `apps/web/src/components/ui`, layouts em `components/layout`; texto só por `t('chave')`. O navegador nunca fala com a API: usa `call()` (`src/lib/api.ts`) → `/bff/*` (`src/server/bff.ts`), que guarda o token em cookie `HttpOnly`. `src/proxy.ts` roteia por endereço: domínio base = site e `/admin`; `{slug}.localhost:3000` = painel do assinante em `src/app/t/`. Guia de estilo em `/guia-de-estilo`. Detalhes em `docs/funcionalidades/FND-006-*` e `docs/16-design-system.md`.
- **Leads (PLT-011):** `POST /leads` (público, hosts da plataforma) com `captcha_token` conferido por `App\Leads\CaptchaVerifier` (`CAPTCHA_DRIVER=fake` só em local/testing, token `captcha_ok`; em produção `recaptcha` com `RECAPTCHA_SECRET` fora do repositório). Equipe: `GET /platform/leads`, `PATCH /platform/leads/{id}`. Resposta pública sempre 202. IP só em hash. Detalhes em `docs/funcionalidades/PLT-011-*`.
- **Gestão de assinantes (PLT-009):** `/platform/tenants` (lista, detalhe), `suspend`, `unsuspend`, `extend-trial`, `entitlements/{feature}` (exceções, `tenant_entitlement_overrides`) e `impersonate` (token da dona, 30 min, `user_access_tokens.impersonated_by`). Serviços em `App\Admin`; as exceções são lidas por `TenantEntitlementOverrides` e passadas ao `EntitlementResolver`. Acesso de suporte é barrado em senha, 2FA e assinatura pelo middleware `no.impersonation`. Detalhes em `docs/funcionalidades/PLT-009-*`.
- **E-mails (PLT-010):** `App\Notifications\Notifier` envia pela fila, uma vez por acontecimento (`notification_log.dedupe_key`), e nunca derruba a ação de origem. Eventos de assinatura (`billing.charged`, `subscription.*`) levam `mark` no contexto para a chave. Comando `notifications:run [--as-of]` (a cada 15 min) avisa o fim do teste. Textos em `resources/views/emails/notifications/`; o nome do produto vem de `config('app.name')`. Detalhes em `docs/funcionalidades/PLT-010-*`.
- **Cupons (PLT-012):** `POST /platform/coupons` (super admin), `POST /signup/coupon-check`, `coupon_code` no cadastro. Regras em `App\Coupons\CouponService`; o preço da assinatura nasce com o desconto (congelado); cupom de 100% dispensa o cartão e as renovações geram fatura de valor zero. Cupom indisponível responde sempre o mesmo 422. Detalhes em `docs/funcionalidades/PLT-012-*`.
- **Equipe (PLT-008):** `GET /users`, `POST /users/invitations`, `PATCH /users/{id}`, `POST /users/{id}/deactivate|reactivate`, `POST /auth/invitations/accept`. Rotas para `role:owner,manager` (middleware `role`); regras em `App\Team\TeamService` (papel `app_auth`). Vínculo `inactive` não autentica. Limite lido de `usuarios.max` via `EntitlementResolver`. Detalhes em `docs/funcionalidades/PLT-008-*`.
- **Gestão da assinatura (PLT-007):** `PUT /subscription/payment-method` (regulariza se suspensa), `POST /subscription/cancel|reactivate`, `GET /subscription/invoices`; só a dona (`owner`), rotas `subscription.exempt`. Regras em `App\Billing\SubscriptionManager` (papel `app_platform`, linha travada). A API lê o meio de pagamento só pela visão `payment_method_summaries`. Cancelamento pedido = `canceled_at` com status em teste/ativa; `billing:run` encerra no vencimento. Detalhes em `docs/funcionalidades/PLT-007-*`.
- **Login do assinante (PLT-006):** `POST /api/v1/auth/login` (e `/auth/2fa/verify`), `/auth/logout`, `/auth/logout-all`, `/auth/me`, `/auth/password/{forgot,reset,change}`, `/auth/2fa/{setup,confirm,disable}`. Token `{uuid}|{40 chars}` guardado em SHA-256 (`user_access_tokens`, RLS); rotas autenticadas usam o middleware `auth.tenant`. Conexão `pgsql_auth` (papel `app_auth`) lê a senha; recriar o volume do Postgres (`docker compose down -v`) ao mudar `init.sql`. Testes de TOTP usam o relógio do Laravel (`totp()` em `TenantAuthTestHelpers`). Detalhes em `docs/funcionalidades/PLT-006-*`.
- **Cobrança e acesso (PLT-004/005):** `php artisan billing:run [--as-of=DATA]` cobra fim de teste e renovações (agendado a cada 5 min; `--as-of` simula o tempo); falha de pagamento suspende na hora. Rotas do assinante que gravam usam `['tenant', 'subscription.access']` (403 com `code` quando suspenso/cancelado). Status em `GET /api/v1/subscription`. Detalhes em `docs/funcionalidades/PLT-004-*` e `PLT-005-*`.
- **Planos e funcionalidades (PLT-002):** o catálogo é dado, não código: nunca compare com a chave de um plano. Quem decide é `App\Plans\EntitlementResolver` (exceção do assinante, regra do plano, padrão da funcionalidade). O catálogo inicial vem de `CatalogSeeder` (idempotente, roda no start). Detalhes em `docs/funcionalidades/PLT-002-catalogo-de-planos/docs.md`.
- **Banco dos testes:** a suíte roda sempre no `app_test` (fixado em `tests/TestCase.php`) e se recusa a rodar em outro banco; ela trunca e recria tabelas. Uma versão anterior rodava no banco de desenvolvimento por causa da variável `DB_DATABASE` do contêiner.
- Os testes da API exigem PostgreSQL real; o `phpunit.xml` força `DB_DATABASE=app_test`, criado por `docker/postgres/init.sql` (só roda ao criar o volume; se faltar o banco, apague o volume com `down -v`).
- O Larastan não analisa `tests/` (closures do Pest com `$this`); só `app`, `routes` e `bootstrap`.
- O contrato por enquanto está em `docs/funcionalidades/FND-002-monorepo-e-agentes/openapi.yaml`; o `packages/api-client` é gerado a partir dele (orval, `fetch`). O contrato completo único (`docs/api/openapi.yaml`, montado no CI) ainda não existe.
- Texto de interface vem de `apps/web/src/i18n/pt-BR.json` via `t()`.

## Stack prevista (ADR 0001)

Monorepo: `apps/api` (PHP 8.4+ / Laravel, PostgreSQL 16+ com RLS, Redis + Horizon), `apps/web` (Next.js, TypeScript strict, Tailwind, shadcn/ui, TanStack Query), `apps/mobile` (Expo, Fase 5), `packages/api-client` (gerado do OpenAPI 3.1), `packages/ui-tokens`. Testes: Pest, Vitest, Playwright, k6. Qualidade: Larastan nível máximo, Pint, ESLint, Prettier. Detalhes em `docs/04-stack.md`.

## Regra de ouro

**Sem spec aprovada, sem código.** Toda funcionalidade tem uma pasta em `docs/funcionalidades/<ID>-<slug>/` com `spec.md`, `test-plan.md`, `docs.md` e `openapi.yaml`. O desenvolvimento só começa quando o `spec.md` está com `Status: aprovada`. Os testes do plano são escritos antes da implementação.

## Onde está cada coisa

- Visão geral e mapa: `README.md`
- Arquitetura e decisões: `docs/03-arquitetura.md`, `docs/arquitetura/adr/`
- Modelo de domínio: `docs/07-modelo-de-dominio.md`
- Padrão de qualidade e definição de pronto: `docs/09-padrao-de-qualidade.md`
- Fluxo de agentes: `docs/10-fluxo-de-agentes.md`; agentes em `.claude/agents/`
- Skills: `docs/11-skills.md`; arquivos em `.claude/skills/`
- Backlog: `docs/13-backlog.md`
- Sistema antigo (Tatulog): `docs/referencias/tatulog-especificacao-funcional.md`

## Convenções

- **Nomes provisórios.** O nome do produto, domínios, e-mails e cores ficam em configuração, nunca no código. No código, use termos de domínio neutros (`tenant`, `appointment`, `quote`, `service`), nunca "sistema-log". Textos de interface ficam em arquivos de tradução (pt-BR primeiro). Novo termo → registrar em `docs/glossario.md`.
- **API-first.** A web é só mais um cliente da API REST `/api/v1`. Nenhuma tela acessa dados por outro caminho. O contrato OpenAPI é atualizado antes do código.
- **Multi-tenant.** Toda tabela de negócio tem `tenant_id` e política RLS no PostgreSQL. Toda rota nova entra na suíte de isolamento de tenant.
- **Autorização na API**, por policies e entitlements do plano. A interface só reflete o resultado.
- **Datas** em UTC no banco, ISO 8601 na API, fuso do tenant na exibição. Durações em minutos. Telefones em E.164. Dinheiro em centavos (inteiro) ou decimal com moeda explícita.
- **Erros** no formato RFC 9457 (Problem Details). Criação de agendamento e pagamento aceita `Idempotency-Key`. Paginação por cursor.
- **Migrations** compatíveis com a versão anterior (expandir, depois contrair).
- IDs de funcionalidade: `FND-xxx` (fundação), `PLT-xxx` (plataforma), `AGD-xxx` (agenda), `ORC-xxx` (orçamento), `FIN-xxx` (financeiro), `CLI-xxx` (clientes), `INT-xxx` (integrações), `MOB-xxx` (mobile).
- **Fluxo de branches:** `<ID>-<slug>` → PR para `develop` → PR de `develop` para `master`. `master` é produção; nunca commit direto em `develop` nem em `master`. O PR referencia a spec. Commits em português, no imperativo.
- Repositório: https://github.com/gustavog2d/sistema-log

## Definição de pronto (resumo de `docs/09-padrao-de-qualidade.md`)

- Cada critério de aceite tem teste automatizado rastreável pelo ID da regra.
- Cobertura da camada de domínio ≥ 90%; a total não cai.
- A suíte de isolamento de tenant (bloqueante no PR) cobre as rotas novas.
- OpenAPI atualizado e `packages/api-client` regenerado sem diferença pendente.
- `docs.md` escrito e changelog atualizado; revisão de código e de segurança aprovadas.

## Aprovações humanas

Gustavo aprova em dois pontos: spec pronta para desenvolvimento e PR pronto para merge. Agentes não fazem merge em `develop` nem em `master`, nem deploy em produção.
