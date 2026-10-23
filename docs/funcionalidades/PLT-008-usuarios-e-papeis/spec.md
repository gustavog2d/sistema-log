# PLT-008 · Usuários e papéis do assinante

- **Status:** aprovada (matriz de `docs/06-perfis-e-permissoes.md`; decisões técnicas delegadas por Gustavo em 2026-10-22)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** `usuarios.max` (limite de usuários ativos)
- **Depende de:** PLT-006, PLT-005, PLT-002
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A dona precisa colocar a equipe no sistema: convidar por e-mail, escolher o papel, trocar o papel, desativar quem saiu, respeitando o limite de usuários do plano. Quem foi convidado define a própria senha pelo link.

## Histórias de usuário

- Como dona ou gerente, quero convidar uma pessoa por e-mail com um papel.
- Como convidada, quero abrir o link, definir minha senha e entrar.
- Como dona, quero trocar o papel de alguém e desativar quem saiu (que perde o acesso na hora).
- Como dona, quero ver quem está ativo, desativado ou convidado e cancelar um convite.

## Regras de negócio

- **RN-01** — Papéis: `owner`, `manager`, `professional`, `reception`. Há sempre exatamente uma dona; não há convite nem troca de papel para `owner`.
- **RN-02** — Quem gerencia: a dona gerencia todos; a gerente gerencia `manager`, `professional` e `reception` (nunca a dona). Profissional e recepção não gerenciam (403). As rotas de equipe funcionam só com a assinatura em teste ou ativa (escrita bloqueada se suspensa ou cancelada, pelo `subscription.access`); a listagem funciona sempre.
- **RN-03** — Convite: `POST /users/invitations` com nome, e-mail e papel. O e-mail vai em letras minúsculas. Já ser membro (ativo ou desativado) dá 409 `already_member`. Um novo convite ao mesmo e-mail substitui o pendente. O convite vale 7 dias; o token é guardado em hash (SHA-256) e o e-mail com o link sai depois da resposta.
- **RN-04** — Limite: usuários ativos mais convites pendentes não podem passar de `usuarios.max` do plano (quando a funcionalidade tem limite); acima disso, 409 `plan_limit_reached`. Reativar alguém também respeita o limite.
- **RN-05** — Aceite: `POST /auth/invitations/accept` (público no subdomínio, funciona com assinatura suspensa) com e-mail, token e senha. Pessoa nova cria a conta com a senha informada (12 a 72 bytes); pessoa que já tem conta em outro negócio precisa informar a senha dela e ganha o vínculo. O token é de uso único; token inválido, expirado ou de outro negócio dá sempre a mesma resposta 422. O aceite devolve a sessão do login (ou o desafio de 2FA).
- **RN-06** — Trocar papel: `PATCH /users/{id}` com `role`. Ninguém troca o próprio papel; a dona não pode ser alterada.
- **RN-07** — Desativar (`POST /users/{id}/deactivate`): o vínculo fica `inactive`, os tokens da pessoa neste negócio caem na hora e ela não consegue entrar nele; os dados dela ficam. Ninguém desativa a si mesmo nem a dona. Reativar (`/reactivate`) volta ao papel anterior.
- **RN-08** — Login e uso: vínculo desativado não autentica nem usa token existente (401, igual a credencial inválida).
- **RN-09** — Listagem `GET /users`: pessoas (id, nome, e-mail, papel, `status` = `active`/`inactive`) e convites pendentes (`status` = `invited`, com `expires_at`), sem hash ou token. Visível para dona e gerente.
- **RN-10** — `DELETE /users/invitations/{id}` cancela um convite pendente (204).
- **RN-11** — Cada negócio só vê e altera a própria equipe. Auditoria dos eventos de equipe fica para o PLT-010/auditoria do assinante; o convite grava `invited_by` e o aceite apaga o convite.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-02 | Profissional, recepção, sem login | Qualquer rota de equipe | 403; 401 sem login |
| CA-02 | RN-02, RN-03 | Dona | Convida gerente, profissional e recepção | 201; e-mail com link enviado depois da resposta; token em hash |
| CA-03 | RN-01, RN-02 | Gerente | Convida `owner`; gerencia a dona | 422; 403 |
| CA-04 | RN-03 | E-mail já membro; convite repetido | Convidar | 409 `already_member`; o convite novo substitui o pendente |
| CA-05 | RN-04 | Plano com limite 2 e 2 ocupados | Convidar; reativar | 409 `plan_limit_reached` |
| CA-06 | RN-05 | Pessoa nova com token válido | Aceitar | 200; conta criada; vínculo ativo; sessão; token consumido |
| CA-07 | RN-05 | Pessoa com conta em outro negócio | Aceitar com a senha dela / senha errada | Vínculo criado / 422, sem vínculo |
| CA-08 | RN-05 | Token expirado, usado, inválido, de outro negócio, e-mail trocado | Aceitar | 422 sempre igual |
| CA-09 | RN-06 | Dona; gerente | Dona troca o papel de alguém; troca o próprio; gerente troca o papel da dona | 200; 422; 403 |
| CA-10 | RN-07, RN-08 | Pessoa ativa com token | Dona desativa | Token deixa de valer na hora; login negado; dados mantidos |
| CA-11 | RN-07 | Desativada | Reativar | Volta ao papel anterior; respeita o limite |
| CA-12 | RN-07 | Auto-desativação; dona alvo | Desativar | 422 (a dona nunca é desativada; gerente tentando recebe 403) |
| CA-13 | RN-09 | Equipe e convites | `GET /users` | Sem token/hash; status corretos; só deste negócio |
| CA-14 | RN-10 | Convite pendente | Cancelar | 204; o token deixa de valer |
| CA-15 | RN-11 | Dois negócios | Qualquer rota | Cada um só mexe no seu; token de um não vale no outro |
| CA-16 | RN-02 | Assinatura suspensa | Convidar; listar | 403 `subscription_suspended`; listar funciona |
| CA-17 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/api/v1/users` | Equipe e convites | 401, 403 |
| POST | `/api/v1/users/invitations` | Convida | 401, 403, 409, 422, 429 |
| DELETE | `/api/v1/users/invitations/{id}` | Cancela convite | 401, 403, 404 |
| PATCH | `/api/v1/users/{id}` | Troca o papel | 401, 403, 404, 422 |
| POST | `/api/v1/users/{id}/deactivate` | Desativa | 401, 403, 404, 422 |
| POST | `/api/v1/users/{id}/reactivate` | Reativa | 401, 403, 404, 409 |
| POST | `/api/v1/auth/invitations/accept` | Aceita o convite | 422, 429 |

## Dados

`memberships.status` (`active`/`inactive`, padrão `active`) e a tabela `invitations` (com RLS: id, tenant_id, email, name, role, token_hash, invited_by, expires_at, created_at; o aceite apaga o convite). O papel de autenticação (`app_auth`) escreve em vínculos, convites e pessoas; a API só lê as colunas não secretas dos convites.

## Fora do escopo

Transferência de titularidade, permissões finas por funcionalidade (chegam com cada módulo), auditoria do assinante, e-mails com tema (PLT-010), tela.
