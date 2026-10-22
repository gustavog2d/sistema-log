# 13 · Backlog inicial (Fases 0 e 1)

São 17 itens; cada um vira uma pasta em `docs/funcionalidades/<ID>-<slug>/` com spec, documentação e plano de teste antes de qualquer código. A Fase 0 entrega documentos, padrões e infraestrutura; a Fase 1 entrega a plataforma de assinatura.

Status possíveis: `A especificar` → `Spec em revisão` → `Pronto para dev` → `Em desenvolvimento` → `Entregue`.

| ID | Item | Depende de | Teste crítico | Status |
| --- | --- | --- | --- | --- |
| FND-001 | ADRs: stack, multi-tenant (RLS), gateway de pagamento, hospedagem | — | — | Em andamento (falta ADR 0003, gateway) |
| FND-002 | Monorepo, `CLAUDE.md`, agentes e skills iniciais | FND-001 | Lint e testes rodando localmente e em container (o CI os executa no FND-003) | Entregue |
| FND-003 | CI: lint, análise estática, testes, contrato e auditoria de dependências (deploy em homologação virou FND-007) | FND-002 | Pipeline falha com estilo, lint ou contrato quebrado; bloqueio de merge depende da proteção de branch | Entregue |
| FND-004 | Infra de homologação em Docker Compose (API, workers, Postgres, Redis, armazenamento de objetos, e-mail) + Cloudflare com curinga; backup e restauração ensaiados (ADR 0004). Terraform/AWS fica para depois | FND-001 | Deploy e rollback ensaiados | A especificar |
| FND-005 | Base multi-tenant: resolução por subdomínio e token, RLS, suíte de isolamento | FND-002 | Tenant A não acessa dado do tenant B | Entregue |
| FND-006 | Design system base (tokens, componentes, layout do painel) | FND-001 | Componentes com testes de acessibilidade | A especificar |
| FND-007 | Deploy em homologação com Docker Compose no servidor escolhido (inclui backup/restauração e Cloudflare) | FND-003, FND-004 | Deploy e rollback ensaiados | Adiado (primeiro roda só local; ADR 0004) |
| PLT-001 | Login do super admin com 2FA (API); o painel web vira PLT-001b | FND-005 | Rota de plataforma inacessível a usuário de tenant | Entregue (API) |
| PLT-001b | Painel web do super admin (login, 2FA e navegação) consumindo a API do PLT-001 | PLT-001, FND-006 | Rota de plataforma inacessível a usuário de tenant | A especificar |
| PLT-002 | Catálogo de planos e funcionalidades (entitlements) gerido pelo super admin | PLT-001 | Funcionalidade fora do plano devolve 403 | Em desenvolvimento |
| PLT-003 | Cadastro self-service: dados do negócio, escolha do subdomínio, nicho, teste grátis | PLT-002 | Subdomínio único e reservado; provisionamento idempotente | A especificar |
| PLT-004 | Assinatura e cobrança recorrente pelo gateway (checkout, webhooks, reconciliação) | PLT-003 | Webhook repetido não duplica cobrança; ciclo de vida completo no sandbox | A especificar |
| PLT-005 | Bloqueio e liberação de acesso conforme status da assinatura | PLT-004 | Suspensa = só leitura; regularizar reativa | A especificar |
| PLT-006 | Autenticação do assinante (web e token mobile), recuperação de senha, 2FA do dono | FND-005 | Token de um tenant não vale em outro | A especificar |
| PLT-007 | Gestão da assinatura pelo dono: plano, forma de pagamento, faturas, upgrade, downgrade, cancelamento | PLT-004 | Downgrade acima do limite é bloqueado com aviso | A especificar |
| PLT-008 | Usuários e papéis do assinante: convite, papéis, desativação, limite do plano | PLT-006 | Cada papel só acessa o que a matriz permite | A especificar |
| PLT-009 | Gestão de assinantes no super admin: lista, detalhe, exceções, suspender, impersonação auditada | PLT-005 | Impersonação sempre registrada | A especificar |
| PLT-010 | E-mails transacionais da plataforma (boas-vindas, teste acabando, cobrança, suspensão) | PLT-004 | Cada evento dispara um e-mail, uma vez | A especificar |
| PLT-011 | Landing, leads com UTM e reCAPTCHA, tela de leads no super admin | PLT-001 | Lead inválido ou sem captcha rejeitado | A especificar |
| PLT-012 | Cupons e assinaturas de cortesia: cupom de 100% ou proporcional, criado pelo super admin, com validade e limite de uso | PLT-002, PLT-003 | Cupom de 100% assina sem cobrança; proporcional reduz o valor | A especificar |

```mermaid
flowchart LR
    FND001[FND-001] --> FND002[FND-002] --> FND003[FND-003]
    FND001 --> FND004[FND-004]
    FND001 --> FND006[FND-006]
    FND002 --> FND005[FND-005]
    FND005 --> PLT001[PLT-001] --> PLT002[PLT-002] --> PLT003[PLT-003] --> PLT004[PLT-004]
    PLT004 --> PLT005[PLT-005] --> PLT009[PLT-009]
    PLT004 --> PLT007[PLT-007]
    PLT004 --> PLT010[PLT-010]
    FND005 --> PLT006[PLT-006] --> PLT008[PLT-008]
    PLT001 --> PLT011[PLT-011]
```
