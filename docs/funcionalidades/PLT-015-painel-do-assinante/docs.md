# PLT-015 · Painel web do assinante

Telas do negócio no endereço `{negócio}.{domínio}` (local: `http://{slug}.localhost:3000`). Elas só usam a API `/api/v1`, sempre pela ponte do web (`/bff/*`).

## Para quem usa

| Tela | Quem vê | O que faz |
| --- | --- | --- |
| `/entrar` | todos | E-mail e senha; segunda etapa com código do aplicativo ou de recuperação |
| `/esqueci-senha`, `/redefinir-senha` | todos | Pede o link por e-mail e define a nova senha |
| `/aceitar-convite` | convidados | Define a senha, aceita os termos e entra |
| `/` | logados | Boas-vindas e situação da assinatura |
| `/assinatura` | dona | Plano, cartão (trocar ou regularizar), cancelar e desfazer, faturas |
| `/equipe` | dona e gerente | Convidar, trocar papel, desativar, reativar e cancelar convite |
| `/conta` | todos | Trocar senha, ligar e desligar o 2FA, sair e sair de todos os acessos |
| `/suporte` | equipe da plataforma | Entrada do acesso de suporte (token no fragmento `#token=`) |

- Assinatura suspensa ou cancelada: uma faixa avisa e os botões de escrita ficam desabilitados; `/assinatura` e `/conta` continuam funcionando para regularizar.
- O menu mostra só o que o papel pode usar; abrir uma página sem permissão mostra "sem permissão".
- O acesso de suporte mostra uma faixa fixa e esconde senha, 2FA e assinatura.

## Para quem desenvolve

- **Sessão:** o token da API fica só no cookie `HttpOnly` `session` (e `challenge` na etapa do 2FA), gravado pela ponte. A tela nunca o vê.
- **Roteamento:** `src/proxy.ts` reescreve `{slug}.localhost` para `/t/...` e repassa o caminho original em `x-original-path`. As páginas públicas ficam em `app/t/(public)`, as logadas em `app/t/(panel)` (o layout confere a sessão e o papel).
- **Adoção do suporte:** `POST /bff/session/adopt` troca o token do fragmento por cookie (`server/adopt.ts`).
- **Links dos e-mails:** a API monta os links do painel com `APP_WEB_HOST` e `APP_TENANT_SCHEME` (`config/tenancy.php`); nos testes são fixados em `tests/TestCase.php`.
- **Textos:** `apps/web/src/i18n/pt-BR.json`; o teste `no-hardcoded-text.test.ts` varre também `components/panel`.
- **Testes:** `docker compose exec web sh -c 'cd /workspace && pnpm --filter web exec vitest run src/components/panel'`. Em testes que simulam rejeição de `call`, use uma função comum (não `vi.fn`), pois o espião relança a rejeição numa cópia sem tratamento.

## Limites conhecidos

- O cartão é o meio de teste (`tok_ok`, `tok_declined`) até o INT-001 (Asaas).
- Troca de plano: PLT-013. Painel da equipe da plataforma: PLT-001b.
