# PLT-006 · Login do assinante (web e mobile)

- **Status:** aprovada (decisões técnicas delegadas por Gustavo em 2026-10-22)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-003, PLT-005
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

O cadastro cria o negócio e a dona, mas ninguém consegue entrar. Esta funcionalidade entrega a **API** de autenticação de quem usa um negócio: entrar com e-mail e senha no endereço do negócio, sair, recuperar a senha, trocar a senha e ativar a verificação em duas etapas (2FA). Web e app usam a mesma API e o mesmo tipo de token.

## Histórias de usuário

- Como dona, quero entrar no meu negócio com e-mail e senha.
- Como pessoa do negócio, quero recuperar a senha se esquecê-la.
- Como dona, quero proteger minha conta com 2FA.
- Como Gustavo, quero que o token de um negócio nunca valha em outro e que trocar a senha derrube os acessos antigos.

## Regras de negócio

- **RN-01** — O login é feito no endereço do negócio (`{slug}.{domínio}`): `POST /api/v1/auth/login` com `email` e `password`. A pessoa precisa ter vínculo (`membership`) com **aquele** negócio; sem vínculo, e-mail inexistente e senha errada dão a **mesma** resposta 401 (sem enumeração), com tempo equivalente.
- **RN-02** — Token de acesso opaco (`{id}|{segredo}`, no cabeçalho `Authorization: Bearer`), válido por 12 horas (configurável), usado igualmente pela web (guardado pelo servidor da web, nunca no navegador) e pelo app. Só o hash do segredo (SHA-256) é guardado. Cada token pertence a **um negócio**: a tabela `user_access_tokens` tem `tenant_id` e RLS, então um token de um negócio não é nem visto nos outros.
- **RN-03** — Bloqueio da conta após 5 falhas seguidas (senha ou código) por 15 minutos (423); e-mail inexistente também acumula falhas e bloqueia igual. Limite de 10 tentativas por minuto por IP e por par (negócio, e-mail).
- **RN-04** — Suspensa ou cancelada, a pessoa **entra normalmente** (acesso só de leitura, PLT-005). As rotas de autenticação (login, recuperação e troca de senha, 2FA, saída) não são bloqueadas pela assinatura.
- **RN-05** — `GET /api/v1/auth/me` devolve a pessoa, o papel no negócio, o negócio e `can_write` da assinatura. `GET /api/v1/subscription` passa a exigir login.
- **RN-06** — Saída: `POST /auth/logout` revoga o token usado; `POST /auth/logout-all` invalida todos os tokens da pessoa em **todos** os negócios (`users.tokens_valid_after`). Trocar ou redefinir a senha também invalida todos os tokens anteriores.
- **RN-07** — Recuperação de senha: `POST /auth/password/forgot` sempre responde 202 (nunca revela se o e-mail existe) e, se a pessoa tem vínculo com o negócio, envia um e-mail com um link de uso único válido por 60 minutos (token guardado em hash). `POST /auth/password/reset` define a nova senha, consome o token, e invalida os tokens antigos. Limite de 5 pedidos por hora por e-mail (no negócio) e de 20 por hora por IP.
- **RN-08** — Troca de senha (`POST /auth/password/change`) exige a senha atual, invalida os outros tokens e devolve um token novo.
- **RN-09** — Senhas: mínimo de 12 caracteres e no máximo 72 bytes (limite do bcrypt), em cadastro, redefinição e troca.
- **RN-10** — 2FA (TOTP) **opcional** para qualquer pessoa, recomendado para a dona; uma configuração (`auth.owner_two_factor_required`, padrão desligada) permite torná-lo obrigatório para donas (decisão de produto pendente). Com 2FA ativo, o login devolve um token de desafio (5 minutos, uso único) e o acesso só sai depois do código (TOTP ou código de recuperação de uso único), com os mesmos cuidados da equipe da plataforma (código não reutilizável, bloqueio por falhas, segredo cifrado, códigos de recuperação em hash).
- **RN-11** — Dados sensíveis: senha em hash, segredo TOTP e códigos de recuperação cifrados ou em hash; nada disso volta em respostas. O papel de banco da API (`app_runtime`) continua **sem** ler a senha: a autenticação usa um papel próprio (`app_auth`), com acesso só ao necessário.
- **RN-12** — Todas as rotas respondem apenas no endereço de um negócio; nos hosts da plataforma, 404. Erros em Problem Details.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-02 | Dona com vínculo | `POST /auth/login` correto | 200 com token, validade e a pessoa com papel |
| CA-02 | RN-01 | E-mail inexistente, senha errada, sem vínculo com este negócio | Login | Mesma resposta 401 |
| CA-03 | RN-03 | 5 falhas | 6ª tentativa | 423; liberada após 15 minutos; e-mail inexistente também bloqueia |
| CA-04 | RN-02 | Token do negócio A | Usar no negócio B | 401 |
| CA-05 | RN-05 | Token válido | `GET /auth/me` | Pessoa, papel, negócio e `can_write` |
| CA-06 | RN-06 | Token | `POST /auth/logout` e reuso | 204 e depois 401 |
| CA-07 | RN-06 | Tokens em dois negócios | `POST /auth/logout-all` | Todos invalidados |
| CA-08 | RN-02 | Token com mais de 12 horas | Usar | 401 |
| CA-09 | RN-04 | Negócio suspenso | Login e rotas de autenticação | Funcionam; `can_write` falso |
| CA-10 | RN-07 | E-mail de membro, de não membro, inexistente | `POST /auth/password/forgot` | Sempre 202; e-mail só para quem tem vínculo; token em hash |
| CA-11 | RN-07 | Token válido, expirado, reutilizado, inválido | `POST /auth/password/reset` | Só o primeiro válido funciona; os demais 422; tokens antigos invalidados |
| CA-12 | RN-08 | Senha atual correta e errada | `POST /auth/password/change` | Troca e novo token; os outros tokens caem; errada dá 422 |
| CA-13 | RN-09 | Senhas curtas e longas demais | Reset e troca | 422 |
| CA-14 | RN-10 | Pessoa ativa o 2FA | Setup, confirmação e novo login | Login pede o código; código certo dá acesso; código errado, reutilizado e de recuperação conforme regras |
| CA-15 | RN-10 | 2FA obrigatório para donas (configuração ligada) e dona sem 2FA | Login | Token restrito à configuração do 2FA |
| CA-16 | RN-11 | Cadastro e login feitos | Consulta ao banco | Senha em hash, token em hash, segredo cifrado; respostas sem esses campos |
| CA-17 | RN-11 | Papel da API | Ler `users.password` ou inserir token | Negado pelo banco |
| CA-18 | RN-02 | Tokens de dois negócios | Papel da API em contexto de um | Só vê os do negócio |
| CA-19 | RN-03 | Mais de 10 tentativas por minuto | Login | 429 |
| CA-20 | RN-12 | Host da plataforma | Qualquer rota `/auth/*` | 404 |
| CA-21 | RN-05 | Sem login | `GET /subscription` | 401 |
| CA-22 | contrato | Respostas | Comparar com o OpenAPI | Batem (tipos, enums, obrigatórios) |

