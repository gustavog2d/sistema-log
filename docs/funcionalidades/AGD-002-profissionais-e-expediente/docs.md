# AGD-002 · Profissionais, expediente e folgas

## Para quem usa

**Profissionais** no menu (todos consultam; dona e gerente alteram). Cadastre quem atende (a própria dona pode ser um profissional), com uma cor para a agenda e a comissão sobre os atendimentos. Em **Expediente**, marque os horários de cada dia da semana; um dia pode ter mais de uma faixa (manhã e tarde) e **Copiar para os outros dias úteis** repete o dia de segunda a sexta. Em **Folgas** registre férias, feriados e dias sem atendimento, de uma pessoa ou do negócio todo. **Desativar** tira o profissional das novas agendas mantendo o histórico.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/professionals`, `/professionals/{id}`, `/professionals/{id}/working-hours` | todos |
| POST, PATCH, DELETE | `/professionals`, `/professionals/{id}` | dona e gerente |
| PUT | `/professionals/{id}/working-hours` (substitui tudo) | dona e gerente |
| GET | `/time-offs` (`professional_id`, `from`, `to`, `limit`, `cursor`) | todos |
| POST, PATCH, DELETE | `/time-offs`, `/time-offs/{id}` | dona e gerente |

- **Expediente:** `working_hours` guarda `weekday` (0 = domingo), `start_minute` e `end_minute` (minutos desde a meia-noite, horário do negócio, de 5 em 5). `EXCLUDE USING gist` (extensão `btree_gist`) impede sobreposição por profissional e dia; a API confere antes e responde 422. A troca é transacional (linha do profissional travada) e gera **um** registro de histórico (`working_hours`, com as faixas antes e depois).
- **Folgas:** `time_offs` em `timestamptz`; a API normaliza qualquer fuso para UTC antes de gravar; filtros por pessoa trazem também as folgas do negócio todo.
- **Vínculo com a equipe:** `user_id` precisa ser membro ativo do negócio e único entre os profissionais.
- **Para a agenda (AGD-003):** o horário livre = expediente − folgas − agendamentos − intervalos; `working_hours` e `time_offs` já têm os índices para isso.
- **Testes:** `vendor/bin/pest tests/Feature/Professionals` e `pnpm --filter web exec vitest run src/components/panel/professionals-view.test.tsx`.

## Limites conhecidos

- A exclusão do profissional ainda não é barrada por agendamentos (passa a valer com AGD-003); prefira desativar.
- Sem folga recorrente, expediente por data específica nem comissão por serviço; o profissional não edita o próprio expediente.
- O horário do expediente e das folgas usa o fuso de Brasília (o fuso do negócio ainda é fixo).
