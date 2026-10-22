# PLT-004 · Cobrança do fim do teste e renovações (com meio de pagamento de teste)

- **Status:** aprovada (regras de negócio de Gustavo em 2026-10-22; decisões técnicas delegadas)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-003
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

O teste de 7 dias já começa com o pagamento cadastrado, mas ninguém cobra. Esta funcionalidade cobra a assinatura quando o teste acaba e a cada renovação (mensal ou anual) e, se o pagamento falhar, **suspende o acesso na mesma hora** (sem carência). Tudo roda com o meio de pagamento de teste (`fake`); o gateway real entra por último atrás da mesma interface.

## Histórias de usuário

- Como assinante, quero que a cobrança aconteça só no 7º dia, para testar sem pagar antes.
- Como Gustavo, quero que quem não pagou perca o acesso imediatamente.
- Como Gustavo, quero poder simular a passagem do tempo para validar o fluxo sem esperar 7 dias.

## Regras de negócio

- **RN-01** — Uma assinatura está **vencida** quando é `trialing` com `trial_ends_at` no passado, ou `active` com `current_period_end` no passado.
- **RN-02** — `php artisan billing:run [--as-of=DATA]` processa as assinaturas vencidas (a data padrão é agora; `--as-of` permite simular o tempo). Roda sozinho a cada 5 minutos, sem sobreposição. Cada assinatura é processada em sua própria transação, e o erro de uma não impede as demais.
- **RN-03** — Cobrança: valor = `price_cents` gravado na assinatura (preço do cadastro, nunca o atual do plano), pelo meio de pagamento da assinatura, com chave de idempotência determinística derivada do **vencimento original** (assinatura + data do vencimento, que não muda mesmo com atraso longo). Rodar duas vezes, ou repetir depois de uma falha que ocorreu após a cobrança, nunca cobra duas vezes.
- **RN-04** — Cobrança aprovada: assinatura `active`; o período começa no vencimento (não em "agora") e dura 1 mês ou 1 ano conforme o ciclo. **O dia da cobrança é o do fim do teste e é preservado**: um mês curto não muda o dia para sempre (31/01 → 28/02 → 31/03 → 30/04; 29/02 → 28/02 no ano seguinte). Se o atraso for de um ciclo inteiro ou mais (sistema parado), o período começa na data da execução, e não cobra períodos antigos em sequência.
- **RN-05** — Cobrança recusada: assinatura `suspended` na hora, com `suspended_at`; a fatura fica `failed` com o motivo. **Não há carência nem nova tentativa automática.** Sem meio de pagamento, também suspende (`no_payment_method`).
- **RN-06** — Falha técnica (gateway indisponível, exceção) **não** muda o estado: a assinatura continua vencida e será tentada na próxima execução.
- **RN-07** — Preço zero (cortesia, PLT-012): não chama o gateway; a fatura nasce `paid` com valor 0 e a assinatura segue `active`.
- **RN-08** — `invoices` (dado de assinante, com RLS): assinatura, período, valor, moeda, status (`paid`, `failed`), motivo, referência da cobrança, datas. Uma fatura por assinatura e início de período. O papel de plataforma grava (informando o tenant na transação); o papel da API só lê, e só as do tenant em contexto.
- **RN-09** — Auditoria (`billing.charged`, `billing.failed`, `subscription.suspended`), gravada depois de cada transação, sem dados de pagamento.
- **RN-11** — `--as-of` só é aceito nos ambientes `local` e `testing` (simular o tempo em produção cobraria assinaturas antes da hora). A data é convertida para UTC (um `--as-of` com deslocamento, como `-03:00`, vale o mesmo instante).
- **RN-12** — Erro técnico nunca passa em silêncio: gera o evento de auditoria `billing.error` e o comando termina com código de saída diferente de zero. Os contadores do resumo só contam o que foi confirmado (depois do commit).
- **RN-13** — Duas execuções simultâneas nunca processam a mesma assinatura (a linha é travada; quem chega depois a pula). `subscriptions` exige data de fim do teste (`trialing`) ou de fim do período (`active`) no banco, e o papel de plataforma não altera nem apaga faturas.
- **RN-10** — Assinaturas `suspended` ou `canceled` nunca são processadas. Regularizar uma suspensão (novo meio de pagamento) é do PLT-007.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-03, RN-04 | Teste vencido, cartão aprovado | `billing:run` | Assinatura `active`; período de 1 mês começando no fim do teste; fatura `paid` com o preço do cadastro; uma cobrança |
| CA-02 | RN-04 | Ciclo anual | `billing:run` | Período de 1 ano |
| CA-03 | RN-01 | Teste ainda não vencido | `billing:run` | Nada é cobrado nem alterado |
| CA-04 | RN-05 | Cartão que falha | `billing:run` | Assinatura `suspended` na hora, fatura `failed` com motivo, `suspended_at` preenchido |
| CA-05 | RN-03 | Executar duas vezes | `billing:run` ×2 | Uma fatura e uma cobrança |
| CA-06 | RN-04 | Assinatura ativa com período vencido | `billing:run` | Nova fatura; período seguinte contínuo |
| CA-07 | RN-04 | Atraso de mais de um ciclo | `billing:run` | Cobra um período só, a partir da data da execução |
| CA-08 | RN-04 | Início em 31/01, ciclo mensal | `billing:run` | Fim em 28/02 (ou 29) |
| CA-09 | RN-07 | Preço zero | `billing:run` | Sem chamada ao gateway; fatura `paid` de 0; `active` |
| CA-10 | RN-08 | Duas assinaturas faturadas | Papel da API no tenant A | Só vê as faturas de A; sem contexto, nenhuma |
| CA-11 | RN-10 | Assinatura `suspended` ou `canceled` vencida | `billing:run` | Ignorada |
| CA-12 | RN-06 | Gateway lança exceção para uma assinatura | `billing:run` | Estado preservado; as outras são processadas; erro contado |
| CA-13 | RN-05 | Assinatura sem meio de pagamento | `billing:run` | `suspended` com `no_payment_method` |
| CA-14 | RN-09 | Cobrança aprovada, recusada | Auditoria | Eventos registrados, sem dados de pagamento |
| CA-15 | RN-02 | `--as-of` com data válida e inválida | Comando | Simula o tempo; data inválida falha com mensagem |
| CA-16 | RN-08 | Papel da API | Escrever em `invoices` | Negado pelo banco |
| CA-17 | RN-02 | Agendador | Lista de tarefas | `billing:run` a cada 5 minutos, sem sobreposição |

## Permissões

Sem rotas novas: o comando roda no servidor.

## API

Nenhuma rota nova nesta funcionalidade (a visão do assinante é o PLT-005).

## Dados

- Nova tabela `invoices` (com `tenant_id`, RLS e FORCE); `subscriptions` ganha `suspended_at`.
- O papel `app_platform` ganha acesso a `invoices`; o papel da API só lê.
- Migration compatível com a versão anterior: sim.

## Eventos, auditoria e notificações

Auditoria (RN-09). E-mails de cobrança entram no PLT-010.

## Impacto no mobile

Nenhum.

## Fora do escopo

Webhooks e conciliação com o gateway real, nota fiscal, e-mails, regularização de suspensão e cancelamento (PLT-007), cupons (PLT-012), tela.

## Riscos e perguntas em aberto

- A cobrança acontece dentro de uma transação com a linha da assinatura travada; com o gateway real, trocar por reserva da fatura antes de chamar (a chave determinística já protege contra cobrança dupla).
