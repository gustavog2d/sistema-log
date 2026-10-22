# Login do assinante

> Para quem desenvolve e opera a plataforma.

## Para que serve

Autentica as pessoas de um negócio (dona, equipe) pela API, no subdomínio do negócio. A web e o mobile usam os mesmos endpoints. Um token só vale no negócio que o emitiu.

## Como funciona

- `POST /api/v1/auth/login` com e-mail e senha devolve um token de acesso (12 h). Todas as falhas (e-mail inexistente, senha errada, sem vínculo, conta bloqueada) respondem igual; 5 falhas bloqueiam a conta por 15 minutos.
- Token: `{uuid}|{40 caracteres}` no cabeçalho `Authorization: Bearer`. Só o hash SHA-256 fica no banco (`user_access_tokens`, com RLS). Rotas autenticadas usam o middleware `auth.tenant`.
- `GET /auth/me` mostra a pessoa e o papel; `POST /auth/logout` apaga o token atual; `POST /auth/logout-all` invalida todos os tokens da pessoa (corte em `users.tokens_valid_after`).
- Senha: 12 a 72 bytes. Recuperação: `POST /auth/password/forgot` (sempre 202, e-mail com link de 60 minutos, uso único) e `POST /auth/password/reset`. `POST /auth/password/change` exige a senha atual e devolve um token novo; os demais caem.
- 2FA (TOTP) opcional: `/auth/2fa/setup`, `/auth/2fa/confirm` (devolve 8 códigos de recuperação), `/auth/2fa/disable` (exige a senha). Com 2FA ativo o login devolve um token de desafio (5 minutos) trocado por `/auth/2fa/verify`. `OWNER_TWO_FACTOR_REQUIRED=true` torna o 2FA obrigatório para donas.
- A senha só é lida pela conexão `pgsql_auth` (papel `app_auth`); o papel da API (`app_runtime`) não a enxerga.

- O bloqueio por falhas é por pessoa (5 falhas, 15 minutos) e só conta senha errada de quem tem vínculo com o negócio; a conta bloqueada vale em todos os negócios da pessoa.
- O e-mail de recuperação sai depois da resposta (`defer`), para o tempo não revelar quem tem vínculo.

## Limitações

- Sem tela de login ainda (painel web).
- Convite e desativação de pessoas são do PLT-008.
- Antes de homologação e produção: configurar os proxies confiáveis (`trustProxies`); sem isso os limites por IP veem o IP do balanceador.
- Pendentes (backlog): e-mail de aviso ao trocar a senha ou mudar o 2FA (PLT-010), exigir código para desligar o 2FA, expurgo de tokens expirados, link de recuperação com token no fragmento da URL.
- Risco aceito: qualquer pessoa que saiba o e-mail pode bloquear o login dele por 15 minutos dentro do próprio negócio (igual à plataforma).
- Decisão de produto pendente: 2FA obrigatório para donas (hoje opcional).

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Login, tokens, recuperação e troca de senha, 2FA |
