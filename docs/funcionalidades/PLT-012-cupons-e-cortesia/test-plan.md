# PLT-012 · Plano de teste

- **Spec:** [spec.md](spec.md) (versão aprovada em: 2026-10-22)
- **Responsável:** agente QA

## Matriz critério × camada

| Caso | Critério | Camada | Arquivo do teste | Situação |
| --- | --- | --- | --- | --- |
| T-01 | CA-01 a CA-04, CA-14 | API | `Feature/Coupons/CouponAdminTest.php` | passando |
| T-02 | CA-05 a CA-08, CA-10, CA-12, CA-13 | API | `Feature/Coupons/CouponSignupTest.php` | passando |
| T-03 | CA-09 | API | `Feature/Coupons/CourtesyBillingTest.php` | passando |
| T-04 | CA-11 | API | `Feature/Coupons/CouponCheckTest.php` | passando |
| T-05 | CA-15 | Contrato | `Feature/Coupons/CouponContractTest.php` | passando |

## Casos obrigatórios

Cobertos: fluxo principal, permissão por papel (super admin escreve, suporte lê), resposta igual para qualquer cupom indisponível, uso não consumido em falha, último uso concorrente (em sequência), cortesia sem cartão e na cobrança, resposta no contrato e limite de tentativas.

Simultaneidade real (dois cadastros ao mesmo tempo): não há teste automatizado; a garantia é o cupom travado com `FOR UPDATE` e a restrição `redeemed_count <= max_redemptions` no banco.
