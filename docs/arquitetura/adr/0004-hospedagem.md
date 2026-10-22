# ADR 0004 · Hospedagem e infraestrutura

- **Status:** aceito (com revisão prevista)
- **Data:** 2026-10-22
- **Decisores:** Gustavo

## Contexto

[docs/08](../../08-infraestrutura-e-seguranca.md) recomenda AWS `sa-east-1` com ECS Fargate, RDS Multi-AZ, ElastiCache e Terraform. Esse desenho tem custo fixo relevante antes de existir o primeiro assinante, e o projeto está em planejamento, sem receita.

## Opções consideradas

1. **AWS com ECS Fargate + Terraform desde o início.**
2. **Docker simples no começo** (containers em um servidor, por Docker Compose), com migração para AWS + Terraform depois.
3. PaaS gerenciado (Render, Railway, Fly).

## Decisão

Opção 2. Tudo roda em containers Docker desde o dia 1 (local, homologação e produção inicial), para que a migração para AWS + Terraform seja trocar o lugar onde os containers rodam, não reescrever a aplicação. A migração para Terraform é uma fase posterior, a decidir quando houver assinantes pagantes.

## Consequências

- Positivas: custo baixo no começo; ambientes idênticos ao local; migração futura previsível.
- Negativas / riscos: sem Multi-AZ, o banco e o servidor viram pontos únicos de falha; deploy sem downtime e escala horizontal ficam manuais.
- O que precisa ser feito:
  - Mitigar o risco com backup automático do PostgreSQL fora do servidor e teste de restauração periódico (já exigido em docs/08).
  - Manter segredos fora do repositório, Cloudflare na frente (DNS curinga, SSL, WAF) e imagens sem estado, com arquivos em object storage (S3 ou compatível) e não em disco local.
  - Definir o provedor do servidor (VPS) e atualizar FND-004: a infra de homologação deixa de ser Terraform e passa a ser Docker Compose; o Terraform vira item futuro do backlog.
  - Definir o gatilho para migrar (número de assinantes, SLA prometido ou exigência de pentest).
