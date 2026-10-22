# PLT-004 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-24)
- **Responsável:** agente QA

PostgreSQL real (`app_test`). O tempo é controlado por `--as-of` e por `Carbon::setTestNow`. O ID do critério aparece no nome de cada teste.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-03, CA-06, CA-07, CA-08 | Serviço/Banco | `Feature/Billing/BillingRunTest.php` | passando |
| T-02 | CA-04, CA-13 | Serviço/Banco | `BillingRunTest.php` (suspensão imediata) | passando |
| T-03 | CA-05 | Serviço/Banco | `BillingRunTest.php` (idempotência) | passando |
| T-04 | CA-09 | Serviço | `BillingRunTest.php` (preço zero) | passando |
| T-05 | CA-10, CA-16 | Banco (RLS) | `Feature/Billing/InvoiceSecurityTest.php` | passando |
| T-06 | CA-11, CA-12 | Serviço | `BillingRunTest.php` (ignoradas; falha técnica isolada) | passando |
| T-07 | CA-14 | Banco | `BillingRunTest.php` (auditoria) | passando |
| T-08 | CA-15, CA-17 | Comando | `Feature/Billing/BillingCommandTest.php` | passando |
| T-09 | isolamento | Esquema | `SchemaRlsTest` (invoices com RLS) | passando |

Teste real contra a API em execução (2026-10-26): dois cadastros (cartão bom e cartão que falha), `billing:run` simulando o dia 3 (nada acontece) e o dia 8 (um cobrado, um suspenso na hora); o status e as faturas bateram com o esperado.

## Casos obrigatórios

- [x] Sucesso do fluxo principal; [x] idempotência; [x] concorrência (linha travada); [x] fuso/virada de mês (31/01, anos bissextos); [x] auditoria; [x] isolamento de tenant (RLS em `invoices`)
- [ ] Permissão, plano, limite: não se aplicam (sem rotas)

## Fora do plano

Gateway real, webhooks, e-mails, regularização.
