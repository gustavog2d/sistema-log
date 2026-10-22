# 03 · Arquitetura

Uma única API REST versionada atende todos os clientes: painel web, página pública de agendamento, super admin e, depois, os apps. O painel web não acessa o banco por outro caminho, então o que funciona na web já funciona para o mobile.

```mermaid
flowchart TB
    subgraph Clientes
        P["Painel do negócio<br/><small>web · subdomínio</small>"]
        A["Agenda pública<br/><small>link do profissional</small>"]
        S["Super admin<br/><small>admin.dominio</small>"]
        M["Apps iOS/Android<br/><small>Fase 5 · mesma API</small>"]
    end
    CF["Cloudflare: DNS curinga, SSL, WAF e CDN"]
    subgraph API["API Laravel · REST /api/v1 + OpenAPI"]
        AU["Autenticação<br/><small>tokens web e mobile</small>"]
        TR["Resolução do tenant<br/><small>subdomínio ou token</small>"]
        PL["Planos e permissões<br/><small>feature flags e limites</small>"]
        MO["Módulos de negócio<br/><small>agenda, orçamento, finanças</small>"]
    end
    W["Filas e workers<br/><small>e-mails, webhooks, sync Google, relatórios</small>"]
    EX["Serviços externos<br/><small>gateway de pagamento, Google Calendar, e-mail e WhatsApp</small>"]
    PG[("PostgreSQL<br/><small>RLS por tenant</small>")]
    RD[("Redis<br/><small>cache e filas</small>")]
    S3[("Object storage<br/><small>S3: fotos, anexos</small>")]

    P --> CF
    A --> CF
    S --> CF
    M -.-> CF
    CF --> API
    API --> W --> EX
    API --> PG
    API --> RD
    API --> S3
    style M stroke-dasharray: 5 5
```

O tracejado marca o que fica para depois; a caixa da API é o núcleo que precisa existir desde a Fase 1.

## Decisões de arquitetura

- **API-first com contrato OpenAPI.** O contrato é escrito na especificação de cada funcionalidade antes do código. Dele sai um cliente TypeScript tipado, usado pela web e depois pelo app.
- **Versionamento** em `/api/v1`. Mudança que quebra contrato só em `/v2`, para não derrubar apps antigos que ainda estão nas lojas.
- **Multi-tenant em banco compartilhado.** Toda tabela de negócio tem `tenant_id`, e o PostgreSQL aplica Row Level Security como segunda barreira, além do filtro na aplicação. Escala para milhares de tenants sem migrar milhares de bancos. (ADR [0002](arquitetura/adr/0002-multi-tenant.md))
- **Resolução do tenant.** Na web, pelo subdomínio (`estudio.dominio`). No app, o login devolve um token ligado ao usuário e ao tenant. Domínio próprio do cliente fica para um plano superior.
- **Autenticação.** Sessão com cookie para a web e token para o mobile, no mesmo provedor. 2FA para donos e super admin.
- **Padrões de API para mobile:** paginação por cursor, `Idempotency-Key` em criação de agendamento e pagamento, datas em ISO 8601 com UTC no banco e fuso do tenant na exibição, erros no formato RFC 9457 (Problem Details).
- **Trabalho pesado em filas**: e-mails, notificações, webhooks de pagamento, sincronização com o Google Calendar e relatórios.
- **Eventos de domínio** (agendamento criado, orçamento aprovado, assinatura vencida) disparam notificações, auditoria e integrações, sem acoplar os módulos.
- **Monólito modular**: um repositório de API com módulos isolados (Plataforma, Agenda, Orçamento, Financeiro, Clientes). Microserviços só se algum módulo provar que precisa.
