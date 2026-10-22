# PLT-003 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

PostgreSQL real, banco `app_test`. O ID do critério aparece no nome de cada teste. O cenário é montado com o papel dono; as chamadas usam os papéis de runtime e de plataforma como em produção.

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01, CA-02, CA-12 | API/Banco | `Feature/Signup/SignupFlowTest.php` | passando |
| T-02 | CA-03, CA-04, CA-18 | API | `SignupFlowTest.php` (pagamento obrigatório, recusado, sem cobrança, falha só na cobrança) | passando |
| T-03 | CA-05, CA-06, CA-07 | API/Banco | `SignupFlowTest.php` (plano e ciclo, preço anual, preço congelado) | passando |
| T-04 | CA-08 | API | `Feature/Signup/SlugAvailabilityTest.php` | passando |
| T-05 | CA-09, CA-10, CA-11 | API | `SignupFlowTest.php` (endereço, nicho, e-mail, senha, termos) | passando |
| T-06 | CA-13, CA-14, CA-15 | API | `Feature/Signup/SignupSafetyTest.php` (idempotência) | passando |
| T-07 | CA-16 | Banco | `SignupSafetyTest.php` (papel da API não escreve em tenants, assinaturas, pagamentos, usuários) | passando |
| T-08 | CA-17 | API/Banco | `SignupSafetyTest.php` (falha no meio desfaz tudo) | passando |
| T-09 | CA-19, CA-08 | API | `SignupSafetyTest.php`, `SlugAvailabilityTest.php` (limites por IP) | passando |
| T-10 | CA-20 | API/Banco | `SignupFlowTest.php` (auditoria sem segredos) | passando |
| T-11 | CA-21 | API | `SignupFlowTest.php`, `SlugAvailabilityTest.php` (host de assinante dá 404) | passando |
| T-12 | CA-22 | Banco (RLS) | `SignupSafetyTest.php` (vínculos isolados por tenant) | passando |
| T-13 | contrato | Contrato | `Feature/Signup/SignupContractTest.php` | passando |
| T-15 | RN-13, RN-14, RN-15 | API/Banco | `SignupSafetyTest.php` (cupom recusado, compensação no gateway, corrida por endereço e e-mail vira 422, 409 em andamento, reserva abandonada, ordem das chaves) | passando |
| T-16 | RN-09, RN-16, RN-17, RN-18 | API/Banco | `SignupSafetyTest.php` (privilégios mínimos dos papéis, corpo não-JSON dá 415, chave de outro IP não é reaproveitada, gateway fake recusado fora de local/testing, e-mail ASCII, endereço punycode, senha de 72 bytes, limite global) | passando |
| T-14 | esquema | Esquema | `SchemaRlsTest` com as novas tabelas (memberships com RLS; as demais na lista de plataforma) | passando |

Teste real com `curl` contra a API em execução: cadastro, repetição com a mesma chave e consulta de endereço (feito em 2026-10-22). Encontrou um defeito que a suíte não pega: o servidor de desenvolvimento do Laravel lê só o `.env`, que podia estar sem as variáveis novas; corrigido no `start.sh`.

## Casos obrigatórios

- [x] Sucesso do fluxo principal
- [x] Validação de cada campo obrigatório e de limites
- [x] **Isolamento de tenant** (vínculos com RLS; papel da API sem escrita em dados de plataforma)
- [x] Idempotência
- [x] Concorrência: reserva da chave por restrição de unicidade; resposta 409 para pedido em andamento
- [x] Resposta bate com o `openapi.yaml`
- [x] Auditoria sem segredos
- [x] Permissão por papel, plano e assinatura suspensa: não se aplica (rotas públicas)

## Fora do plano

Cobrança no 7º dia, suspensão, cancelamento, cupom, e-mails, telas e gateway real.
