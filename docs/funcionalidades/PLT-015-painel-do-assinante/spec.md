# PLT-015 · Painel web do assinante

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22 e delegação das decisões técnicas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** FND-006, PLT-006, PLT-007, PLT-008, PLT-014
- **Responsável pela spec:** agente Produto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Depois de criar a conta, a dona precisa entrar no painel do negócio (no endereço `{negócio}.{domínio}`), ver como está a assinatura, cuidar do pagamento, convidar a equipe e proteger a conta. Este item entrega as telas que usam a API de autenticação, assinatura e equipe já prontas.

## Páginas (endereço do negócio)

| Rota | Quem | O que mostra |
| --- | --- | --- |
| `/entrar` | público | Login (e-mail e senha), segunda etapa do 2FA, links de recuperação |
| `/esqueci-senha`, `/redefinir-senha` | público | Pedir o link e definir a nova senha |
| `/aceitar-convite` | público | Aceitar o convite (senha e termos) |
| `/` | logada | Início: boas-vindas, situação da assinatura, atalhos |
| `/assinatura` | dona | Situação, meio de pagamento, trocar/regularizar, cancelar e desfazer, faturas |
| `/equipe` | dona e gerente | Pessoas e convites: convidar, trocar papel, desativar, reativar, cancelar convite |
| `/conta` | todas | Dados da pessoa, trocar senha, 2FA (ligar e desligar), sair e sair de todos os acessos |

## Regras de negócio

