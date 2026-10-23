# PLT-014 · Site público: landing, contato e cadastro do assinante

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22 e delegação das decisões técnicas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** FND-006, PLT-003, PLT-011, PLT-012
- **Responsável pela spec:** agente Produto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Quem chega ao endereço principal precisa entender o produto, conhecer os planos, falar com a gente e, principalmente, **criar a conta e começar os 7 dias de teste** sem ajuda. Este item entrega as páginas públicas que usam a API já pronta (planos, contato, cadastro, cupom).

## Páginas (domínio base)

| Rota | O que mostra |
| --- | --- |
| `/` | Landing: promessa, nichos, o que o sistema faz, planos com preço mensal e anual, como funciona o teste, formulário de contato e chamada para o cadastro |
| `/cadastro` | Cadastro em 4 passos: negócio, você, plano, pagamento e termos |
| `/cadastro/pronto` | Confirmação com o endereço do negócio e o próximo passo |
| `/entrar` | Campo do endereço do negócio que leva ao login dele |
| `/termos` e `/privacidade` | Textos provisórios (o jurídico define) |

## Regras de negócio

- **RN-01** — Os planos e preços vêm da API (`GET /plans`), nunca fixos na página. Mostra preço mensal, preço anual com o desconto e a economia; o plano do meio (mais completo para quem está começando) pode ser destacado por configuração do catálogo, não por regra no código. Se a API estiver fora, a página mostra um aviso e o resto da landing continua.
- **RN-02** — Nada de nome, domínio ou cor fixos: o nome do produto vem de configuração e todo texto vem das traduções.
- **RN-03** — Contato (`POST /leads`): nome, e-mail, telefone (opcional, formato E.164 com ajuda para digitar), nicho (opcional), mensagem (opcional), aceite da política de privacidade e o anti-robô. O campo isca (`company_site`) fica escondido para pessoas (fora da navegação por teclado e de leitor de tela). Guarda a origem: `utm_*`, `referrer` e `landing_path` lidos da página (a sessão do visitante guarda as UTM da primeira visita). Resposta 202 mostra "recebemos seu contato" sem diferenciar lead repetido. Erros de campo aparecem no campo; 429 pede para tentar mais tarde.
- **RN-04** — Anti-robô: em desenvolvimento um token de teste (`NEXT_PUBLIC_CAPTCHA_MODE=fake`); em produção o reCAPTCHA v3 do Google com `NEXT_PUBLIC_RECAPTCHA_SITE_KEY` (a chave pública fica em configuração). Sem chave configurada o formulário não envia e avisa.
- **RN-05** — Cadastro (`POST /signup`, com `Idempotency-Key`): passo 1 nome do negócio, endereço (`slug`, sugerido a partir do nome, conferido em `GET /signup/slug-availability` enquanto a pessoa digita, com resposta rápida e sem travar a digitação) e nicho; passo 2 nome, e-mail e senha (mínimo de 12 caracteres, com indicação do que falta); passo 3 plano e ciclo (mensal/anual) e cupom opcional (`POST /signup/coupon-check` mostra o desconto e o preço final, ou a mesma mensagem para qualquer cupom indisponível); passo 4 meio de pagamento e aceite dos termos. Cada passo valida na tela antes de seguir e o botão voltar não perde o que foi digitado. A senha nunca vai para a URL, o armazenamento do navegador nem o log.
- **RN-06** — Meio de pagamento de teste (até o gateway real, INT-001): com `NEXT_PUBLIC_PAYMENT_MODE=fake` a tela oferece "Cartão de teste aprovado" (`tok_ok`), "Cartão de teste que recusa na cobrança" (`tok_fail_on_charge`) e "Cartão de teste recusado agora" (`tok_declined`), com aviso de que é ambiente de teste. Com o cupom de 100% (cortesia) o passo some e o resumo diz que não há cobrança. A tela nunca pede nem guarda número de cartão.
- **RN-07** — O resumo do passo final mostra plano, ciclo, preço (com o desconto do cupom) e a regra do teste: "7 dias grátis; a primeira cobrança é no fim do teste; cancele quando quiser antes disso sem custo" (os dias vêm do plano).
- **RN-08** — Idempotência: o mesmo envio repetido (clique duplo, rede lenta) usa a mesma chave e não cria dois negócios; mudar os dados gera uma chave nova. Enquanto envia, o botão fica ocupado e desabilitado.
- **RN-09** — Erros do cadastro (422) voltam ao passo do campo com a mensagem; recusa do cartão aparece no passo de pagamento; 429 e falha de rede mostram aviso para tentar de novo, mantendo o que foi digitado.
- **RN-10** — Sucesso: `/cadastro/pronto` mostra o endereço do negócio (`{slug}.{domínio}`) com link, o fim do teste e que um e-mail de boas-vindas foi enviado. O endereço usa o mesmo domínio e porta da página atual.
- **RN-11** — `/entrar`: a pessoa digita o endereço do negócio (só o nome, sem o domínio); o formato é conferido (a mesma regra do cadastro) e ela vai para `{slug}.{domínio}/entrar`. Endereço inválido mostra erro no campo.
- **RN-12** — Acessibilidade e celular: tudo funciona só com teclado, com foco visível e rótulos ligados; o cadastro indica o passo atual (`aria-current="step"`) e move o foco para o título do passo ao avançar; o layout funciona a partir de 360 px de largura, sem rolagem horizontal; alvos de toque de 44 px.
- **RN-13** — Os textos de `/termos` e `/privacidade` são provisórios e dizem isso; ficam em tradução para o jurídico substituir.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Planos da API | Landing | Preços mensal e anual, desconto e economia vêm dos dados; API fora mostra aviso |
| CA-02 | RN-03 | Formulário de contato válido | Enviar | `POST /leads` com os campos, UTM, `referrer`, `landing_path` e o token do anti-robô; mensagem de sucesso |
| CA-03 | RN-03 | Campo isca | Renderizar | Fora do teclado e do leitor de tela, vazio por padrão |
| CA-04 | RN-03, RN-09 | 422 e 429 | Enviar contato | Erro no campo; aviso de tentar mais tarde; dados mantidos |
| CA-05 | RN-04 | Sem chave do reCAPTCHA em modo real | Enviar | Não envia e avisa |
| CA-06 | RN-05 | Passo 1 | Digitar nome e endereço | Endereço sugerido; disponibilidade conferida com atraso; mensagens de livre/ocupado/inválido |
| CA-07 | RN-05 | Passo 2 | Senha curta | Indica o que falta e não avança |
| CA-08 | RN-05, RN-07 | Passo 3 | Escolher plano, ciclo e cupom | Preço e desconto na tela; cupom indisponível com mensagem única |
| CA-09 | RN-06 | Passo 4 em modo teste | Escolher o cartão de teste | Token certo enviado; aviso de teste |
| CA-10 | RN-06 | Cupom de 100% | Passo 4 | Sem pagamento; resumo sem cobrança; envia sem token |
| CA-11 | RN-08 | Envio repetido | Clique duplo e novo envio igual | Mesma `Idempotency-Key`; muda ao alterar os dados |
| CA-12 | RN-09 | 422 em campo de outro passo; cartão recusado | Enviar | Volta ao passo com a mensagem; dados mantidos |
| CA-13 | RN-10 | Sucesso | Concluir | Vai a `/cadastro/pronto` com o endereço e o fim do teste |
| CA-14 | RN-11 | Endereço válido e inválido | `/entrar` | Redireciona para o endereço do negócio; erro no campo |
| CA-15 | RN-12 | Todas as páginas e passos | Axe e teclado | Sem violações; foco no título do passo; `aria-current="step"` |
| CA-16 | RN-05 | Senha digitada | Qualquer passo | Nunca em URL, `localStorage`, `sessionStorage` nem log |
| CA-17 | RN-02, RN-13 | Todas as páginas | Varredura | Sem texto fixo fora das traduções |

## Fora do escopo

Blog, páginas por nicho, depoimentos, comparativo com concorrentes, SEO avançado (sitemap e dados estruturados), tokenização real do cartão (INT-001) e o painel do assinante (PLT-015).
