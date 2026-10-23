# Design system e base do web

> Para quem desenvolve o web.

## Para que serve

Uma base visual e técnica única para todas as telas: tokens, componentes acessíveis, layouts e o caminho seguro até a API.

## Como funciona

- **Tokens:** `packages/ui-tokens` (`src/index.ts` é a fonte; `src/tokens.css` é o espelho do tema do Tailwind; um teste confere a paridade e o contraste de cada par de cores usado). Use sempre classes do tema (`bg-brand-600`, `text-muted`, `border-control-border`), nunca valores soltos.
- **Componentes:** `apps/web/src/components/ui` (botão, campos com rótulo/dica/erro, cartão, selo, aviso, tabela, diálogo, logotipo, carregando, estado vazio). Texto de interface só por `t('chave')` (`src/i18n/pt-BR.json`); um teste varre `ui/` e `layout/` atrás de texto fixo.
- **Layouts:** `MarketingLayout` (site), `AppShell` (painéis do assinante e da equipe) e `PageHeader`.
- **Guia de estilo:** `/guia-de-estilo` (só no domínio base) mostra a paleta com os contrastes e os componentes.
- **Roteamento por endereço (`src/proxy.ts`):** o domínio base (`localhost` em desenvolvimento) serve o site e o painel da equipe (`/admin`); `{negócio}.localhost:3000` serve o painel do assinante (arquivos em `src/app/t/`). O cruzamento é 404.
- **Ponte com a API (BFF, `src/app/bff/[...path]`):** a tela chama `call('POST', 'auth/login', { body })` (`src/lib/api.ts`), que fala com `/bff/*` no próprio endereço. O servidor repassa à API com o endereço do negócio e guarda o token em cookie `HttpOnly` (`session`, e `challenge` no meio do login em duas etapas); o token nunca aparece no JavaScript. Mudanças exigem `X-Requested-With: fetch`; só caminhos da lista são aceitos; a ponte não segue redirecionamentos e não guarda cache. Em produção os cookies saem `Secure` (`COOKIE_SECURE=true`).
- **Erros:** `ApiError` traz a mensagem, o status, os erros por campo (422), o código (`code`) e o `retry-after`; erro 5xx mostra mensagem genérica.
- **Endereços reservados:** `www`, `app`, `admin` e `api` nunca são painel de assinante (a API os trata como hosts da plataforma).
- **Variáveis:** `NEXT_PUBLIC_APP_NAME` (nome do produto), `APP_BASE_DOMAIN` (domínio base), `API_URL` (endereço interno da API), `COOKIE_SECURE` (cookies `Secure` por padrão fora de desenvolvimento), `TRUST_FORWARDED_FOR` e `STYLE_GUIDE`. `NEXT_PUBLIC_APP_NAME` é embutida na compilação do Next: em produção precisa existir já na build. Atrás do balanceador, declare o contêiner do web em `TRUSTED_PROXIES` da API (senão os limites por IP veem o IP do web).

## Limitações

- As páginas dos painéis (`src/app/t/`, `/admin`) ainda não existem: o roteamento já as separa, e elas chegam nos itens PLT-014, PLT-015 e PLT-001b (até lá, 404 do Next). `/guia-de-estilo` só abre em desenvolvimento (em produção só com `STYLE_GUIDE=true`).

- Só tema claro. O logotipo é um monograma provisório. Sem biblioteca de ícones. As telas vêm nos itens PLT-014, PLT-015 e PLT-001b.

## Changelog

| Versão | Data | Mudança |
| --- | --- | --- |
| 0.1.0 | 2026-10-23 | Tokens, componentes, layouts, ponte com a API e roteamento por endereço |