- **RN-01** — Entrar: `POST /auth/login`. A resposta é a mesma para qualquer falha ("e-mail ou senha incorretos"); conta bloqueada (423) mostra o aviso de bloqueio com o tempo; 429 pede para tentar mais tarde. Com 2FA, abre a segunda etapa (código do app ou de recuperação, `POST /auth/2fa/verify`); dona que precisa configurar o 2FA (se a configuração exigir) vai à tela de configuração. Sucesso leva a `/`. O token fica só no cookie `HttpOnly` da ponte; a tela nunca o vê.
- **RN-02** — Páginas logadas (`/`, `/assinatura`, `/equipe`, `/conta`) exigem sessão: sem sessão válida, vão a `/entrar` (o endereço pedido é guardado só se for um caminho interno do painel). Quem já está logada e abre `/entrar` vai a `/`.
- **RN-03** — O menu mostra só o que o papel pode usar (dona: tudo; gerente: início, equipe, conta; profissional e recepção: início e conta). Abrir por link uma página que o papel não tem mostra "sem permissão" (a API também recusa). A tela só reflete o que a API decide.
- **RN-04** — Situação da assinatura: faixa no topo de toda página logada quando está suspensa ("só leitura até regularizar", com atalho para a dona), cancelada, ou com cancelamento pedido ("acesso até {data}"). Em teste, o início mostra os dias que faltam e a data da primeira cobrança. Suspensa ou cancelada: botões de escrita da equipe e do resto ficam desabilitados com a explicação; `/assinatura` e `/conta` continuam funcionando para regularizar.
- **RN-05** — `/assinatura` (dona): mostra plano, ciclo, preço, próxima cobrança, meio de pagamento (bandeira e final). Trocar o meio de pagamento (cartão de teste até o INT-001) com `Idempotency-Key`; em assinatura suspensa a troca já cobra e regulariza (mensagens: regularizada, ou "cartão recusado, tente outro", ou "suspensa pela equipe, fale com o suporte"). Cancelar abre confirmação que explica a regra ("acesso até {data}, nada é cobrado depois; durante o teste não há cobrança"); desfazer o cancelamento enquanto pendente. Faturas em tabela (período, valor, situação, data) com "carregar mais" por cursor.
- **RN-06** — `/equipe` (dona e gerente): lista pessoas (nome, e-mail, papel, situação) e convites pendentes (com validade). Convidar abre diálogo (nome, e-mail, papel; a gerente não oferece "gerente" para si... a API decide, a tela mostra o erro). Trocar papel e desativar/reativar com confirmação; a dona nunca aparece com ações; ninguém age sobre si mesmo. Limite do plano (409 `plan_limit_reached`) mostra "limite de usuários do plano" com a saída (falar com a equipe/trocar de plano). Cancelar convite.
- **RN-07** — `/conta`: nome, e-mail e papel; trocar senha (senha atual, nova com a mesma regra do cadastro; a sessão continua); 2FA: ligar (mostra o segredo e o endereço `otpauth` para o aplicativo, pede o primeiro código, mostra os códigos de recuperação uma única vez com aviso para guardar) e desligar (pede a senha); "Sair" e "Sair de todos os acessos".
- **RN-08** — Recuperar senha: `/esqueci-senha` pede o e-mail e sempre mostra "se o e-mail existir, enviamos o link"; `/redefinir-senha?token=&email=` define a nova senha (regra do cadastro) e leva ao login; link inválido ou vencido mostra a mensagem única e o caminho para pedir outro.
- **RN-09** — Aceitar convite: `/aceitar-convite?token=&email=` pede a senha (quem já tem conta informa a senha dela; a tela explica), o aceite dos termos e entra (ou abre a etapa do 2FA). Convite inválido ou vencido: mensagem única. O token e o e-mail da URL somem da barra de endereço depois de lidos (`history.replaceState`).
- **RN-10** — Acesso de suporte: `/suporte` recebe o token no fragmento da URL (`#token=…`, que não vai ao servidor), o entrega à ponte (`POST /bff/session/adopt`), apaga o fragmento e abre o painel com uma faixa fixa "Você está em um acesso de suporte (30 min)"; nessas sessões as telas de senha, 2FA e assinatura mostram que não estão disponíveis.
- **RN-11** — Segurança da tela: nada de senha, token ou código na URL, no armazenamento do navegador ou no console; campos de senha com `autocomplete` correto; envios que mudam dinheiro ou acesso usam botão ocupado e desabilitado (sem clique duplo) e `Idempotency-Key` quando a API aceita; o servidor do web nunca repassa dados de uma sessão para outra.
- **RN-12** — Acessibilidade e celular: teclado completo, foco visível, erros ligados aos campos e levados ao foco, diálogos que prendem o foco, tabelas com legenda, layout a partir de 360 px sem rolagem horizontal da página, alvos de 44 px.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Credenciais certas; erradas; conta bloqueada; muitas tentativas | Entrar | Início; mensagem única; aviso de bloqueio; aviso de espera |
| CA-02 | RN-01 | Conta com 2FA | Entrar | Pede o código; código certo entra; errado dá a mesma mensagem; recuperação funciona |
| CA-03 | RN-02 | Sem sessão; com sessão | Abrir páginas logadas e `/entrar` | Vai a `/entrar` (com retorno interno); vai a `/` |
| CA-04 | RN-03 | Cada papel | Menu e páginas | Só os itens do papel; "sem permissão" no link direto |
| CA-05 | RN-04 | Suspensa, cancelada, cancelamento pendente, em teste | Qualquer página | Faixa e explicação certas; escrita desabilitada quando suspensa/cancelada |
| CA-06 | RN-05 | Dona | Ver a assinatura | Plano, preço, próxima cobrança, cartão (só bandeira e final), faturas com "carregar mais" |
| CA-07 | RN-05 | Dona; cartão aprovado e recusado; suspensa | Trocar o meio de pagamento | Atualiza; erro no passo; regulariza e a faixa some; suspensa pela equipe mostra o aviso |
| CA-08 | RN-05 | Dona | Cancelar e desfazer | Confirmação com a regra e a data; estado atualizado |
| CA-09 | RN-06 | Dona e gerente | Equipe | Lista; convidar; trocar papel; desativar/reativar; cancelar convite; erros da API na tela |
| CA-10 | RN-06 | Limite do plano | Convidar | Mensagem do limite |
| CA-11 | RN-07 | Pessoa logada | Trocar senha; ligar e desligar o 2FA; sair; sair de todos | Resultados e mensagens certos; códigos de recuperação mostrados uma vez |
| CA-12 | RN-08 | Pedido de link; link bom, ruim e vencido | Recuperar senha | Mensagem sempre igual; redefine; mensagem única de erro |
| CA-13 | RN-09 | Convite bom, ruim; conta nova e existente | Aceitar | Entra; mensagem única; pede a senha certa; URL limpa |
| CA-14 | RN-10 | Fragmento `#token=` | `/suporte` | Token vai à ponte, fragmento some, faixa de suporte; telas sensíveis bloqueadas |
| CA-15 | RN-11 | Qualquer tela com senha | Usar | Nada em URL, storage ou console; botões ocupados |
| CA-16 | RN-12 | Todas as páginas | Axe e teclado | Sem violações; foco nos erros; diálogos prendem o foco |
| CA-17 | RN-01..RN-03 | Servidor | Sessão | A sessão é lida no servidor com o endereço do negócio; 401 limpa a sessão e leva ao login |
| CA-18 | RN-10 | Ponte | `POST /bff/session/adopt` | Só no endereço do negócio, só token válido (conferido com a API), cookie `HttpOnly`; sem corpo do token na resposta |

## API

Usa as rotas existentes (PLT-006, PLT-007, PLT-008). Novidade só no web: `POST /bff/session/adopt` (ponte), sem rota nova na API.

## Fora do escopo

Agenda, clientes, orçamento e financeiro (Fase 2 em diante); troca de plano (PLT-013); tela de preferências e notificações; QR code do 2FA (o app aceita o segredo digitado).
