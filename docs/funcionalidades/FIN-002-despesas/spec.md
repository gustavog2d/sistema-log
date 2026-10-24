# FIN-002 · Despesas e categorias

- **Status:** aprovada (modelo de domínio e herança do Tatulog; decisões técnicas delegadas por Gustavo em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** FND-008
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Registrar o que o negócio gasta (aluguel, materiais, equipamentos, impostos...), agrupado por categoria, para que o resultado do mês (FIN-003) considere receitas e despesas.

## Regras de negócio

- **RN-01** — **Categoria de despesa:** `name` de 2 a 60 caracteres, único no negócio sem diferenciar maiúsculas nem acentos (o banco decide em uma corrida). Dá para criar, renomear e excluir.
- **RN-02** — **Categoria com despesas não é excluída:** 409 `category_in_use` com `count` (mova ou apague as despesas antes). A chave estrangeira composta com `RESTRICT` garante no banco.
- **RN-03** — **Categorias sugeridas:** `POST /expense-categories/defaults` cria as que faltam entre *Aluguel*, *Contas e serviços*, *Equipamentos*, *Impostos e taxas*, *Marketing*, *Materiais* e *Outros* (pelo nome, sem duplicar) e responde quantas criou e quantas já existiam. Repetir é seguro.
- **RN-04** — **Despesa:** `category_id` (obrigatório, do negócio), `description` (2 a 255, texto simples), `amount_cents` (inteiro de 1 a 100.000.000) e `spent_on` (data AAAA-MM-DD do dia do gasto, no calendário do negócio, entre 2000 e 2100; pode ser futura para contas já programadas). Dá para editar tudo e excluir.
- **RN-05** — **Listagem:** `GET /expenses` com `from` e `to` (datas, obrigatórias, período de até 366 dias, inclusive os dois dias); filtro `category_id`; `limit` de 1 a 500 (padrão 200); ordem do gasto mais recente para o mais antigo. A resposta traz `data` (cada despesa com a categoria resumida), `total_cents` e `count` do filtro inteiro (não só da página) e `by_category` (total por categoria do filtro, do maior para o menor).
- **RN-06** — **Quem acessa:** só dona e gerente (leitura e escrita); recepção e profissional recebem 403. O acesso de suporte só lê.
- **RN-07** — Toda criação, edição e exclusão (de despesa e de categoria) entra no histórico (FND-008) com antes e depois.
- **RN-08** — Tela `/despesas` (menu **Despesas**, dona e gerente): navegação por mês, total do mês e por categoria, lista, nova despesa, editar e excluir (com confirmação), gerenciar categorias (criar, renomear, excluir, criar sugeridas). Estados vazio, carregando e erro; erros por campo; pt-BR; acessível.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Nomes válidos, repetidos (com outra caixa e acento) e inválidos | Criar e renomear categoria | 201/200; 422 por campo; nome repetido recusado; renomear para o próprio nome vale |
| CA-02 | RN-02 | Categoria com e sem despesas | Excluir | 409 `category_in_use` com `count`; sem despesas 204; banco também recusa |
| CA-03 | RN-03 | Negócio sem categorias e com algumas | Criar sugeridas | Cria as que faltam; repetir cria zero; conta criadas e existentes |
| CA-04 | RN-04 | Dados válidos e inválidos | Criar e editar despesa | 201/200; 422 por campo (categoria de outro negócio, valor, data, descrição) |
| CA-05 | RN-05 | Várias despesas em datas e categorias diferentes | Listar | Período inclusive e limitado; filtro por categoria; ordem; `total_cents`, `count` e `by_category` do filtro inteiro |
| CA-06 | RN-04 | Despesa existente | Excluir | 204 |
| CA-07 | RN-06 | Quatro papéis e suporte | Qualquer rota | Dona e gerente tudo; recepção e profissional 403; suporte só lê |
| CA-08 | RN-06 | Assinatura suspensa e cancelada | Leitura e escrita | Leitura 200; escrita 403 com `code` |
| CA-09 | RN-07 | Criar, editar, excluir | Histórico | Registros com antes e depois |
| CA-10 | RN-01 | Dois negócios | Qualquer rota | Cada um vê e altera só o seu; id de outro dá 404; nomes iguais em negócios diferentes valem |
| CA-11 | RN-08 | Tela | Mês, totais, nova, editar, excluir, categorias | Comportamento e mensagens certos; sem violações no axe |
| CA-12 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/expense-categories` | Lista (com `expenses_count`) | dona, gerente |
| POST | `/expense-categories` | Cria | dona, gerente |
| POST | `/expense-categories/defaults` | Cria as sugeridas | dona, gerente |
| PATCH | `/expense-categories/{id}` | Renomeia | dona, gerente |
| DELETE | `/expense-categories/{id}` | Exclui | dona, gerente |
| GET | `/expenses` | Lista por período, com totais | dona, gerente |
| POST | `/expenses` | Cria | dona, gerente |
| GET | `/expenses/{id}` | Detalhe | dona, gerente |
| PATCH | `/expenses/{id}` | Edita | dona, gerente |
| DELETE | `/expenses/{id}` | Exclui | dona, gerente |

## Dados

`expense_categories` (nome único por negócio pela coluna gerada `name_key`) e `expenses` com chave estrangeira composta `(tenant_id, category_id)` `ON DELETE RESTRICT`, ambas com RLS.

## Fora do escopo

Despesa recorrente, anexo de comprovante, rateio entre profissionais, fornecedores, pagamento parcial de despesa, importação de extrato, receita e dashboard (FIN-003).
