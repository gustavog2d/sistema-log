# FIN-002 · Despesas e categorias

## Para quem usa

**Despesas** no menu (só dona e gerente). A tela mostra um mês de cada vez, com o total do mês, o total por categoria e a lista de despesas; use **Mês anterior** e **Próximo mês** para navegar.

- **Nova despesa:** escolha a categoria, escreva a descrição, o valor e a data do gasto. Pode ser data futura (conta já programada). Dá para **editar** e **excluir** (com confirmação).
- **Categorias:** o botão **Categorias** abre a gestão: criar, renomear e excluir. **Criar categorias sugeridas** cadastra as que faltam entre Aluguel, Contas e serviços, Equipamentos, Impostos e taxas, Marketing, Materiais e Outros. Uma categoria **com despesas não pode ser excluída**: mova ou apague as despesas antes. Os nomes são únicos sem diferenciar maiúsculas nem acentos.
- Sem categoria cadastrada não dá para registrar despesa; a tela avisa.

## Para quem desenvolve

| Método | Rota | Quem |
| --- | --- | --- |
| GET | `/expense-categories` (com `expenses_count`) | dona e gerente |
| POST | `/expense-categories`, `/expense-categories/defaults` | dona e gerente |
| PATCH, DELETE | `/expense-categories/{id}` | dona e gerente |
| GET | `/expenses` (`from` e `to` obrigatórios, até 366 dias; `category_id`; `limit` 1–500) | dona e gerente |
| GET, PATCH, DELETE | `/expenses/{id}` | dona e gerente |
| POST | `/expenses` | dona e gerente |

- `GET /expenses` devolve `data`, e os totais do **filtro inteiro**: `total_cents`, `count` e `by_category` (do maior para o menor). A FIN-003 lê esses números.
- **Regras** em `App\Expenses\ExpenseLedger`; validação em `ExpenseInput`. `spent_on` é uma data do calendário do negócio (coluna `date`, sem horário nem fuso).
- **Categoria:** nome único pela coluna gerada `name_key` (sem acento, sem diferenciar caixa); `expenses` aponta para ela por chave composta `(tenant_id, category_id)` com `ON DELETE RESTRICT`, e a API responde 409 `category_in_use` com `count`.
- **Histórico:** `Expense` e `ExpenseCategory` usam `Audited`.
- **Acesso:** `role:owner,manager` em todas as rotas; as escritas têm `no.impersonation` (suporte só lê) e respeitam `subscription.access`.
- **Testes:** `vendor/bin/pest tests/Feature/Expenses` e `pnpm --filter web exec vitest run src/components/panel/expenses-view.test.tsx src/lib/expense-types.test.ts`.

## Limites conhecidos

- Sem despesa recorrente, comprovante anexo, fornecedor, rateio entre profissionais ou importação de extrato.
- A lista do mês mostra até 500 despesas (os totais cobrem todas).
- Receita, resultado do mês e gráficos vêm com o FIN-003.
