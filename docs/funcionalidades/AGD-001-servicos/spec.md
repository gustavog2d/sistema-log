# AGD-001 · Serviços e categorias

- **Status:** aprovada (modelo de domínio e herança do Tatulog; decisões técnicas delegadas por Gustavo em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** FND-008
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

O negócio precisa de um catálogo do que oferece (um trabalho de tatuagem, uma manicure, um corte, uma polidora) com duração, preço e regras, para montar a agenda e os orçamentos sem digitar tudo de novo. As categorias organizam o catálogo, e um **preset de nicho** cria os primeiros serviços do negócio num clique.

## Regras de negócio

- **RN-01** — Serviço: `name` (obrigatório, 2 a 100, sem endereços de internet nem de e-mail, único no negócio sem diferenciar maiúsculas e acentos), `description` (opcional, até 500), `category_id` (opcional), `duration_minutes` (obrigatório, inteiro de 5 a 720, múltiplo de 5), `price_cents` (opcional, 0 a 100.000.000; **vazio significa "sob orçamento"**), `buffer_before_minutes` e `buffer_after_minutes` (intervalo antes e depois, 0 a 240, múltiplos de 5, padrão 0), `requires_deposit` (exige sinal, padrão falso), `status` (`active` ou `archived`, padrão `active`).
- **RN-02** — Categoria: `name` (obrigatório, 2 a 60, único no negócio sem diferenciar maiúsculas e acentos). Listada em ordem alfabética.
- **RN-03** — Quem usa: todos os papéis **leem** serviços e categorias; só dona e gerente **criam, editam, arquivam e excluem**. O acesso de suporte só lê; assinatura suspensa ou cancelada = somente leitura.
- **RN-04** — `GET /services` lista (ordem alfabética sem acento; paginação por cursor, `limit` de 1 a 50, padrão 20), com filtros `status` (padrão: só ativos; `all` mostra tudo), `category_id` e busca `q` (2 a 100) pelo nome. `GET /service-categories` lista todas (sem paginação: poucas) com a contagem de serviços.
- **RN-05** — **Arquivar** (`status: archived`) tira o serviço das novas agendas e orçamentos mas mantém o histórico; **excluir** só é permitido se o serviço nunca foi usado (a regra "usado" passa a valer com a agenda; hoje a exclusão sempre é permitida). Serviço usado e excluído responde 409 `service_in_use` com a dica de arquivar.
- **RN-06** — Categoria **com serviços não pode ser excluída** (409 `category_in_use`, com a contagem); o dono move ou apaga os serviços antes. (Correção da lacuna do Tatulog, que apagava as despesas junto.)
- **RN-07** — **Preset de nicho:** `POST /services/presets` (dona e gerente) cria os serviços e categorias iniciais do nicho do negócio (`tatuagem`, `manicure`, `barbearia`, `estetica_automotiva`), pulando os que já existem pelo nome. Resposta com o que foi criado e o que foi pulado. Preço dos presets fica vazio ("sob orçamento"), para a dona preencher; as durações são sugestões.
- **RN-08** — Toda criação, edição e exclusão de serviço e de categoria entra no histórico (FND-008).
- **RN-09** — Tela `/servicos` (todos veem; dona e gerente editam): lista de serviços com filtro de categoria e de arquivados, cadastro e edição em diálogo (duração em minutos com atalhos, preço em reais ou "sob orçamento", intervalos, sinal), arquivar/reativar, exclusão com confirmação, gestão de categorias em diálogo e botão do preset quando não há serviços. Estados vazio, carregando e erro; erros por campo; pt-BR.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Dados válidos e inválidos | `POST /services` | 201 com os padrões; 422 por campo (nome, duração fora de 5-720 ou não múltiplo de 5, preço negativo, intervalos, categoria de outro negócio) |
| CA-02 | RN-01, RN-02 | Nome repetido com outra grafia, no negócio e em outro | Criar e editar serviço e categoria | 422 no negócio; aceito em outro negócio; não colide consigo mesmo |
| CA-03 | RN-03 | Quatro papéis; suporte; suspensa | Rotas | Todos leem; só dona e gerente escrevem; suporte só lê; 403 com `code` em suspensa |
| CA-04 | RN-04 | Vários serviços e categorias | Listar | Ordem, filtros, busca, cursor, `limit`, contagem por categoria |
| CA-05 | RN-05 | Serviço | Arquivar, reativar, excluir | Status muda e vai ao histórico; arquivado some da lista padrão; excluir remove |
| CA-06 | RN-06 | Categoria com e sem serviços | Excluir | 409 `category_in_use` com a contagem; sem serviços exclui |
| CA-07 | RN-07 | Negócio de cada nicho; preset repetido | `POST /services/presets` | Cria os serviços e categorias do nicho sem preço; a segunda chamada pula tudo; nicho sem preset responde 422 |
| CA-08 | RN-08 | Criar, editar e excluir | Histórico | Registros com antes e depois dos campos auditados |
| CA-09 | RN-01 | Dois negócios | Qualquer rota | Cada um vê e altera só o seu; id de outro dá 404 |
| CA-10 | RN-09 | Tela | Lista, filtros, criar, editar, arquivar, excluir, categorias, preset | Comportamento e mensagens certos; sem violações no axe |
| CA-11 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/services` | Lista (`q`, `status`, `category_id`, `limit`, `cursor`) | todos |
| POST | `/services` | Cria | dona, gerente |
| GET | `/services/{id}` | Detalhe | todos |
| PATCH | `/services/{id}` | Edita (inclui `status`) | dona, gerente |
| DELETE | `/services/{id}` | Exclui | dona, gerente |
| POST | `/services/presets` | Cria o preset do nicho | dona, gerente |
| GET | `/service-categories` | Lista com contagem | todos |
| POST | `/service-categories` | Cria | dona, gerente |
| PATCH | `/service-categories/{id}` | Renomeia | dona, gerente |
| DELETE | `/service-categories/{id}` | Exclui (se vazia) | dona, gerente |

## Dados

`service_categories` (`tenant_id`, `name`, `name_key` gerado), `services` (`tenant_id`, `category_id`, `name`, `name_key` gerado, `description`, `duration_minutes`, `price_cents`, `buffer_before_minutes`, `buffer_after_minutes`, `requires_deposit`, `status`), RLS e únicos por negócio em `name_key`.

## Fora do escopo

Preço por profissional, pacotes de sessões, variações de um serviço (tamanho, comprimento) e foto do serviço; vocabulário por nicho nos textos da tela.
