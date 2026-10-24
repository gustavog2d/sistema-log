# AGD-003 · Agenda

- **Status:** aprovada (modelo de domínio e herança do Tatulog; decisões técnicas delegadas por Gustavo em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** CLI-001, AGD-001, AGD-002
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

Marcar, ver e acompanhar os atendimentos: quem atende quem, quando, com qual serviço, e em que situação está cada um. Nunca dois atendimentos do mesmo profissional ao mesmo tempo, e o sistema ajuda a achar um horário livre.

## Regras de negócio

- **RN-01** — Agendamento: `client_id`, `professional_id` (obrigatórios), `service_id` (opcional), `starts_at` (obrigatório, ISO 8601, minutos de 5 em 5), `ends_at` (opcional se há serviço: assume `starts_at` + duração do serviço), `description` (até 255), `type` (`appointment` ou `retouch`, padrão `appointment`), `session_number` e `sessions_total` (sessão n de m, opcionais; `session_number` ≤ `sessions_total`), `value_total_cents` (opcional; padrão: preço do serviço, se tiver), `notes` (até 2000), `status` (padrão `scheduled`) e `client_review` (1 a 5, só com o atendimento finalizado). A duração vai de 5 minutos a 24 horas.
- **RN-02** — Cliente e profissional precisam ser do negócio; o profissional precisa estar ativo e o serviço, ativo (arquivado dá 422). Ao agendar, os intervalos antes e depois do serviço são copiados para o agendamento (mudar o serviço depois não muda agendamentos antigos).
- **RN-03** — **Conflito:** dois agendamentos ativos (`pending`, `scheduled` ou `completed`) do mesmo profissional não podem ocupar o mesmo horário, **contando os intervalos antes e depois**. Conflito responde 409 `appointment_conflict` com o agendamento que atrapalha. Quem decide em uma corrida é o banco (restrição de exclusão). Cancelado e falta liberam o horário.
- **RN-04** — **Fora do expediente ou em folga:** se o agendamento não cabe inteiro em uma faixa do expediente do profissional naquele dia (horário do negócio) ou toca uma folga (dele ou do negócio todo), a API responde 422 `outside_availability` com os motivos (`outside_working_hours`, `time_off`). Quem agenda confirma o encaixe reenviando `allow_outside_hours: true`; o conflito com outro agendamento (RN-03) nunca é liberado.
- **RN-05** — **Situações e passagens:** `pending` → `scheduled` ou `canceled`; `scheduled` → `completed`, `canceled`, `no_show` ou `pending`; `canceled` e `no_show` → `scheduled` (reativar, conferindo conflito e disponibilidade de novo); `completed` é final. Outra passagem dá 422 em `status`. `client_review` só vale em agendamento finalizado.
- **RN-06** — **Edição:** em agendamento ativo (`pending`, `scheduled`) dá para mudar tudo (inclui remarcar: horário, profissional, serviço), conferindo RN-02 a RN-04 de novo. Finalizado: só `notes`, `value_total_cents` e `client_review`. Cancelado e falta: só `notes`.
- **RN-07** — **Quem vê o quê:** dona e gerente veem e mexem em tudo. Recepção vê e mexe em todos os agendamentos, mas **sem valores** (`value_total_cents` vem nulo e é ignorado ao gravar). Profissional vê e mexe só nos agendamentos do profissional ligado à pessoa dele (vínculo do AGD-002, com a equipe ainda ativa); agenda de outro profissional dá 404; sem vínculo, a lista vem vazia e criar dá 403. O acesso de suporte só lê.
- **RN-08** — `GET /appointments` (`from` e `to` obrigatórios, período de até 62 dias; filtros `professional_id`, `client_id`, `status`; `limit` de 1 a 500, padrão 200): agendamentos que tocam o período, por início. Cada um traz `client`, `professional` (com a cor) e `service` resumidos.
- **RN-09** — **Horários livres:** `GET /professionals/{id}/availability?date=AAAA-MM-DD` com `service_id` ou `duration_minutes` devolve os inícios possíveis do dia (de 15 em 15 minutos, no horário do negócio): dentro do expediente, fora de folgas, sem conflito considerando intervalos, e no futuro. Dia sem expediente devolve lista vazia.
- **RN-10** — **Excluir:** só dona e gerente, e não o finalizado (409 `appointment_completed`: use as situações). Exclusão entra no histórico.
- **RN-11** — **Travas em outros cadastros:** cliente, serviço ou profissional com agendamentos não podem ser excluídos (409 `client_in_use`, `service_in_use`, `professional_in_use`, com a dica de arquivar ou desativar). O cliente passa a mostrar `appointments_count` (sem cancelados) e `average_review` (média das avaliações, duas casas, nula sem avaliações).
- **RN-12** — Toda criação, edição e exclusão entra no histórico (FND-008), com as `notes` ocultas.
- **RN-13** — Tela `/agenda`: semana de segunda a domingo com navegação, agendamentos agrupados por dia (horário, cliente, serviço, cor e nome do profissional, situação), filtro por profissional; novo agendamento (cliente com busca e criação rápida, profissional, serviço, data, hora, duração, valor, tipo, sessão, notas; sugestão de horários livres; confirmação do encaixe); detalhe com ações de situação (confirmar, finalizar com avaliação, cancelar, faltou, reativar), remarcar e excluir. Estados vazio, carregando e erro; erros por campo; pt-BR.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-02 | Dados válidos e inválidos | `POST /appointments` | 201 com os padrões (fim pela duração do serviço, valor pelo preço); 422 por campo; cliente, profissional ou serviço de outro negócio, profissional inativo e serviço arquivado recusados |
| CA-02 | RN-03 | Agendamento existente | Criar e remarcar para sobrepor (inclusive só pelos intervalos); encostar sem sobrepor; cancelado e falta | 409 com o conflitante; encostar vale; cancelado e falta liberam |
| CA-03 | RN-03 | Duas gravações ao mesmo tempo no mesmo horário | Banco | Só uma entra; a outra vira 409 |
| CA-04 | RN-04 | Dentro, fora do expediente, em folga, folga do negócio | Criar e remarcar | 201; 422 `outside_availability` com motivos; 201 com `allow_outside_hours` |
| CA-05 | RN-05 | Cada situação | Mudar a situação | Passagens válidas; 422 nas inválidas; reativar confere conflito |
| CA-06 | RN-05, RN-06 | Finalizado, cancelado, ativo | Editar | Só os campos permitidos; avaliação só finalizado, de 1 a 5 |
| CA-07 | RN-07 | Quatro papéis e suporte | Listar, ver, criar, editar | Valores só para dona, gerente e o profissional dono; recepção sem valores; profissional só os seus; 404 do alheio; suporte só lê |
| CA-08 | RN-08 | Vários agendamentos | `GET /appointments` | Período obrigatório e limitado; filtros; ordem; resumos |
| CA-09 | RN-09 | Expediente, folgas e agendamentos | Horários livres | Passos de 15 min, respeitando tudo; vazio sem expediente; passado fora |
| CA-10 | RN-10 | Agendamento ativo e finalizado | Excluir | Dona e gerente apagam o ativo; finalizado 409; outros papéis 403 |
| CA-11 | RN-11 | Cliente, serviço e profissional usados | Excluir | 409 com o código; `appointments_count` e `average_review` do cliente |
| CA-12 | RN-12 | Criar, editar, excluir | Histórico | Registros com antes e depois; notas ocultas |
| CA-13 | RN-02 | Dois negócios | Qualquer rota | Cada um vê e altera só o seu; 404 para id de outro |
| CA-14 | RN-07 | Assinatura suspensa e cancelada | Leitura e escrita | Leitura 200; escrita 403 com `code` |
| CA-15 | RN-13 | Tela | Semana, filtros, criar, remarcar, situações, excluir | Comportamento e mensagens certos; sem violações no axe |
| CA-16 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Quem |
| --- | --- | --- | --- |
| GET | `/appointments` | Lista por período | todos (com as regras de RN-07) |
| POST | `/appointments` | Cria | dona, gerente, recepção, profissional (o próprio) |
| GET | `/appointments/{id}` | Detalhe | idem |
| PATCH | `/appointments/{id}` | Edita, remarca e muda a situação | idem |
| DELETE | `/appointments/{id}` | Exclui | dona, gerente |
| GET | `/professionals/{id}/availability` | Horários livres do dia | todos (com as regras de RN-07) |

## Dados

`appointments` com chaves estrangeiras compostas para clientes, profissionais e serviços (`ON DELETE RESTRICT`), `blocked_from` e `blocked_until` (início e fim com os intervalos) e `EXCLUDE USING gist` por profissional e período nos agendamentos ativos.

## Fora do escopo

Agendamento pelo cliente (agenda pública, Fase 3), lembretes por e-mail e WhatsApp (Fase 4), sinal e pagamentos (FIN-001), agenda em grade de horas por profissional (a tela é por dia em lista), orçamentos que viram sessões (ORC).
