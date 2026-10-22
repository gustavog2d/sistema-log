# Login do super admin com 2FA (API)

> Para quem desenvolve e opera a plataforma.

## Para que serve

Autentica a equipe da plataforma (super admin e suporte) com senha e segundo fator (TOTP). É separada dos usuários dos assinantes.

## Primeiro acesso

1. Crie o super admin: `docker compose exec api php artisan platform:create-admin voce@exemplo.com "Seu Nome"` (a senha é pedida sem eco, mínimo 12 caracteres; para automação use a variável `PLATFORM_ADMIN_PASSWORD`, nunca um argumento de linha de comando).
2. Faça `POST /api/v1/platform/auth/login` no host da plataforma (`localhost` ou `admin.localhost` em desenvolvimento). A resposta traz um `challenge_token` e `next: "setup"`.
3. Com esse token, `POST /api/v1/platform/2fa/setup` devolve o segredo e a URI `otpauth://` para o app autenticador.
4. `POST /api/v1/platform/2fa/confirm` com o código de 6 dígitos devolve 8 códigos de recuperação (guarde-os, aparecem uma vez) e o token de acesso.

## Acessos seguintes

`login` → `challenge_token` (`next: "verify"`) → `POST /platform/auth/2fa/verify` com o código TOTP ou um código de recuperação → `access_token` (Bearer, 8 horas). O token de desafio vale 5 minutos e serve só para a etapa seguinte.

## Segurança

- Resposta de falha idêntica para e-mail inexistente, senha errada e usuário inativo.
- 5 falhas seguidas bloqueiam a conta por 15 minutos; 10 tentativas por minuto por IP.
- Códigos TOTP não podem ser reutilizados; códigos de recuperação valem uma vez.
- Segredo TOTP e códigos de recuperação ficam cifrados/em hash; auditoria em `platform_audit_logs` sem segredos.
- As rotas só respondem nos hosts da plataforma; no subdomínio de um assinante dão 404.

## Limitações

- Painel web: PLT-001b. Recuperação de senha: por enquanto, pelo comando/banco.
- Bloqueio por 15 minutos vale também para e-mails inexistentes (423 igual), e qualquer pessoa que saiba o e-mail do admin pode bloqueá-lo temporariamente: risco aceito pela regra de bloqueio (RN-08); mitigação futura: bloqueio por par e-mail + IP.
- O primeiro acesso é *trust on first use*: quem tiver só a senha de um usuário sem 2FA ativado consegue registrar o próprio autenticador. Ative o 2FA logo após criar o usuário.
- A tabela `personal_access_tokens` é global; tokens de assinantes (PLT-006) não devem usá-la sem `tenant_id`.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Login com 2FA, tokens Sanctum, auditoria e comando de criação |
