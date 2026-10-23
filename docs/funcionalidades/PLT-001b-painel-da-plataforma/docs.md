# PLT-001b · Painel web da equipe da plataforma

Área da equipe em `/admin`, no domínio base (local: `http://localhost:3000/admin`). Usa só a API `/api/v1/platform/*`, pela ponte `/bff`.

## Para quem usa

| Tela | O que faz |
| --- | --- |
| `/admin/entrar` | Senha e código do 2FA. No primeiro acesso, configura o 2FA e mostra os códigos de recuperação uma única vez |
| `/admin` | Resumo: assinantes por situação, leads novos, cupons ativos (até 50 por situação) |
| `/admin/assinantes` | Lista com busca por nome ou endereço e filtro de situação |
| `/admin/assinantes/{id}` | Dados, cartão (bandeira e final), faturas, funcionalidades; ações abaixo |
| `/admin/planos` | Planos e preços; o super admin altera |
| `/admin/cupons` | Cupons; o super admin cria e altera (cupom de 100% exige limite de usos) |
| `/admin/leads` | Leads da landing: status, notas; o super admin apaga |

- **Super admin** escreve; **suporte** consulta e pode entrar como assinante.
- Ações no assinante (super admin): suspender (motivo), reativar, estender o teste (1 a 30 dias), exceção de funcionalidade (definir e remover).
- **Entrar como assinante** (também o suporte): pede o motivo (mínimo de 10 caracteres), abre o painel do negócio em outra aba por 30 minutos. O acesso fica registrado e a dona é avisada.

## Para quem desenvolve

- **Sessão:** cookie `HttpOnly` `session` da ponte; as páginas leem `GET /platform/me` no servidor (`server/admin-session.ts`). Sem sessão, vai a `/admin/entrar?voltar=` (só caminhos `/admin/...` internos, `safeAdminReturnPath`).
- **Login:** reaproveita `components/panel/login-form.tsx` (`variant="admin"`) e `two-factor-setup.tsx` (`base="platform/2fa"`).
- **Acesso de suporte:** o token devolvido pela API vai só ao fragmento `#token=` da nova aba (`/suporte`), com `noopener,noreferrer`; não fica em estado nem na tela.
- **Dados:** a primeira página vem do servidor; busca e "carregar mais" usam `call()` com cursor. Textos em `i18n/pt-BR.json` (`admin.*`).
- **Criar o primeiro acesso local:** `docker compose exec -e PLATFORM_ADMIN_PASSWORD=... api php artisan platform:create-admin <email> "<nome>"`.
- **Testes:** `docker compose exec web sh -c 'cd /workspace && pnpm --filter web exec vitest run src/components/admin'`. Em testes que simulam rejeição de `call`, use uma função comum (o espião do Vitest relança a rejeição numa cópia sem tratamento).

## Limites conhecidos

- Edição das regras de funcionalidade por plano e do catálogo de funcionalidades fica para uma entrega futura (hoje só leitura via API).
- O resumo conta no máximo 50 itens por situação (mostra `50+`); contadores exatos pedem um endpoint de resumo na API.
- Gestão de contas da própria equipe (criar, desativar, trocar senha) só pelo comando `platform:create-admin`.
