# PLT-001 · Login do super admin com 2FA (API)

- **Status:** aprovada (decisões técnicas delegadas por Gustavo em 2026-10-22)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** — (equipe da plataforma, fora de qualquer plano)
- **Depende de:** FND-005
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A equipe da plataforma (super admin e suporte) precisa entrar com segurança para gerir assinantes, planos e cobranças. Esta funcionalidade entrega a **API** de autenticação da equipe da plataforma, com segundo fator obrigatório. O painel web do super admin é uma entrega seguinte (PLT-001b), consumindo esta API.

Sucesso: um super admin criado por comando consegue ativar o 2FA, entrar com senha + código, chamar uma rota protegida e sair; ninguém entra só com a senha; usuário de assinante nunca acessa rotas da plataforma.

## Histórias de usuário

- Como Gustavo (super admin), quero entrar com e-mail, senha e código do app autenticador, para que uma senha vazada não baste.
- Como super admin, quero ativar o 2FA no primeiro acesso e guardar códigos de recuperação, para não perder o acesso.
- Como equipe da plataforma, quero sair e invalidar meu token.

## Regras de negócio

- **RN-01** — `platform_users` é tabela de plataforma (sem `tenant_id`): `id` (UUID), `name`, `email` (único, minúsculo), `password` (hash), `role` (`super_admin` ou `suporte`), `active`, segredo TOTP **cifrado**, `totp_confirmed_at`, códigos de recuperação **em hash**, `failed_attempts`, `locked_until`, `last_login_at`.
- **RN-02** — Todas as rotas deste item são do grupo `platform` (só no host da plataforma). No subdomínio de um assinante respondem 404.
- **RN-03** — O primeiro usuário é criado pelo comando `php artisan platform:create-admin`; não existe cadastro público de equipe.
- **RN-04** — O 2FA (TOTP) é obrigatório. Login com senha correta devolve um **token de desafio** (validade 5 minutos, só serve para o passo seguinte). Sem 2FA ativado, o desafio permite apenas configurar o 2FA; com 2FA, apenas verificar o código.
- **RN-05** — Só após o código correto (ou um código de recuperação, de uso único) a API emite o **token de acesso** (validade 8 horas, configurável). Token de desafio não acessa rota protegida.
- **RN-06** — Ativar o 2FA devolve o segredo e a URI `otpauth://`; a confirmação exige um código válido e devolve 8 códigos de recuperação, mostrados uma única vez.
- **RN-07** — Resposta de falha de login é a mesma para e-mail inexistente, senha errada e usuário inativo (sem enumeração de usuários), com tempo de resposta equivalente.
- **RN-08** — Após 5 falhas seguidas (senha ou código), a conta fica bloqueada por 15 minutos. Além disso, há limite de 10 tentativas por minuto por IP.
- **RN-09** — Todo evento relevante (login, falha, bloqueio, 2FA ativado, código de recuperação usado, logout) gera um registro de auditoria da plataforma, sem guardar senha nem código.
- **RN-10** — Logout revoga o token usado. Desativar o usuário revoga todos os tokens dele.
- **RN-11** — Erros no formato Problem Details (RFC 9457), incluindo 405; respostas da plataforma com `Cache-Control: no-store`.
- **RN-12** — Falhas de login esperadas não vão para o log da aplicação (a pilha de chamadas carregaria senha e códigos). E-mail inexistente também acumula falhas e é bloqueado igual a uma conta real; a senha é sempre conferida (hash fictício com o custo configurado) para o tempo de resposta não revelar a conta.
- **RN-13** — Token de usuário desativado é recusado mesmo que ainda exista; desativar por qualquer caminho revoga os tokens. O código TOTP, mesmo o primeiro, não é aceito duas vezes. Contadores e códigos de recuperação são atualizados com a linha travada.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-04 | Usuário sem 2FA, senha correta | `POST /platform/auth/login` | 200 com token de desafio `setup` |
| CA-02 | RN-06 | Token `setup` | `POST /platform/2fa/setup` e depois `/confirm` com código válido | Devolve segredo, depois códigos de recuperação e o token de acesso |
| CA-03 | RN-04, RN-05 | Usuário com 2FA, senha correta | Login e depois `POST /platform/auth/2fa/verify` com código válido | Token de acesso emitido |
| CA-04 | RN-05 | Token de desafio | `GET /platform/me` | 403 Problem Details |
| CA-05 | RN-05 | Token de acesso | `GET /platform/me` | 200 com nome, e-mail e papel |
| CA-06 | RN-07 | E-mail inexistente, senha errada ou usuário inativo | Login | Mesma resposta 401 nos três casos |
| CA-07 | RN-08 | 5 falhas seguidas | 6ª tentativa, mesmo correta | 423/429 Problem Details; liberada após 15 minutos |
| CA-08 | RN-05 | Código errado ou reutilizado | Verificação | 401; código de recuperação só vale uma vez |
| CA-09 | RN-02 | Host de um assinante | Qualquer rota `/platform/*` | 404 |
| CA-10 | RN-10 | Token de acesso | `POST /platform/auth/logout` e reuso | Token revogado, reuso devolve 401 |
| CA-11 | RN-01 | Usuário criado | Inspeção no banco | Senha em hash, segredo TOTP cifrado, recuperação em hash |
| CA-12 | RN-09 | Fluxo completo | Consulta de auditoria | Eventos registrados sem segredos |
| CA-13 | RN-03 | Comando executado | `platform:create-admin` | Usuário `super_admin` criado; e-mail duplicado recusado |
| CA-14 | RN-08 | Mais de 10 requisições por minuto do mesmo IP | Login | 429 |

