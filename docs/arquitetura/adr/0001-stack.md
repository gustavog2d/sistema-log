# ADR 0001 · Stack da API e dos clientes

- **Status:** aceito
- **Data:** 2026-10-22
- **Decisores:** Gustavo

## Contexto

O sistema precisa de uma API usada pela web agora e por apps iOS/Android depois, com cobrança recorrente, multi-tenant e alto padrão de segurança. O time tem mais de 15 anos de PHP/Laravel. O Tatulog roda em Laravel 11 + Filament, e o prompt de reconstrução sugeria TypeScript ponta a ponta.

## Opções consideradas

1. **Laravel (PHP 8.4+) na API + TypeScript nos clientes** (Next.js na web, React Native + Expo no mobile).
2. **TypeScript ponta a ponta** (NestJS ou Next.js no back-end, Prisma).

## Decisão

Opção 1, confirmada por Gustavo em 2026-10-22. Ver [docs/04-stack.md](../../04-stack.md).

## Consequências

- Positivas: aproveita a experiência do time nas partes sensíveis (cobrança, multi-tenant, segurança); ecossistema SaaS maduro; clientes TypeScript compartilham cliente da API e validações.
- Negativas: duas linguagens no monorepo; validações duplicadas entre PHP e TS (mitigado pelo contrato OpenAPI como fonte de verdade).
