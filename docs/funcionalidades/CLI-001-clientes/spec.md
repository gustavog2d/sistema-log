# CLI-001 · Clientes

- **Status:** aprovada (herança do Tatulog com as correções da lista de lacunas; decisões técnicas delegadas por Gustavo em 2026-10-22; limites por plano liberados em 2026-10-23)
- **Fase:** 2 · Núcleo
- **Plano / feature flag:** — (liberado em todos os planos por enquanto)
- **Depende de:** FND-008
- **Responsável pela spec:** agente Produto + Arquiteto + Designer · **Aprovação:** Gustavo

## Problema e objetivo

O negócio precisa de um cadastro de clientes simples e sem duplicidade, que sirva de base para agenda, orçamento e financeiro: achar um cliente rápido, criar na hora (dentro de um agendamento) sem duplicar e manter notas e contatos.

## Regras de negócio

- **RN-01** — Campos: `name` (obrigatório, 2 a 100 caracteres, sem endereços de internet nem de e-mail), `phone` (obrigatório, **E.164**: `+` e 8 a 15 dígitos, ex.: `+5511999998888`; espaços, hífens e parênteses são aceitos e removidos), `email` (opcional, em minúsculas, só ASCII), `instagram` (opcional, sem `@`, minúsculas, 1 a 30 letras, números, ponto e sublinhado), `notes` (opcional, até 2000 caracteres; quebra de linha e tabulação valem, outros caracteres de controle e de direção do texto são recusados, e o mesmo vale para o nome).
- **RN-02** — Unicidade **por negócio** (o mesmo telefone pode existir em outro negócio): `phone` sempre; `email` e `instagram` quando preenchidos. Duplicidade responde 422 no campo, com a mensagem; a criação rápida (RN-05) trata o telefone repetido devolvendo o cliente existente.
- **RN-03** — Quem usa: dona, gerente, profissional e recepção listam, veem, criam e editam. **Excluir** só dona e gerente, e só se o cliente não tiver agendamentos (o bloqueio por agendamento passa a valer com a agenda; hoje a exclusão sempre é permitida). Suporte (acesso de suporte) só lê.
- **RN-04** — Listagem `GET /clients`: ordem alfabética por nome (depois id), paginação por cursor (`limit` de 1 a 50, padrão 20), busca `q` (2 a 100 caracteres) por nome, telefone (dígitos), e-mail e Instagram, sem diferenciar maiúsculas e acentos. A busca por telefone aceita qualquer pedaço dos dígitos.
- **RN-05** — Criação rápida `POST /clients/quick` (nome e telefone): se já existe cliente com o telefone, devolve **200** com o cliente existente e `existing: true`; senão cria e devolve **201** com `existing: false`. A resposta traz só `id`, `name` e `phone` (nada de e-mail, Instagram nem notas). Duas chamadas simultâneas com o mesmo telefone resultam em um só cliente (o banco decide).
- **RN-06** — `GET /clients/{id}` e `PATCH /clients/{id}` (campos parciais). Cliente de outro negócio responde 404. Mudança que não altera nada responde 200 sem registro de histórico.
- **RN-07** — Toda criação, edição e exclusão entra no histórico (FND-008) com `name`, `phone`, `email`, `instagram` e `notes`. As **notas** são texto livre e podem ter dado de saúde (alergia, medicação): o histórico registra só que existem ou mudaram (`[oculto]`), nunca o conteúdo.
- **RN-08** — Escritas respeitam o bloqueio por assinatura (suspensa ou cancelada: 403, só leitura). Limite de 240 pedidos por minuto por pessoa e, na listagem (que devolve dados pessoais), 60 por minuto.
- **RN-09** — Tela `/clientes`: lista com busca (a cada envio), "carregar mais", cadastro e edição em diálogo (telefone com país e máscara, padrão +55), exclusão com confirmação (dona e gerente), histórico do cliente em diálogo. Estados vazio, carregando e erro; erros da API por campo. Texto de interface em pt-BR.

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01 | Dados válidos e inválidos | `POST /clients` | 201; normaliza telefone, e-mail e Instagram; 422 por campo nos inválidos |
| CA-02 | RN-02 | Telefone, e-mail e Instagram repetidos no negócio; e em outro negócio | Criar e editar | 422 no campo; aceito em outro negócio; vazios não colidem |
| CA-03 | RN-03 | Quatro papéis; suporte; sem login | Rotas | Leitura e escrita dos quatro; excluir só dona e gerente; suporte só lê; 401 sem login |
| CA-04 | RN-04 | Vários clientes | `GET /clients` com busca, cursor e limite | Ordem, busca (nome, dígitos, e-mail, Instagram, acentos), cursor, 422 fora de 1-50 |
| CA-05 | RN-05 | Telefone novo e repetido; corrida | `POST /clients/quick` | 201 `existing:false`; 200 `existing:true`; um só cliente na corrida |
| CA-06 | RN-06 | Cliente próprio e de outro negócio | `GET`/`PATCH`/`DELETE` | Dados; 404 do outro; `PATCH` sem mudança não grava histórico |
| CA-07 | RN-07 | Criar, editar e excluir | Histórico | Um registro de cada com antes e depois dos campos auditados |
| CA-08 | RN-08 | Assinatura suspensa e cancelada | Escritas e leitura | 403 com `code` nas escritas; leitura 200 |
| CA-09 | RN-09 | Tela | Lista, busca, criar, editar, excluir, histórico | Comportamento e mensagens certos; sem violações no axe |
| CA-10 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| GET | `/clients` | Lista (`q`, `limit`, `cursor`) | 401, 422 |
| POST | `/clients` | Cria | 401, 403, 422 |
| POST | `/clients/quick` | Criação rápida (devolve o existente pelo telefone) | 401, 403, 422 |
| GET | `/clients/{id}` | Detalhe | 401, 404 |
| PATCH | `/clients/{id}` | Edita | 401, 403, 404, 422 |
| DELETE | `/clients/{id}` | Exclui (dona e gerente) | 401, 403, 404 |

## Dados

`clients` (`tenant_id`, `name`, `phone`, `email`, `instagram`, `notes`, timestamps), RLS, únicos por negócio em `phone` e, quando preenchidos, `email` e `instagram`.

## Fora do escopo

Média de avaliação e contagem de atendimentos (entram com a agenda e os pagamentos); importação de planilha; consentimento de marketing; foto do cliente; tags.
