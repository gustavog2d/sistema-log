# PLT-001b · Painel web da equipe da plataforma

- **Status:** aprovada (delegação das decisões técnicas por Gustavo em 2026-10-22; sem regra de negócio nova)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-001, PLT-002, PLT-009, PLT-011, PLT-012, FND-006
- **Responsável pela spec:** agente Produto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

A equipe da plataforma (super admin e suporte) só tem a API. Este item entrega o painel em `/admin` (domínio base) para entrar com senha e 2FA, acompanhar assinantes, agir sobre eles, gerir cupons, planos e leads.

## Páginas (domínio base, prefixo `/admin`)

| Rota | Quem | O que mostra |
| --- | --- | --- |
| `/admin/entrar` | público | Senha, depois o código do 2FA (ou a configuração obrigatória do 2FA) |
| `/admin` | equipe | Resumo: assinantes por situação, leads novos, cupons ativos |
| `/admin/assinantes` | equipe | Lista com busca e filtro de situação, "carregar mais" |
| `/admin/assinantes/{id}` | equipe | Detalhe: dona, plano, cartão (bandeira e final), cupom, faturas, funcionalidades; ações |
| `/admin/planos` | equipe | Planos e preços; super admin edita |
| `/admin/cupons` | equipe | Cupons; super admin cria e altera |
| `/admin/leads` | equipe | Leads com busca e filtro; muda status e notas; super admin apaga |

## Regras de negócio

- **RN-01** — Entrar: `POST /platform/auth/login`; resposta única de erro; 423 (bloqueio) e 429 com o tempo quando houver. A segunda etapa (`/platform/auth/2fa/verify`, código do app ou de recuperação) é obrigatória; quem ainda não tem 2FA é levada à configuração (`/platform/2fa/setup` e `/confirm`), com códigos de recuperação mostrados uma única vez. O token fica só em cookie `HttpOnly` da ponte.
- **RN-02** — Páginas `/admin/*` (menos `/admin/entrar`) exigem sessão (`GET /platform/me`); sem sessão válida vão a `/admin/entrar`. Quem já está logada e abre `/admin/entrar` vai a `/admin`. O retorno guardado é só um caminho `/admin/...` interno.
- **RN-03** — Papéis: `super_admin` vê e usa tudo; `suporte` lê tudo e pode entrar como assinante (acesso de suporte), mas não vê os botões de escrita (suspender, reativar, estender, exceções, planos, cupons, apagar lead). A API decide; a tela só reflete e mostra "sem permissão" se a API recusar (403).
- **RN-04** — Assinantes: lista (nome, endereço, plano, situação, próxima data) com busca `q` e filtro `status`; detalhe com dona (nome e e-mail), plano e ciclo, preço, cupom, pessoas ativas, cartão (só bandeira e final), faturas e funcionalidades efetivas (origem: exceção, plano ou padrão).
- **RN-05** — Ações no detalhe, cada uma com confirmação e botão ocupado: suspender (motivo de 5 a 200 caracteres), reativar, estender o teste (1 a 30 dias), definir/remover exceção de funcionalidade (liberada, limite, visível), entrar como assinante (motivo de 10 a 200 caracteres). Entrar como assinante abre o painel do negócio em nova aba em `/suporte#token=…` (o token nunca vai em query string nem fica na tela depois de aberto). Erros da API (409, 422) aparecem no diálogo.
- **RN-06** — Cupons: lista (código, percentual, situação, usos/limite, validade); criar (código, percentual, limite de usos obrigatório se 100%, validade, descrição) e ativar/desativar/alterar limites. Cupom de 100% (cortesia) mostra aviso.
- **RN-07** — Planos: lista (nome, chave, situação, dias de teste, preço mensal, desconto anual, preço anual); super admin edita nome, posição, situação, dias de teste, preço e desconto. Regras por funcionalidade de cada plano aparecem somente para leitura (edição das regras fica para uma entrega futura). A chave e a moeda não mudam.
- **RN-08** — Leads: lista com busca, filtro de situação e origem (UTM), "carregar mais"; abrir um lead mostra os dados e a mensagem; mudar status e notas (até 1000 caracteres); super admin apaga com confirmação.
- **RN-09** — Segurança: nada de senha, token ou código na URL, no armazenamento do navegador ou no console; o navegador só fala com `/bff/*`; dados de lead e de assinante só chegam a sessões da equipe (a API garante). Sair revoga o token (`/platform/auth/logout`).
- **RN-10** — Acessibilidade e celular: teclado completo, foco visível e levado aos erros, diálogos que prendem o foco, tabelas com legenda, layout a partir de 360 px sem rolagem horizontal da página, alvos de 44 px, sem violações no axe.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Credenciais certas; erradas; bloqueada; muitas tentativas | Entrar | Pede o código; mensagem única; aviso de bloqueio com o tempo; aviso de espera |
| CA-02 | RN-01 | Código certo; errado; de recuperação; conta sem 2FA | Segunda etapa | Entra em `/admin`; mensagem única; entra; configura o 2FA e mostra os códigos uma vez |
| CA-03 | RN-02 | Sem sessão; com sessão | Abrir `/admin/*` e `/admin/entrar` | Vai a `/admin/entrar` (retorno interno); vai a `/admin` |
| CA-04 | RN-03 | Super admin; suporte | Menu e botões | Suporte não vê escrita; 403 da API mostra "sem permissão" |
| CA-05 | RN-04 | Lista com dados | Buscar e filtrar | Chamadas com `q` e `status`; "carregar mais" por cursor; vazio e erro tratados |
| CA-06 | RN-04 | Detalhe | Abrir | Dados completos; cartão só bandeira e final; sem dado sensível extra |
| CA-07 | RN-05 | Super admin | Suspender, reativar, estender | Confirmação, motivo validado, resultado e erro da API na tela |
| CA-08 | RN-05 | Super admin | Exceção de funcionalidade | Define e remove; origem atualizada |
| CA-09 | RN-05 | Equipe | Entrar como assinante | Pede o motivo; abre o negócio em `/suporte#token=`; token fora da URL do painel e da página |
| CA-10 | RN-06 | Super admin | Criar e alterar cupom | Validações (100% exige limite); lista atualizada; aviso de cortesia |
| CA-11 | RN-07 | Super admin; suporte | Planos | Lista; edição só do super admin; erros da API |
| CA-12 | RN-08 | Equipe | Leads | Busca e filtros; status e notas salvos; apagar só super admin com confirmação |
| CA-13 | RN-09 | Qualquer tela | Usar | Nada sensível em URL, storage ou console; sair revoga |
| CA-14 | RN-10 | Todas as páginas | Axe e teclado | Sem violações; foco nos erros; diálogos prendem o foco |
| CA-15 | RN-01, RN-02 | Servidor | Sessão | A sessão da equipe é lida no servidor pelo cookie; 401 leva ao login |

## API

Usa as rotas existentes de `/platform/*`. Sem rota nova na API nem no BFF além da liberação do cabeçalho do acesso de suporte já existente.

## Fora do escopo

Edição das regras de funcionalidade por plano e de funcionalidades do catálogo; gestão de contas da própria equipe; relatórios financeiros; troca de plano (PLT-013).
