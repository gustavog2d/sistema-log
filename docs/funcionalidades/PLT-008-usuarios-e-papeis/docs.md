# Usuários e papéis

> Para quem desenvolve e opera a plataforma.

## Para que serve

A dona (e a gerente) monta a equipe do negócio: convida por e-mail, define o papel, desativa quem saiu.

## Como funciona

- Papéis: `owner` (um só), `manager`, `professional`, `reception`. A dona gerencia todos; a gerente, todos menos a dona. Profissional e recepção não acessam as rotas de equipe.
- `POST /users/invitations` (nome, e-mail, papel): cria um convite de 7 dias; o e-mail com o link sai depois da resposta; só o hash do token fica no banco. Convidar de novo substitui o pendente; quem já é membro dá 409 `already_member`.
- `POST /auth/invitations/accept` (e-mail, token, senha): pessoa nova cria a conta com a senha; quem já tem conta informa a senha atual. Devolve a sessão (ou o desafio de 2FA). Token inválido, expirado ou de outro negócio sempre dá o mesmo 422.
- Limite: `usuarios.max` do plano conta pessoas ativas e convites pendentes (409 `plan_limit_reached`); sem limite configurado, não há teto. Reativar também respeita o limite.
- Desativar (`POST /users/{id}/deactivate`) derruba na hora os tokens da pessoa neste negócio e bloqueia o login nele; os dados ficam. Os acessos dela em outros negócios não mudam. Reativar volta ao papel anterior.
- `GET /users` lista pessoas e convites pendentes (sem segredos) e funciona mesmo com a assinatura suspensa; as mudanças exigem assinatura em teste ou ativa.
- Escritas usam o papel de banco `app_auth`; a API só lê (e nunca vê o hash do token do convite).

- O aceite exige `accept_terms` verdadeiro e registra os termos na conta nova. O aceite funciona com a assinatura suspensa e não confere o limite de novo (a vaga já foi contada no convite pendente).
- Convites: o nome só aceita letras, números e pontuação simples; no máximo 3 convites por dia para o mesmo e-mail (entre todos os negócios).
- Riscos aceitos: uma gerente pode rebaixar ou desativar outras gerentes (nunca a dona). O link do convite leva o token na URL (`/aceitar-convite?token=`); a tela deve limpar a URL e usar `Referrer-Policy: no-referrer`.

## Limitações

- Sem transferência de titularidade, sem permissões finas por funcionalidade (chegam com cada módulo), sem auditoria do assinante e sem e-mails com tema (PLT-010). Sem tela.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Convites, aceite, papéis, desativação e limite do plano |