## Permissões

Todas as pessoas com vínculo autenticam. Papéis (dono, gerente, profissional, recepção) vêm de `memberships`; as permissões por funcionalidade entram com cada funcionalidade.

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição |
| --- | --- | --- |
| POST | `/api/v1/auth/login` | Entrar (token ou desafio de 2FA) |
| POST | `/api/v1/auth/2fa/verify` | Código do 2FA, devolve o token |
| POST | `/api/v1/auth/logout` · `/auth/logout-all` | Sair |
| GET | `/api/v1/auth/me` | Quem sou eu |
| POST | `/api/v1/auth/password/forgot` · `/reset` · `/change` | Recuperar e trocar a senha |
| POST | `/api/v1/auth/2fa/setup` · `/confirm` · `/disable` | Gerir o 2FA |

## Dados

- `users` ganha: contadores de falhas e bloqueio, campos do 2FA, `tokens_valid_after`, `last_login_at`.
- Novas: `user_access_tokens` (dado de assinante: `tenant_id`, RLS), `password_reset_tokens` (global, hash).
- Novo papel de banco `app_auth` (conexão `pgsql_auth`), sem superusuário e sem BYPASSRLS.
- Migration compatível com a versão anterior: sim.

## Eventos, auditoria e notificações

E-mail de recuperação de senha (texto simples, em português; o tema visual dos e-mails é do PLT-010). Auditoria do assinante entra com o registro de auditoria do negócio (item futuro).

## Impacto no mobile

O app usa os mesmos endpoints e o mesmo token Bearer no endereço do negócio.

## Fora do escopo

Convite e gestão de usuários (PLT-008), confirmação de e-mail e e-mails com tema (PLT-010), impersonação (PLT-009), login social, telas, autorização por funcionalidade.

## Riscos e perguntas em aberto

- Decisão de produto: 2FA obrigatório para donas? (hoje opcional, configurável).
- Pessoa com o mesmo e-mail em mais de um negócio: entra em cada um pelo endereço dele, com a mesma senha.
