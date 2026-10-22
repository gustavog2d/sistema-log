# Glossário

Todos os termos são provisórios. Ao renomear algo na interface, atualize aqui e nos arquivos de tradução; o termo de código só muda se o significado mudar.

| Termo na interface (pt-BR) | Termo no código | Significado | Variações por nicho |
| --- | --- | --- | --- |
| sistema-log | `app.name` (config) | Nome de trabalho do produto | — |
| Assinante / negócio / estúdio | `tenant` | Conta que paga a assinatura e tem subdomínio próprio | "estúdio" (tatuagem), "salão" (beleza) |
| Super admin | `platform_user` (papel `super_admin`) | Equipe da plataforma | — |
| Dono, gerente, profissional, recepção | `membership.role` | Papéis dentro do assinante | "tatuador" para profissional |
| Plano | `plan` | Pacote contratado | Essencial, Profissional, Estúdio (provisórios) |
| Funcionalidade do plano | `feature` / entitlement | Chave liberada ou limitada pelo plano | — |
| Serviço | `service` | O que o negócio oferece, com duração e preço padrão | "trabalho", "procedimento" |
| Orçamento | `quote` | Proposta de preço, duração e sessões para um cliente | — |
| Agendamento / atendimento / sessão | `appointment` | Horário reservado para um cliente | "sessão" (tatuagem), "atendimento" (salão) |
| Retoque | `appointment.type = retouch` | Sessão de ajuste após o trabalho | Tatuagem, micropigmentação |
| Sinal | `payment.kind = deposit` | Valor pago para reservar | "entrada" |
| Cliente | `client` | Cliente final do assinante | — |
| Expediente | `working_hours` | Horários em que o profissional atende | — |
| Bloqueio | `time_off` | Folga, férias, feriado | — |
| Preset de nicho | `niche_preset` | Serviços, durações e vocabulário iniciais de um nicho | — |
