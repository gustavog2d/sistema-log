# AGD-002 · Profissionais, expediente e folgas

- **Status:** aprovada (modelo de domínio e herança do Tatulog; decisões técnicas delegadas por Gustavo em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** AGD-001
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

A agenda precisa saber **quem atende**, **em que horários** e **quando não atende**. Este item cadastra os profissionais (a própria dona pode ser um deles), o expediente semanal de cada um e as folgas (de uma pessoa ou do negócio todo, como um feriado). O cálculo de horários livres e os agendamentos vêm no AGD-003.

## Regras de negócio

- **RN-01** — Profissional: `name` (obrigatório, 2 a 100, único no negócio sem diferenciar maiúsculas e acentos), `color` (`#RRGGBB`, usada na agenda; padrão sorteado de uma paleta), `commission_percent` (inteiro de 0 a 100, padrão 0: comissão sobre o valor dos atendimentos; **dado financeiro**: dona e gerente veem a de todos, o profissional vê só a própria e os demais papéis recebem nulo), `user_id` (opcional: pessoa do negócio que usa este perfil para entrar e ver a própria agenda; uma pessoa só pode estar em um profissional; precisa ser da equipe ativa do negócio), `status` (`active` ou `inactive`, padrão `active`).
- **RN-02** — Quem usa: todos os papéis **leem** profissionais, expediente e folgas; só dona e gerente **criam, editam, desativam, excluem** e definem expediente e folgas. Suporte só lê; assinatura suspensa ou cancelada = somente leitura.
- **RN-03** — Desativar um profissional (`inactive`) tira da nova agenda mas mantém o histórico; excluir só é permitido se nunca foi usado em agendamento (a regra passa a valer com a agenda; hoje a exclusão sempre é permitida e leva o expediente junto).
- **RN-04** — **Expediente semanal** (`PUT /professionals/{id}/working-hours`): a lista **inteira** de faixas substitui a anterior. Cada faixa: `weekday` (0 = domingo a 6 = sábado), `start` e `end` no formato `HH:MM` (horário do negócio; na tela são campos de texto para aceitar `24:00` no fim), de 5 em 5 minutos, `end` depois de `start`, até 24:00. Um dia pode ter várias faixas (ex.: 09:00-12:00 e 14:00-18:00), **sem sobreposição** (o banco também garante). Dia sem faixa = não atende. `GET` devolve as faixas ordenadas por dia e início.
- **RN-05** — **Folga** (`time-offs`): `professional_id` (vazio = o negócio todo: feriado, recesso), `starts_at` e `ends_at` (instantes ISO 8601 entre 2000 e 2100; fim depois do início; duração de até 366 dias), `reason` (opcional, até 100). Folgas podem se sobrepor. `GET /time-offs` lista por início (mais próximas primeiro) com filtros `professional_id`, `from` e `to` (período em que a folga toca), paginação por cursor.
- **RN-06** — Toda criação, edição e exclusão de profissional e de folga, e cada troca de expediente, entra no histórico (FND-008). Excluir um profissional registra também a exclusão de cada folga dele e a do expediente. O expediente guarda o conjunto novo e o anterior em um só registro (`working_hours` do profissional).
- **RN-07** — Horário do expediente é o relógio do negócio (fuso do negócio); folgas são instantes (UTC na API).
- **RN-08** — Tela `/profissionais`: lista com cor, comissão, situação e vínculo (o diálogo escolhe a pessoa da equipe ativa, para dona e gerente); cadastro e edição em diálogo; editor de expediente (por dia da semana, várias faixas, copiar um dia para os outros); folgas em lista com cadastro (período e motivo) e exclusão; desativar e reativar. Estados vazio, carregando e erro; erros por campo; pt-BR.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Dados válidos e inválidos | `POST /professionals` | 201 com os padrões e cor da paleta; 422 por campo (nome, cor, comissão fora de 0-100, pessoa de outro negócio ou já vinculada) |
| CA-02 | RN-01 | Nome repetido com outra grafia; mesmo nome em outro negócio | Criar e editar | 422 no negócio; aceito em outro; não colide consigo mesmo |
| CA-03 | RN-02 | Quatro papéis; suporte; suspensa | Rotas | Todos leem; só dona e gerente escrevem; suporte só lê; 403 com `code` |
| CA-04 | RN-03 | Profissional | Desativar, reativar, excluir | Status muda e vai ao histórico; filtro `status` na lista; excluir leva o expediente |
| CA-05 | RN-04 | Faixas válidas | `PUT .../working-hours` | Substitui tudo; várias faixas por dia; `GET` ordenado |
| CA-06 | RN-04 | Faixas inválidas (sobrepostas, fim antes do início, fora de 5 min, dia 7, horário malformado) | `PUT .../working-hours` | 422 sem mudar nada |
| CA-07 | RN-05 | Folgas | Criar, editar, excluir, listar com filtros | Validações, folga do negócio todo, filtros por pessoa e período, cursor |
| CA-08 | RN-06 | Mudanças | Histórico | Registros com antes e depois; expediente em um registro |
| CA-09 | RN-02 | Dois negócios | Qualquer rota | Cada um vê e altera só o seu; 404 para id de outro negócio; pessoa e profissional de outro negócio recusados |
| CA-10 | RN-08 | Tela | Lista, cadastro, edição, expediente, folgas | Comportamento e mensagens certos; sem violações no axe |
| CA-11 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/professionals` | Lista (`status`, `limit`, `cursor`) | todos |
| POST | `/professionals` | Cria | dona, gerente |
| GET | `/professionals/{id}` | Detalhe | todos |
| PATCH | `/professionals/{id}` | Edita (inclui `status`) | dona, gerente |
| DELETE | `/professionals/{id}` | Exclui | dona, gerente |
| GET | `/professionals/{id}/working-hours` | Expediente | todos |
| PUT | `/professionals/{id}/working-hours` | Substitui o expediente | dona, gerente |
| GET | `/time-offs` | Lista folgas (`professional_id`, `from`, `to`) | todos |
| POST | `/time-offs` | Cria | dona, gerente |
| PATCH | `/time-offs/{id}` | Edita | dona, gerente |
| DELETE | `/time-offs/{id}` | Exclui | dona, gerente |

## Dados

`professionals`, `working_hours` (minutos desde meia-noite; exclusão por sobreposição com `btree_gist`), `time_offs`; RLS e únicos por negócio; `user_id` único quando preenchido.

## Fora do escopo

Horário livre calculado e agendamentos (AGD-003); folga recorrente; expediente por data específica; comissão por serviço; permissão de o profissional editar o próprio expediente.
