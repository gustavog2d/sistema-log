import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type {
  Expense,
  ExpenseCategory,
  ExpenseList,
} from '@/lib/expense-types';
import { me } from './panel-fixtures';
import { ExpensesView } from './expenses-view';

const call = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (method: string, ...rest: unknown[]) =>
    failure && method !== 'GET'
      ? Promise.reject(failure)
      : call(method, ...rest),
}));

const category = (over: Partial<ExpenseCategory> = {}): ExpenseCategory => ({
  id: 'k1',
  name: 'Aluguel',
  expenses_count: 1,
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const expense = (over: Partial<Expense> = {}): Expense => ({
  id: 'e1',
  category: { id: 'k1', name: 'Aluguel' },
  description: 'Aluguel de novembro',
  amount_cents: 250000,
  spent_on: '2026-11-05',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const list = (rows: Expense[] = [expense()]): ExpenseList => ({
  data: rows,
  total_cents: rows.reduce((sum, row) => sum + row.amount_cents, 0),
  count: rows.length,
  by_category: rows.length
    ? [{ category_id: 'k1', name: 'Aluguel', total_cents: 250000 }]
    : [],
});

const view = (
  props: Partial<React.ComponentProps<typeof ExpensesView>> = {},
) => (
  <ExpensesView
    me={me()}
    initial={list()}
    categories={[category()]}
    month="2026-11"
    {...props}
  />
);

beforeEach(() => {
  failure = null;
  call.mockReset();
  call.mockImplementation((method: string) =>
    Promise.resolve({
      status: 200,
      data: method === 'GET' ? list() : expense(),
    }),
  );
});

describe('ExpensesView', () => {
  it('CA-11 shows the month, the total, the total by category and the list', () => {
    render(view());

    expect(
      screen.getByRole('heading', { name: 'novembro de 2026' }),
    ).toBeInTheDocument();
    expect(
      screen.getByText('Total do mês').nextElementSibling,
    ).toHaveTextContent('R$ 2.500,00');
    const table = screen.getByRole('table', { name: 'Despesas do mês' });
    expect(within(table).getByText('05/11/2026')).toBeInTheDocument();
    expect(within(table).getByText('Aluguel de novembro')).toBeInTheDocument();
  });

  it('CA-11 shows an empty state, a load error and asks for a category first', () => {
    const { unmount } = render(view({ initial: list([]) }));
    expect(screen.getByText('Nenhuma despesa neste mês.')).toBeInTheDocument();
    unmount();

    const second = render(view({ initial: null }));
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar as despesas agora.',
    );
    second.unmount();

    render(view({ categories: [] }));
    expect(screen.getByText(/Crie ao menos uma categoria/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nova despesa' })).toBeDisabled();
  });

  it('CA-11 loads the next month with its first and last day', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Próximo mês' }));

    await waitFor(() => expect(call).toHaveBeenCalled());
    expect(String(call.mock.calls[0]?.[1])).toContain(
      'from=2026-12-01&to=2026-12-31',
    );
    expect(
      await screen.findByRole('heading', { name: 'dezembro de 2026' }),
    ).toBeInTheDocument();
  });

  it('CA-11 registers an expense with the amount in cents and refreshes the month', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Nova despesa' }));
    const dialog = screen.getByRole('dialog', { name: 'Nova despesa' });
    await user.type(
      within(dialog).getByLabelText('Descrição'),
      'Compra de tinta',
    );
    await user.type(within(dialog).getByLabelText(/^Valor/), '89,90');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'expenses', {
        body: {
          category_id: 'k1',
          description: 'Compra de tinta',
          amount_cents: 8990,
          spent_on: '2026-11-01',
        },
      }),
    );
    expect(await screen.findByText('Despesa registrada.')).toBeInTheDocument();
  });

  it('CA-11 refuses an empty amount on the screen and shows API field errors', async () => {
    const user = userEvent.setup();
    render(view());
    await user.click(screen.getByRole('button', { name: 'Nova despesa' }));
    const dialog = screen.getByRole('dialog', { name: 'Nova despesa' });
    await user.type(within(dialog).getByLabelText('Descrição'), 'Tinta');

    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(within(dialog).getByLabelText(/^Valor/)).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(call.mock.calls.filter((c) => c[0] === 'POST')).toHaveLength(0);

    await user.type(within(dialog).getByLabelText(/^Valor/), '10');
    failure = new ApiError('x', 422, {
      description: 'A descrição precisa ter ao menos 2 caracteres.',
    });
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(
      await within(dialog).findByText(
        'A descrição precisa ter ao menos 2 caracteres.',
      ),
    ).toBeInTheDocument();
  });

  it('CA-11 edits an expense with the values filled in', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Editar: Aluguel de novembro' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Editar despesa' });
    expect(within(dialog).getByLabelText(/^Valor/)).toHaveValue('2500,00');
    expect(within(dialog).getByLabelText('Data do gasto')).toHaveValue(
      '2026-11-05',
    );
    await user.clear(within(dialog).getByLabelText(/^Valor/));
    await user.type(within(dialog).getByLabelText(/^Valor/), '2600');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'expenses/e1', {
        body: {
          category_id: 'k1',
          description: 'Aluguel de novembro',
          amount_cents: 260000,
          spent_on: '2026-11-05',
        },
      }),
    );
  });

  it('CA-11 deletes only after the confirmation', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Excluir: Aluguel de novembro' }),
    );
    const dialog = screen.getByRole('dialog', {
      name: 'Excluir esta despesa?',
    });
    expect(call).not.toHaveBeenCalled();
    await user.click(
      within(dialog).getByRole('button', { name: 'Sim, excluir' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'expenses/e1'),
    );
    expect(await screen.findByText('Despesa excluída.')).toBeInTheDocument();
  });

  it('CA-11 manages categories: creates the suggested ones and explains a category in use', async () => {
    const user = userEvent.setup();
    render(view({ categories: [] }));

    await user.click(screen.getByRole('button', { name: 'Categorias' }));
    const dialog = screen.getByRole('dialog', {
      name: 'Categorias de despesa',
    });
    call.mockImplementation((method: string, path: string) =>
      Promise.resolve({
        status: 200,
        data:
          path === 'expense-categories'
            ? { data: [category({ expenses_count: 0 })] }
            : method === 'GET'
              ? list()
              : {},
      }),
    );
    await user.click(
      within(dialog).getByRole('button', {
        name: 'Criar categorias sugeridas',
      }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'expense-categories/defaults'),
    );
    expect(await within(dialog).findByText('Aluguel')).toBeInTheDocument();

    failure = new ApiError('x', 409, {}, 'category_in_use');
    await user.click(
      within(dialog).getByRole('button', { name: 'Excluir: Aluguel' }),
    );
    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Esta categoria tem despesas.',
    );
  });

  it('CA-11 disables every change when read only or in a support access', () => {
    const { unmount } = render(view({ me: me('owner', { can_write: false }) }));
    expect(screen.getByRole('button', { name: 'Nova despesa' })).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Editar: Aluguel de novembro' }),
    ).toBeDisabled();
    unmount();

    render(view({ me: me('owner', { impersonating: true }) }));
    expect(
      screen.getByText(/não estão disponíveis em um acesso de suporte/),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nova despesa' })).toBeDisabled();
  });

  it('CA-11 has no accessibility violations (list and dialog)', async () => {
    const user = userEvent.setup();
    const { container } = render(view());
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Nova despesa' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});

describe('ExpensesView extra states', () => {
  it('CA-11 warns when the list is shorter than the month and when the categories did not load', () => {
    const long = { ...list(), count: 600 };
    const { unmount } = render(view({ initial: long }));
    expect(
      screen.getByText(/Mostrando as 1 despesas mais recentes de 600/),
    ).toBeInTheDocument();
    unmount();

    render(view({ categories: null }));
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar as categorias agora.',
    );
  });
});
