# FIN-003 · Dashboard financeiro

- **Status:** aprovada (formas de receita decididas por Gustavo em 2026-10-23; decisões técnicas delegadas em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** FIN-001, FIN-002
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Mostrar à dona e ao gerente como o negócio está indo: quanto entrou, quanto está previsto, quanto falta receber, quanto sobra depois da comissão e das despesas, a meta do mês, os últimos 12 meses e o calendário do mês.

## Regras de negócio

- **RN-01** — **Períodos e fuso:** todo período é de datas AAAA-MM-DD **inclusive** os dois dias, no calendário do negócio (hoje fuso de Brasília). Um pagamento ou agendamento pertence ao dia local do seu instante (um pagamento às 23:30 de 30/11 em Brasília é de novembro, mesmo sendo dezembro em UTC).
- **RN-02** — **Receita recebida (no momento):** soma dos pagamentos `received` cujo `paid_at` cai no período, de agendamentos que não estão cancelados nem com falta. Estornado não conta.
- **RN-03** — **Receita prevista:** soma do `value_total_cents` dos agendamentos `pending`, `scheduled` e `completed` cujo `starts_at` cai no período (cancelado e falta ficam de fora; sem valor conta zero). **A receber** = prevista − tudo o que já foi recebido desses mesmos agendamentos, mesmo que o pagamento tenha sido em outro mês (um sinal pago em outubro abate a previsão de novembro). Nunca negativo.
- **RN-04** — **Líquida:** recebida − comissão sobre o que foi recebido (cada pagamento paga o percentual congelado do seu agendamento, FIN-001). **Prevista líquida:** prevista − comissão do valor combinado (percentual do agendamento).
- **RN-05** — **Despesas e resultado:** despesas do período (FIN-002) e `result_cents` = líquida recebida − despesas (pode ser negativo).
- **RN-06** — **Resumo:** `GET /finance/summary?from=&to=` (período de até 366 dias) devolve `received_cents`, `expected_cents`, `to_receive_cents`, `commission_received_cents`, `net_received_cents`, `commission_expected_cents`, `net_expected_cents`, `expenses_cents`, `result_cents`, `appointments_count` (agendamentos previstos) e `by_method` (recebido por forma de pagamento, do maior para o menor).
- **RN-07** — **Meta do mês:** `GET /finance/goal` devolve `monthly_goal_cents` (nulo se não definida) e `PUT /finance/goal` define (inteiro de 1 a 1.000.000.000) ou limpa (`null`). A mesma meta vale para todos os meses. A tela mostra o recebido do mês contra a meta, com o percentual (pode passar de 100%). A mudança entra no histórico.
- **RN-08** — **Balanço de 12 meses:** `GET /finance/monthly?to=AAAA-MM` (padrão: o mês atual) devolve os 12 meses que terminam em `to`, do mais antigo ao mais novo, mesmo os sem movimento, cada um com `month`, `received_cents`, `net_received_cents`, `expected_cents`, `expenses_cents` e `result_cents`, e os totais do ano.
- **RN-09** — **Calendário:** `GET /finance/calendar?month=AAAA-MM` devolve todos os dias do mês com `date`, `received_cents`, `expected_cents`, `appointments_count` e `expenses_cents`.
- **RN-10** — **Quem acessa:** só dona e gerente (leitura); a meta só se altera por dona e gerente; o acesso de suporte só lê; recepção e profissional recebem 403. Assinatura suspensa ou cancelada: leitura vale, a gravação da meta não.
- **RN-11** — Tela `/financeiro` (menu **Financeiro**, dona e gerente): navegação por mês; cartões de recebida, prevista, a receber, líquida e despesas, e o resultado; meta do mês com barra de progresso e edição; recebido por forma de pagamento; balanço dos 12 meses (tabela com barras); calendário do mês. Estados vazio, carregando e erro; pt-BR; acessível (a informação nunca depende só da cor).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-02 | Pagamentos recebidos, estornados, em outro mês e na virada do mês (fuso) | Resumo | Só os recebidos do período, no dia local |
| CA-02 | RN-03 | Agendamentos ativos, cancelados, com falta, sem valor e com sinal de outro mês | Resumo | Prevista e a receber certas; cancelado e falta fora; a receber nunca negativa |
| CA-03 | RN-04 | Comissões diferentes | Resumo | Líquida e prevista líquida pelo percentual congelado |
| CA-04 | RN-05 | Despesas no período | Resumo | `expenses_cents` e `result_cents` (inclusive negativo) |
| CA-05 | RN-06 | Pagamentos de várias formas | Resumo | `by_method` ordenado |
| CA-06 | RN-06 | Períodos válidos e inválidos | Resumo | 422 por campo para ausente, invertido, maior que 366 dias; vazio devolve zeros |
| CA-07 | RN-07 | Sem meta, com meta, limpar, inválida | Meta | GET nulo; PUT grava; null limpa; 422 fora de 1 a 1.000.000.000; histórico |
| CA-08 | RN-08 | Movimento em alguns meses | Balanço | 12 meses ordenados, zerados incluídos, totais certos, `to` respeitado |
| CA-09 | RN-09 | Movimento em alguns dias | Calendário | Todos os dias do mês com os valores do dia local |
| CA-10 | RN-10 | Quatro papéis e suporte | Todas as rotas | Dona e gerente; recepção e profissional 403; suporte só lê (meta 403) |
| CA-11 | RN-10 | Assinatura suspensa e cancelada | Leitura e meta | Leitura 200; meta 403 com `code` |
| CA-12 | RN-01 | Dois negócios | Todas as rotas | Cada um só enxerga os próprios números |
| CA-13 | RN-11 | Tela | Mês, cartões, meta, balanço, calendário, erros | Comportamento e mensagens certos; sem violações no axe |
| CA-14 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/finance/summary` | Resumo do período | dona, gerente |
| GET | `/finance/monthly` | Balanço dos 12 meses | dona, gerente |
| GET | `/finance/calendar` | Calendário do mês | dona, gerente |
| GET | `/finance/goal` | Meta mensal | dona, gerente |
| PUT | `/finance/goal` | Define ou limpa a meta | dona, gerente |

## Dados

Sem tabelas de resultado: tudo é calculado na hora a partir de `payments`, `appointments` e `expenses`. A meta fica em `tenant_settings` (chave `monthly_goal_cents`, única por negócio, com RLS).

## Fora do escopo

Gráficos interativos, exportação de relatório, comparação com o ano anterior, meta por mês ou por profissional, visão do profissional sobre as próprias comissões, imposto sobre a receita, nota fiscal.
