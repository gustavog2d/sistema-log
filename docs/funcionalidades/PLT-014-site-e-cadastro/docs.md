# Site público: landing, contato e cadastro

> Para quem desenvolve o web.

## Para que serve

As páginas que a pessoa vê antes de ter conta: entender o produto, conhecer os planos, falar com a gente e criar a conta com 7 dias de teste.

## Como funciona

- **`/`** (`components/site/landing.tsx`): promessa, nichos, benefícios, planos e preços lidos de `GET /plans` no servidor (`server/plans.ts`), como funciona, contato e chamada final. Se a API estiver fora, mostra um aviso no lugar dos planos e o resto continua. `NEXT_PUBLIC_HIGHLIGHT_PLAN` (opcional) destaca um plano.
- **Contato** (`contact-form.tsx`): `POST /leads` com a origem da primeira visita da sessão (`lib/utm.ts`: UTM, referrer http(s) e página de entrada, em `sessionStorage`), o aceite da política e o token do anti-robô (`lib/captcha.ts`). O campo isca `company_site` fica fora da tela, do teclado e do leitor de tela.
- **Cadastro** (`signup-flow.tsx`, regras em `signup-model.ts`): 4 passos; o endereço é sugerido do nome e conferido na API com atraso de 400 ms; cupom em `signup/coupon-check`; cartão de teste (`tok_ok`, `tok_fail_on_charge`, `tok_declined`) só com `NEXT_PUBLIC_PAYMENT_MODE=fake` (fora disso o envio fica bloqueado e avisa); cortesia (cupom de 100%) tira o passo de pagamento. O envio usa `Idempotency-Key`: o mesmo conteúdo reutiliza a chave, mudar os dados gera outra. Erro 422 volta ao passo do campo. Sucesso leva a `/cadastro/pronto?negocio=…&fim=…`, que mostra o endereço no mesmo domínio e porta da página.
- **`/entrar`**: pede o endereço do negócio e leva a `{negócio}.{domínio}/entrar` (a tela de login do assinante é do PLT-015).
- **`/termos` e `/privacidade`**: textos provisórios em `i18n/pt-BR.json`.
- **Anti-robô em produção:** `NEXT_PUBLIC_CAPTCHA_MODE` vazio usa o reCAPTCHA v3 do Google com `NEXT_PUBLIC_RECAPTCHA_SITE_KEY` (chave pública); sem a chave o formulário não envia. A chave secreta fica só na API (`RECAPTCHA_SECRET`).
- **Anti-robô no cadastro:** `POST /signup` também leva `captcha_token` (ação `signup`; a API recusa token de outra ação). O token é novo a cada tentativa e fica fora da comparação de repetições da `Idempotency-Key`.
- **Cupom digitado:** ao continuar do passo do plano com um cupom digitado e ainda não aplicado, o cupom é conferido na hora (código inválido não deixa seguir), para o resumo e o envio dizerem o mesmo.
- **Planos em cache:** o servidor guarda os planos por 5 minutos e, se a API falhar, continua mostrando o último resultado bom.
- **Cabeçalhos de segurança** (`next.config.ts`): CSP (só o próprio site e o reCAPTCHA), `X-Frame-Options: DENY`, `frame-ancestors 'none'`, `Referrer-Policy`, `nosniff`, `Permissions-Policy` e HSTS em produção. A compilação de produção para se `NEXT_PUBLIC_PAYMENT_MODE` ou `NEXT_PUBLIC_CAPTCHA_MODE` for `fake`.
- **Chamadas à API:** a ponte e as páginas do servidor usam `hostAwareFetch` (`server/upstream.ts`), que respeita o cabeçalho `Host` (o `fetch` do Node o ignora), não pede compressão, limita a resposta a 5 MB e tem prazo de 10 s.
- A senha nunca vai para a URL, nem para `localStorage` ou `sessionStorage`, nem para o console.

## Em produção (obrigatório)

- Atrás de um balanceador que **reescreve** o `X-Forwarded-For`: `TRUST_FORWARDED_FOR=true` no web e, na API, `TRUSTED_PROXIES` com o endereço do web. Sem isso a API vê sempre o IP do contêiner do web e todos os limites por IP (contato, cadastro, cupom, endereço) viram um balde só; com `TRUST_FORWARDED_FOR=true` sem balanceador, o IP pode ser forjado.
- `NEXT_PUBLIC_RECAPTCHA_SITE_KEY` na compilação do web e `CAPTCHA_DRIVER=recaptcha` com `RECAPTCHA_SECRET` na API.
- `COOKIE_SECURE` vazio ou `true`, `API_URL` e `APP_BASE_DOMAIN` definidos.

## Limitações

- `/cadastro/pronto?negocio=…` mostra o endereço pedido na URL (um link forjado mostraria "conta criada" para outro negócio do mesmo domínio: baixo impacto, sem dado nenhum). Sem confirmação do e-mail antes do boas-vindas (o anti-robô reduz o abuso). Sem tokenização real do cartão (INT-001): em produção o cadastro de pagamento fica bloqueado até lá. Sem SEO avançado. O link "Entrar no meu painel" leva a uma página que só existe depois do PLT-015.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-23 | Landing, contato, cadastro, confirmação, busca do painel e termos |
