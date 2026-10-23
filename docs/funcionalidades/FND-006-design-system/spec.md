# FND-006 · Design system e base do web

- **Status:** aprovada (Gustavo pediu sugestão de cores e logotipo em 2026-10-22 e delegou as decisões técnicas)
- **Fase:** 0 · Fundação
- **Plano / feature flag:** —
- **Depende de:** FND-001, FND-002
- **Responsável pela spec:** agente Produto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

O web precisa de uma base visual e técnica única antes das telas: tokens de design (cores, tipografia, espaços), componentes acessíveis, os três layouts (site, painel do assinante, painel da equipe) e o caminho seguro até a API. Sem isso cada tela inventaria o seu estilo e o seu jeito de falar com a API.

## Regras de negócio

- **RN-01** — Os tokens têm uma fonte só (`packages/ui-tokens`): um arquivo CSS (`tokens.css`, tema do Tailwind) e um objeto TypeScript espelho para o mobile. Um teste garante que os dois têm os mesmos valores. Nada de cor ou espaço solto nos componentes.
- **RN-02** — Paleta sugerida (nome e logotipo ainda são provisórios): marca em **azul-tinta** (`#3B3FB3`), destaque em **âmbar** (`#F5A524`), neutros em **ardósia**, e estados de sucesso, alerta, erro e informação. Todo par texto/fundo usado pelos componentes tem contraste mínimo de 4,5:1 (3:1 para texto grande e componentes de interface), conferido por teste.
- **RN-03** — Componentes base: botão (variações e estado de carregamento), campo de texto, área de texto, seleção, caixa de marcação, campo com rótulo/dica/erro, cartão, selo, aviso, indicador de carregamento, estado vazio, tabela, janela de diálogo e logotipo. Cada um tem estados de foco, desabilitado e (quando cabe) erro, e passa na verificação de acessibilidade automática (axe) sem violações.
- **RN-04** — Acessibilidade: rótulo ligado a cada campo, erro e dica ligados por `aria-describedby`, foco sempre visível, alvo de toque de pelo menos 44 px no celular, diálogo que prende o foco e fecha com Esc, link "pular para o conteúdo", marcos de página (cabeçalho, navegação, principal) e navegação por teclado.
- **RN-05** — Layouts: `MarketingLayout` (site público), `AppShell` (painel do assinante, menu lateral que vira menu recolhível no celular) e `AdminShell` (equipe da plataforma). O nome do produto vem sempre de configuração (`NEXT_PUBLIC_APP_NAME`), nunca fixo; o logotipo é um monograma provisório gerado a partir do nome.
- **RN-06** — Texto de interface só em arquivos de tradução (pt-BR primeiro), com chaves tipadas; um teste falha se um componente de `ui/` tiver texto fixo em português.
- **RN-07** — Acesso à API pelo servidor do web (BFF): o navegador só fala com `/bff/*` no próprio endereço (sem CORS); o servidor repassa à API trocando o endereço (host) pelo do negócio, para a API resolver o assinante. O token de acesso fica em cookie `HttpOnly`, `SameSite=Lax` (e `Secure` fora do ambiente local), nunca no JavaScript. O servidor devolve ao navegador a resposta sem o token (única exceção: o acesso de suporte do PLT-009, que a tela da equipe entrega ao endereço do assinante por fragmento de URL; o PLT-001b define o desvio e a política de conteúdo). Chamadas que mudam dados exigem o cabeçalho `X-Requested-With` (proteção contra CSRF). O caminho da API é validado segmento a segmento (letras, números, hífen, sublinhado), depois de decodificado uma vez, e nunca sai de `/api/v1`. O endereço decide o que se chama: o domínio base usa as rotas da plataforma, do cadastro e dos leads; o endereço de assinante usa as rotas do negócio; endereço inválido, ou rota do outro mundo, é 404. O `X-Forwarded-For` do cliente é descartado (só vale com `TRUST_FORWARDED_FOR=true`, atrás do nosso balanceador); o corpo é limitado (256 KB, só JSON) e a chamada à API tem prazo de 15 s.
- **RN-08** — Roteamento por endereço: o domínio base serve o site e o painel da equipe (`/admin`); `{negócio}.{domínio base}` serve o painel do assinante. Um endereço de assinante não abre as páginas do site nem do painel da equipe, e vice-versa (404).
- **RN-09** — Erros: a API responde em Problem Details; o web mostra a mensagem de campo (422) junto ao campo e a mensagem geral em um aviso, sem expor detalhes técnicos. Falha de rede mostra um aviso para tentar de novo.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Arquivo CSS e objeto TS | Teste de paridade | Mesmos valores |
| CA-02 | RN-02 | Pares texto/fundo da paleta | Teste de contraste | Todos ≥ 4,5:1 (≥ 3:1 em texto grande e componentes) |
| CA-03 | RN-03, RN-04 | Cada componente em cada estado | Teste com axe | Sem violações |
| CA-04 | RN-04 | Campo com erro e dica | Renderizar | Rótulo, dica e erro ligados por id; `aria-invalid` |
| CA-05 | RN-04 | Diálogo aberto | Teclado | Foco dentro; Esc fecha; foco volta a quem abriu |
| CA-06 | RN-04, RN-05 | Os três layouts | Renderizar | Link de pular, marcos corretos, menu por teclado |
| CA-07 | RN-05 | Nome do produto em configuração | Logotipo e título | Mostram o nome da configuração |
| CA-08 | RN-06 | Componentes de `ui/` | Varredura | Nenhum texto fixo em português |
| CA-09 | RN-07 | Pedido ao BFF com e sem cookie | Servidor | Repassa com `Authorization` e host do negócio; sem cookie não envia `Authorization` |
| CA-10 | RN-07 | Resposta de login da API | BFF | Cookie `HttpOnly` gravado; token fora do corpo devolvido |
| CA-11 | RN-07 | Método que muda dados sem `X-Requested-With`; caminho fora da lista | BFF | 403; 404 |
| CA-12 | RN-08 | Hosts do site, do negócio e inválidos | Roteamento | Reescreve para o grupo certo; o cruzamento dá 404 |
| CA-13 | RN-09 | Resposta 422 e falha de rede | Função de chamada | Erros de campo mapeados; aviso geral |

## Fora do escopo

Tema escuro, ilustrações, animações, biblioteca de ícones além do necessário, as telas (PLT-014, PLT-015, PLT-001b).
