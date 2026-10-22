# 08 · Infraestrutura, ambientes e segurança

> **Decisão atual (ADR [0004](arquitetura/adr/0004-hospedagem.md)):** no começo, tudo roda em containers Docker (Docker Compose) em um servidor simples, por custo. A tabela abaixo é o **alvo** (AWS `sa-east-1` com Terraform), para quando houver assinantes pagantes. Até lá, valem os mesmos requisitos de backup com restauração testada, segredos fora do repositório e arquivos em object storage.

O alvo é AWS na região de São Paulo (`sa-east-1`), com Cloudflare na frente e tudo em containers descritos como código (Terraform). Sai do servidor único do Tatulog, que não aguenta muitos assinantes nem tem redundância, e usa uma nuvem que o time já opera.

| Peça | Serviço | Observação |
| --- | --- | --- |
| Borda | Cloudflare | DNS com curinga `*.dominio`, SSL, WAF, proteção contra bots e cache |
| API, workers, web | ECS Fargate (containers) | Escala por quantidade de tarefas; deploy sem downtime |
| Banco | RDS PostgreSQL Multi-AZ | Backup automático com restauração em ponto no tempo |
| Cache e filas | ElastiCache Redis | Sessões, cache, filas do Horizon |
| Arquivos | S3 com URLs assinadas | Fotos e anexos por tenant, nunca públicos |
| E-mail | Amazon SES | Domínio com SPF, DKIM e DMARC |
| Segredos | Secrets Manager | Nada de chave em `.env` versionado |
| Observabilidade | Sentry + CloudWatch + uptime externo | Erros, logs estruturados com `tenant_id`, alertas |

## Ambientes

1. Local: Docker Compose com Postgres, Redis, Mailpit e o gateway em modo sandbox.
2. Preview por pull request (web) para revisar telas antes do merge.
3. Homologação: espelho da produção, com dados fictícios, onde rodam os testes ponta a ponta e o scan de segurança.
4. Produção: deploy pelo GitHub Actions só a partir de `master` (vinda de `develop`) com CI verde e aprovação manual.

## Segurança e LGPD

- Criptografia em trânsito (TLS) e em repouso (RDS, S3); campos sensíveis cifrados na aplicação.
- Isolamento por tenant testado em toda rota (ver [padrão de qualidade](09-padrao-de-qualidade.md)).
- Rate limiting por IP, usuário e tenant; login com bloqueio progressivo e 2FA para dono e super admin.
- Princípio do menor privilégio no IAM; acessos humanos à produção registrados.
- Migrations compatíveis com a versão anterior (expandir, depois contrair), porque apps antigos continuam chamando a API.
- LGPD: o assinante é controlador dos dados dos clientes dele e a plataforma é operadora. Termos, contrato de tratamento, exportação e exclusão de dados por tenant e por cliente final.
- Backups com teste de restauração periódico e pentest externo antes do lançamento público.

## Pendências de segurança antes de homologação e produção

Levantadas na revisão de segurança do FND-002 (o ambiente Docker atual é só de desenvolvimento):

- Imagens separadas para homologação e produção: usuário não-root, sem bind mount, sem `composer install`/`pnpm install` no start, sem `artisan serve` (usar php-fpm ou Octane), tags fixas; `migrate` fora do start automático.
- `APP_DEBUG=false` e `APP_ENV=production`; segredos e `APP_KEY` vindos de secret manager, sem senhas padrão (`${VAR:?}`).
- Banco e Redis sem porta pública, com senha forte e TLS.
- Allowlist explícita de CORS, cookies de sessão `secure`/`same_site`, e headers de segurança (HSTS, CSP, `X-Content-Type-Options`, `Referrer-Policy`) na web e na borda.
- `/api/v1/health` detalhado restrito à rede interna ou autenticado; só liveness (`/up`) público. Rate limiting nas rotas públicas.
- `composer audit` e `pnpm audit` no CI (FND-003).

### Pendências levantadas na revisão de segurança do FND-005

- Rodar migrations em um job separado, sem expor as credenciais do papel dono (`DB_ADMIN_*`) ao runtime; em produção o dono não deve ser superusuário.
- Papel de plataforma (`app_platform`) para escrever em `tenants` (cadastro, suspensão), a criar no PLT-003.
- Primeiro job de fila: tenant explícito no payload, aplicado e limpo por um middleware de job; hoje o `TenantScope` falha fechado.
- Rate limit nas rotas do grupo `tenant` (enumeração de tenants pelo 404) e avaliar não expor o `id` interno.
- `trustProxies` restrito aos IPs do balanceador e `trustHosts`; `sslmode=require` ou `verify-full` no banco.
- Teste de registro de rotas cobre só `/api/v1`; rotas fora dele (`up`, `storage`) não declaram grupo.
- Tabelas `jobs`, `cache` e `failed_jobs` ficam sem RLS (guardam payloads de tenants): revisar quando filas e cache entrarem em uso real.

### Pendências levantadas na revisão de segurança do PLT-001

- Bloqueio de conta permite negar serviço ao super admin (5 senhas erradas); avaliar bloqueio por par e-mail + IP ou entrada por IP confiável.
- Primeiro acesso do 2FA é *trust on first use*; considerar ativar o 2FA já na criação do usuário.
- Limite de tentativas só por IP: atrás de balanceador, configurar `trustProxies` (senão o limite vira global) e acrescentar limite por e-mail.
- CORS: restringir a origem do painel nas rotas da plataforma (hoje o padrão do Laravel aceita qualquer origem; sem cookie, risco baixo).
- `APP_DEBUG=false` fora do ambiente local; erros 500 em Problem Details quando o painel existir.
- Verificação de código de recuperação faz até 8 bcrypts por tentativa; aceitável com o limite por IP, registrar.
