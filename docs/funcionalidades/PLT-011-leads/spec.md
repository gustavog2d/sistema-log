# PLT-011 · Leads da landing (captura, origem e tela da equipe)

- **Status:** aprovada (decisões técnicas delegadas por Gustavo em 2026-10-22; escopo do backlog: leads com UTM e reCAPTCHA, tela de leads no super admin)
- **Fase:** 1 · Plataforma
- **Plano / feature flag:** —
- **Depende de:** PLT-001
- **Responsável pela spec:** agente Produto + Arquiteto · **Aprovação:** Gustavo

## Problema e objetivo

A landing precisa captar interessados (nome, e-mail, telefone, nicho) e a equipe precisa saber de onde cada um veio (UTM) e acompanhar o contato. Este item entrega a API de captura (com verificação anti-robô) e a gestão dos leads pela equipe; a landing e a tela ficam no painel web.

## Regras de negócio

- **RN-01** — `POST /leads` (público, só nos hosts da plataforma): `name` (2–100), `email`, `phone` (opcional, E.164), `niche` (opcional, um dos nichos configurados), `message` (opcional, até 500), UTM (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, até 100 cada), `referrer` e `landing_path` (até 255), `accept_privacy` verdadeiro (consentimento LGPD) e `captcha_token`. Responde sempre `202` sem corpo útil, haja ou não lead repetido.
- **RN-02** — Anti-robô: o `captcha_token` é conferido por um verificador (`CaptchaVerifier`). Em produção é o reCAPTCHA do Google (chave secreta em configuração, nunca no código); em desenvolvimento e testes há um verificador de teste (token `captcha_ok`), recusado fora de `local` e `testing`. Token ausente, inválido ou recusado: 422 em `captcha_token`, nada gravado. Falha do serviço de verificação conta como recusa (fecha por padrão).
- **RN-03** — Campo isca (`website`): se vier preenchido, responde 202 como sucesso mas não grava nada (robô não descobre que foi barrado).
- **RN-04** — Repetição: o mesmo e-mail (sem diferenciar maiúsculas) dentro de 24 horas não cria outro lead; o pedido repetido responde 202 igual e só atualiza a mensagem/telefone se vierem preenchidos. Depois de 24 horas vira um novo lead.
- **RN-05** — Limites: 5 pedidos por hora por IP e teto global de 300 por hora; acima disso 429.
- **RN-06** — Dados guardados: o necessário mais a origem; o IP não é guardado, só um hash com sal (para achar abuso), e o consentimento (`privacy_accepted_at` e a versão da política). Lead vem com `status` `new`.
- **RN-07** — `GET /platform/leads` (equipe): lista por cursor (`limit` 1–50), do mais novo ao mais antigo, com busca `q` (nome ou e-mail), filtro `status` (`new`, `contacted`, `converted`, `discarded`) e `utm_source`. `PATCH /platform/leads/{id}` (equipe) altera `status` e `notes` (até 1000). Suporte e super admin podem os dois.
- **RN-08** — Retenção: leads `discarded`, ou `new`/`contacted` sem movimento há mais de 12 meses, são apagados por rotina diária; `converted` fica.
- **RN-09** — Auditoria: `lead.updated` (quem, status antes/depois; as notas não vão para o log).

## Critérios de aceite

| ID | Regra | Dado | Quando | Então |
| --- | --- | --- | --- | --- |
| CA-01 | RN-01, RN-06 | Dados válidos e captcha ok | `POST /leads` | 202; lead gravado com UTM e consentimento; IP só em hash |
| CA-02 | RN-01 | Sem consentimento, e-mail inválido, telefone fora do E.164, nicho desconhecido, textos longos | `POST /leads` | 422; nada gravado |
| CA-03 | RN-02 | Sem token, token inválido, verificador recusa ou fora do ar | `POST /leads` | 422 em `captcha_token`; nada gravado |
| CA-04 | RN-03 | Campo isca preenchido | `POST /leads` | 202; nada gravado |
| CA-05 | RN-04 | Mesmo e-mail duas vezes em 24 h; depois de 24 h | `POST /leads` | Um lead (mensagem atualizada); dois leads |
| CA-06 | RN-05 | Mais de 5 pedidos por hora do mesmo IP | `POST /leads` | 429 |
| CA-07 | RN-07 | Vários leads | `GET /platform/leads` | Página certa, novos primeiro, filtros e busca; `limit` inválido 422; cursor inválido 422 |
| CA-08 | RN-07 | Equipe | `PATCH /platform/leads/{id}` | Status e notas alterados; inválidos 422; inexistente 404 |
| CA-09 | RN-07 | Sem login; host de assinante | Rotas da equipe | 401; 404 |
| CA-10 | RN-08 | Leads antigos | Rotina de retenção | Apagados os que a regra manda; `converted` fica |
| CA-11 | RN-09 | Alterar um lead | — | Auditoria com status antes/depois e sem as notas |
| CA-12 | RN-01 | Lead gravado | Listar | A lista não devolve o hash do IP |
| CA-13 | contrato | Respostas reais | Todas as rotas | Batem com o `openapi.yaml` |

## API

Contrato em `openapi.yaml`.

| Método | Rota | Descrição | Erros |
| --- | --- | --- | --- |
| POST | `/api/v1/leads` | Captura (público) | 422, 429 |
| GET | `/api/v1/platform/leads` | Lista (equipe) | 401, 403, 422 |
| PATCH | `/api/v1/platform/leads/{id}` | Status e notas (equipe) | 401, 403, 404, 422 |

## Dados

Tabela `leads` (dado da plataforma, sem tenant): contato, origem (UTM, referrer, página), consentimento, `ip_hash`, `status`, `notes`, datas. O papel de plataforma escreve; a API só lê (a tela da equipe usa a API).

## Fora do escopo

A landing e a tela (painel web), exportação CSV, envio de e-mail à equipe a cada lead, integração com CRM.
