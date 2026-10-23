# Leads da landing

> Para quem desenvolve e opera a plataforma.

## Para que serve

Capta os interessados da landing (nome, e-mail, telefone, nicho), guarda de onde vieram (UTM) e deixa a equipe acompanhar o contato.

## Como funciona

- `POST /api/v1/leads` (público, só nos hosts da plataforma): dados do contato, UTM, `referrer`, `landing_path`, `accept_privacy` verdadeiro e `captcha_token`. A resposta é sempre 202 sem corpo, seja lead novo, repetido ou barrado pelo campo isca `company_site` (se vier preenchido, nada é gravado). Limite de 5 por hora por IP e 300 por hora no total.
- Anti-robô: `CAPTCHA_DRIVER=fake` (padrão local; aceita o token `captcha_ok`; recusado fora de local e testing) ou `recaptcha` com `RECAPTCHA_SECRET` (chave secreta só em arquivo local ou segredo do servidor, nunca no repositório; nota mínima `RECAPTCHA_MIN_SCORE`, 0,5). Falha do serviço do Google recusa o pedido.
- O mesmo e-mail em 24 horas não vira outro lead: só completa telefone e mensagem se vierem preenchidos. Depois disso vira um novo lead.
- O IP não é guardado: só um HMAC dele (com `APP_KEY`), para achar abuso. O consentimento (data e versão da política, `PRIVACY_POLICY_VERSION`) fica no lead.
- Equipe (suporte e super admin): `GET /platform/leads` (busca `q`, filtros `status` e `utm_source`, cursor) e `PATCH /platform/leads/{id}` (`status`: `new`, `contacted`, `converted`, `discarded`; `notes`). A auditoria `lead.updated` guarda o status antes e depois, sem as notas.
- Retenção: a rotina diária apaga os `discarded` e os `new`/`contacted` parados há mais de 12 meses; `converted` fica.

- O limite por hora conta também os pedidos recusados na validação, e o teto global de 300 por hora pode ser esgotado por um atacante (negação de serviço do formulário; o formulário deve avisar a pessoa para tentar mais tarde). O IP vem de `$request->ip()`: atrás de proxy é preciso configurar os proxies confiáveis, senão todos dividem o mesmo limite. O captcha é conferido depois da validação e só uma vez por pedido.
- Pedidos simultâneos do mesmo e-mail são serializados (trava por e-mail) e a alteração de status trava a linha. `status=` ou `limit=` vazios na lista dão 422.

- Reforços de segurança: o pedido repetido só preenche o que está vazio; textos sem caracteres de controle; e-mail só ASCII e sem aspas, parênteses nem `<>`; `referrer` só `http(s)` e `landing_path` só caminho; IP do visitante só de proxy declarado em `TRUSTED_PROXIES`; hash do IP com `LEADS_IP_HASH_KEY` (ou `APP_KEY`); o padrão do captcha em configuração é `recaptcha` e o `fake` derruba a subida fora de local e testing.
- Risco aceito: não há confirmação do e-mail (double opt-in): alguém pode cadastrar o contato de outra pessoa com o aceite da política; a equipe deve tratar o primeiro contato como pedido de confirmação. `converted` fica sem prazo até o dado migrar para o negócio; o titular pede exclusão pelo `DELETE`. A tela da equipe deve mostrar tudo como texto, nunca `dangerouslySetInnerHTML` nem `href` direto desses campos, e proteger uma futura exportação CSV contra fórmulas.

## Limitações

- Sem a landing e a tela (painel web), sem exportação, sem aviso à equipe a cada lead, sem integração com CRM. A página precisa pedir o consentimento e mostrar a política de privacidade (texto a definir pelo jurídico).

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-22 | Captura, anti-robô, deduplicação, gestão e retenção |
