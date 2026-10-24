# AGD-003 · Agenda

## Para quem usa

**Agenda** no menu (todos os papéis). A tela mostra a semana de segunda a domingo, dia a dia, com horário, cliente, profissional, serviço, valor e situação. Use **Semana anterior**, **Hoje** e **Próxima semana** para navegar e o filtro **Profissional** para ver só a agenda de uma pessoa.

- **Novo agendamento:** busque o cliente (nome ou telefone; clientes novos se cadastram em **Clientes**), escolha profissional, serviço e início. O fim vem da duração do serviço; sem serviço, informe o fim. **Ver horários livres** lista os horários possíveis do dia (de 15 em 15 minutos) e preenche o início ao clicar.
- **Encaixe:** fora do expediente ou em uma folga o sistema avisa e pede confirmação (**Encaixar mesmo assim**). Já **conflito com outro agendamento do mesmo profissional nunca é permitido** (os intervalos antes e depois do serviço contam).
- **Situações:** *Pendente* → *Agendado* → *Finalizado*; também *Cancelado* e *Faltou*. Um cancelado ou faltoso volta a *Agendado* por **Reagendar**, que confere o horário de novo. *Finalizado* é definitivo: só notas, valor e avaliação (1 a 5) mudam.
- **Excluir:** dona e gerente, e só quem não foi finalizado. Use o cancelamento para guardar o histórico.
- **Quem vê o quê:** dona e gerente veem e mexem em tudo; a recepção também, mas sem valores; o profissional vê e agenda só no próprio perfil.
- Cliente, serviço e profissional com agendamentos **não podem ser excluídos** (desative o profissional, arquive o serviço). Na API, o cliente mostra quantos agendamentos tem (sem cancelados) e a média das avaliações.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/appointments` (`from`, `to` obrigatórios, até 62 dias; `professional_id`, `client_id`, `status`, `limit` 1–500, padrão 200) | todos (profissional só os próprios) |
| GET | `/appointments/{id}` | todos (404 para o de outro profissional) |
| POST, PATCH | `/appointments`, `/appointments/{id}` | todos, menos acesso de suporte |
| DELETE | `/appointments/{id}` | dona e gerente |
| GET | `/professionals/{id}/availability` (`date`, e `service_id` ou `duration_minutes`) | todos |

- **Regras** em `App\Appointments\AppointmentBook`; horários livres em `Availability`; papéis em `Visibility`; entrada em `AppointmentInput`.
- **Instantes** em UTC, de 5 em 5 minutos; o fim vem da duração do serviço e os intervalos (`buffer_*`) são copiados do serviço para o agendamento. O expediente é lido no fuso do negócio (`App\Support\BusinessTime::ZONE`, hoje fixo em Brasília); um agendamento precisa caber em uma faixa do mesmo dia.
- **Conflito:** `appointments_no_overlap` (`EXCLUDE USING gist` sobre `tstzrange(blocked_from, blocked_until)`, só para pendente, agendado e finalizado) decide mesmo em corrida; a API confere antes (409 `appointment_conflict` com `conflict: {id, starts_at, ends_at}`) e converte a violação `23P01` na mesma resposta.
- **Fora do expediente/folga:** 422 `outside_availability` com `reasons` (`outside_working_hours`, `time_off`); `allow_outside_hours: true` confirma o encaixe.
- **Situações:** grafo e limites de edição por situação em `AppointmentBook` (constantes `TRANSITIONS` e `EDITABLE`); reativar ou mudar o horário confere conflito e expediente de novo.
- **Travas de exclusão:** 409 com `code` `client_in_use`, `service_in_use`, `professional_in_use`, `appointment_completed`; o banco também recusa (chaves compostas `(tenant_id, x_id)` com RESTRICT).
- **Histórico:** `Appointment` usa `Audited`; as notas ficam mascaradas (`[oculto]`).
- **Testes:** `vendor/bin/pest tests/Feature/Appointments` e `pnpm --filter web exec vitest run src/components/panel/agenda-view.test.tsx src/lib/appointment-types.test.ts`.

## Limites conhecidos

- A tela é uma lista por dia (não há grade de horas nem arrastar e soltar); o calendário do mês vem com o painel do financeiro.
- Sem agendamento recorrente, sem lista de espera e sem agendamento feito pelo próprio cliente (outras fases).
- O fuso do negócio ainda é fixo (Brasília).
- O cliente de um agendamento não pode ser trocado pela tela (cancele e crie outro).
- Valor e pagamentos do atendimento (sinal, parcelas, comissão) vêm com o FIN-001; por ora `value_total_cents` é só o valor combinado.
