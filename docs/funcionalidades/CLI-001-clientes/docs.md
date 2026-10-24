# CLI-001 · Clientes

## Para quem usa

**Clientes** no menu (todos os papéis). Busque por nome, telefone, e-mail ou Instagram (sem se preocupar com acentos ou maiúsculas), cadastre e edite clientes. O telefone é pedido com o país (padrão Brasil) e vira o formato internacional sozinho. Telefone, e-mail e Instagram não podem se repetir no mesmo negócio. Dona e gerente também excluem clientes e veem o **histórico** de mudanças de cada um. Com a assinatura suspensa ou em um acesso de suporte, a tela fica só para consulta.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/clients?q=&limit=&cursor=` | todos |
| GET | `/clients/{id}` | todos |
| POST | `/clients`, `/clients/quick` | todos (sem acesso de suporte) |
| PATCH | `/clients/{id}` | todos (sem acesso de suporte) |
| DELETE | `/clients/{id}` | dona e gerente (sem acesso de suporte) |

- **Telefone:** E.164 (`+5511999998888`); `ClientInput::normalize` remove espaços, hífens, pontos e parênteses. Check no banco (`clients_phone_e164`).
- **Unicidade:** índices únicos `clients_tenant_phone_unique` e, parciais, `clients_tenant_email_unique` e `clients_tenant_instagram_unique`. A validação dá a mensagem; o banco decide em corridas (`ClientService` mapeia a violação para 422 no campo).
- **Criação rápida:** tenta inserir e, se o índice do telefone recusa, devolve o cliente existente (`existing: true`): sem checagem prévia, portanto sem corrida.
- **Busca e ordem:** colunas geradas `search_name`, `search_text`, `phone_digits` (a função `f_unaccent` embrulha a `unaccent` como IMMUTABLE); índices `pg_trgm`. Os curingas do `LIKE` digitados valem como texto. Busca por telefone só quando o texto parece telefone.
- **Histórico:** `Client` usa `Audited` (`name`, `phone`, `email`, `instagram`, `notes`); a tela mostra com `EntityHistory`.
- **Testes:** `vendor/bin/pest tests/Feature/Clients` e `pnpm --filter web exec vitest run src/components/panel/clients-view.test.tsx src/lib/phone.test.ts`.

## Pendências registradas nas revisões

- **LGPD:** o histórico guarda nome, telefone e e-mail do cliente (as notas ficam ocultas) e é só de acréscimo: o pedido de eliminação do titular depende do PLT-016 (anonimização do histórico). Orientar a não digitar dado de saúde nas notas.
- O acesso de suporte (30 minutos, como a dona) lê todos os clientes e o histórico sem trilha de leitura; auditoria de leitura de dado pessoal fica como evolução.
- A criação rápida devolve só `id`, `name` e `phone`.

## Limites conhecidos

- A exclusão é barrada quando o cliente tem agendamentos (409 `client_in_use`); o cliente mostra `appointments_count` e `average_review`.
- Sem média de avaliação e contagem de atendimentos (entram com a agenda e os pagamentos), importação, tags nem foto.
- O número "nacional" só tem máscara para o Brasil; os demais países aceitam dígitos agrupados.
