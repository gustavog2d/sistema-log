# FIN-003 · Dashboard financeiro

## Para quem usa

**Financeiro** no menu (só dona e gerente). A tela mostra um mês de cada vez (use **Mês anterior** e **Próximo mês**):

- **Recebida:** o dinheiro que entrou no mês (pagamentos recebidos, por data do pagamento). Estornado não conta.
- **Prevista:** o valor dos atendimentos do mês (agendados, pendentes e finalizados; cancelados e faltas ficam de fora).
- **A receber:** prevista menos o que já foi recebido desses atendimentos, mesmo que o pagamento (um sinal, por exemplo) tenha sido em outro mês.
- **Líquida:** recebida menos a comissão dos profissionais (cada pagamento paga o percentual guardado no atendimento). Também aparece a **prevista líquida**.
- **Despesas** do mês e o **resultado** (líquida menos despesas, que pode ser negativo).
- **Meta do mês:** defina um valor mensal e veja quanto do recebido já chegou lá (pode passar de 100%). A mesma meta vale para todos os meses; deixe em branco para remover.
- **Por forma de pagamento**, o **balanço dos últimos 12 meses** (com totais) e o **calendário** do mês com o movimento de cada dia.

Os dias seguem o calendário do negócio (Brasília): um pagamento às 23:30 do dia 30 é desse mês.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/finance/summary?from=&to=` (datas, até 366 dias) | dona e gerente |
| GET | `/finance/monthly?to=aaaa-mm` (12 meses; padrão o mês atual) | dona e gerente |
| GET | `/finance/calendar?month=aaaa-mm` | dona e gerente |
| GET | `/finance/goal` | dona e gerente |
| PUT | `/finance/goal` (`monthly_goal_cents` de 1 a 1.000.000.000 ou `null`) | dona e gerente |

- **Cálculo** em `App\Finance\FinanceReport`, direto das tabelas `payments`, `appointments` e `expenses` (sem tabelas de resultado, nada fica velho). O instante vira data no fuso `BusinessTime::ZONE` antes de comparar; o RLS limita ao negócio.
- **Comissão** = `valor × percentual do agendamento` ÷ 100 arredondada ao centavo (meio para cima) **por pagamento** (recebida) e **por agendamento** (prevista), como na tela do agendamento; assim o resumo, o balanço e o calendário somam igual. O percentual é o congelado no agendamento (FIN-001).
- **A receber** é calculado por agendamento (`GREATEST(valor − recebido, 0)`), então nunca fica negativo.
- **Meta:** `App\Finance\MonthlyGoal` guarda em `tenant_settings` (chave `monthly_goal_cents`, única por negócio); limpar apaga a linha. A mudança entra no histórico como "Configuração".
- **Acesso:** `role:owner,manager`; `PUT /finance/goal` tem `no.impersonation` e respeita `subscription.access`.
- **Testes:** `vendor/bin/pest tests/Feature/Finance` e `pnpm --filter web exec vitest run src/components/panel/finance-view.test.tsx src/lib/finance-types.test.ts`.

## Limites conhecidos

- O fuso do negócio é fixo (Brasília).
- Uma única meta mensal para todos os meses; sem meta por profissional.
- Sem gráficos interativos, exportação nem comparação com o ano anterior; o balanço usa uma tabela com barras.
- Receita de orçamentos e cobranças automáticas entram quando essas funcionalidades existirem.