## Permissões

| Ação | Super admin | Suporte |
| --- | --- | --- |
| Entrar, configurar o próprio 2FA, sair, ver o próprio perfil | Sim | Sim |

As demais permissões da equipe entram com cada funcionalidade.

## API

Contrato completo em `openapi.yaml`.

| Método | Rota | Descrição | Erros específicos |
| --- | --- | --- | --- |
| POST | `/api/v1/platform/auth/login` | Senha → token de desafio | 401, 423, 429 |
| POST | `/api/v1/platform/auth/2fa/verify` | Código TOTP ou de recuperação → token de acesso | 401, 423 |
| POST | `/api/v1/platform/2fa/setup` | Gera segredo e URI | 403 |
| POST | `/api/v1/platform/2fa/confirm` | Confirma o 2FA e devolve códigos de recuperação e token de acesso | 401, 403 |
| GET | `/api/v1/platform/me` | Perfil | 401, 403 |
| POST | `/api/v1/platform/auth/logout` | Revoga o token | 401 |

## Dados

- Tabelas de plataforma novas: `platform_users`, `platform_audit_logs`, `personal_access_tokens` (Sanctum). Nenhuma tem `tenant_id`/RLS; entram na lista de tabelas de plataforma do teste de esquema, de propósito.
- O papel de runtime (`app_runtime`) pode escrever nelas (a API precisa gravar tokens e auditoria). Usuários de assinantes (PLT-006) usarão tabela e tokens próprios, com `tenant_id` e RLS, para nunca misturar com a equipe da plataforma.
- Migration compatível com a versão anterior: sim (só adições).

## Eventos, auditoria e notificações

Registro em `platform_audit_logs` (usuário, evento, IP, agente, metadados sem segredos). Sem e-mail por enquanto.

## Impacto no mobile

Nenhum. O app dos assinantes usará outra autenticação (PLT-006).

## Fora do escopo

- Painel web do super admin (PLT-001b) e telas de gestão.
- Recuperação de senha da equipe (reset por comando enquanto a equipe for pequena).
- Autenticação de usuários de assinantes (PLT-006).
- Cadastro de novos membros da equipe pela interface.

## Riscos e perguntas em aberto

- A tabela `personal_access_tokens` do Sanctum é global; PLT-006 não deve reutilizá-la para tokens de assinantes sem `tenant_id`.
- Aprovação do tempo de validade dos tokens (5 min desafio, 8 h acesso): padrão de mercado, ajustável por configuração.
