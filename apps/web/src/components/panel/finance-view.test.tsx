import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { FinanceData } from '@/lib/finance-types';
import { me } from './panel-fixtures';
import { FinanceView } from './finance-view';

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

const emptyMonth = (month: string) => ({
  month,
  received_cents: 0,
  net_received_cents: 0,
  expected_cents: 0,
  expenses_cents: 0,
  result_cents: 0,
});

const data = (): FinanceData => ({
  summary: {
    received_cents: 1300000,
    expected_cents: 3000000,
    to_receive_cents: 1500000,
    commission_received_cents: 475000,
    net_received_cents: 825000,
    commission_expected_cents: 1400000,
    net_expected_cents: 1600000,
    expenses_cents: 300000,
    result_cents: 525000,
    appointments_count: 3,
    by_method: [
      { method: 'cash', total_cents: 600000 },
      { method: 'pix', total_cents: 400000 },
    ],
  },
  monthly: {
    months: [
      ...Array.from({ length: 11 }, (_, i) =>
        emptyMonth(`2025-${String(i + 12 > 12 ? i : i + 12).padStart(2, '0')}`),
      ),
      {
        month: '2026-11',
        received_cents: 1300000,
        net_received_cents: 825000,
        expected_cents: 3000000,
        expenses_cents: 300000,
        result_cents: 525000,
      },
    ],
    totals: {
      received_cents: 1300000,
      net_received_cents: 825000,
      expected_cents: 3000000,
      expenses_cents: 300000,
      result_cents: 525000,
    },
  },
  calendar: {
    month: '2026-11',
    days: Array.from({ length: 30 }, (_, i) => ({
      date: `2026-11-${String(i + 1).padStart(2, '0')}`,
      received_cents: i === 2 ? 400000 : 0,
      expected_cents: i === 2 ? 1500000 : 0,
      appointments_count: i === 2 ? 2 : 0,
      expenses_cents: 0,
    })),
  },
});

const view = (
  props: Partial<React.ComponentProps<typeof FinanceView>> = {},
) => (
  <FinanceView
    me={me()}
    initial={data()}
    goal={null}
    month="2026-11"
    {...props}
  />
);

beforeEach(() => {
  failure = null;
  call.mockReset();
  call.mockImplementation((method: string, path: string) => {
    if (path.startsWith('finance/summary'))
      return Promise.resolve({ status: 200, data: data().summary });
    if (path.startsWith('finance/monthly'))
      return Promise.resolve({ status: 200, data: data().monthly });
    if (path.startsWith('finance/calendar'))
      return Promise.resolve({ status: 200, data: data().calendar });

    return Promise.resolve({
      status: 200,
      data: { monthly_goal_cents: method === 'PUT' ? 2000000 : null },
    });
  });
});

describe('FinanceView', () => {
  it('CA-13 shows the cards of the month with the commission hints and the result', () => {
    render(view());
    const cards = within(screen.getByRole('region', { name: 'Resumo do mês' }));

    expect(cards.getByText('Recebida').nextElementSibling).toHaveTextContent(
      'R$ 13.000,00',
    );
    expect(cards.getByText('Prevista').nextElementSibling).toHaveTextContent(
      'R$ 30.000,00',
    );
    expect(cards.getByText('A receber').nextElementSibling).toHaveTextContent(
      'R$ 15.000,00',
    );
    expect(cards.getByText('Líquida').nextElementSibling).toHaveTextContent(
      'R$ 8.250,00',
    );
    expect(
      cards.getByText(/Recebida menos a comissão: R\$\s4\.750,00/),
    ).toBeInTheDocument();
    expect(cards.getByText('Resultado').nextElementSibling).toHaveTextContent(
      'R$ 5.250,00',
    );
    expect(cards.getByText(/3 atendimentos previstos/)).toBeInTheDocument();
  });

  it('CA-13 lists what was received by payment method', () => {
    render(view());
    const section = screen.getByRole('region', {
      name: 'Recebido por forma de pagamento',
    });

    expect(within(section).getByText('Dinheiro')).toBeInTheDocument();
    expect(within(section).getByText('PIX')).toBeInTheDocument();
  });

  it('CA-13 shows the 12-month table with the totals and the calendar of the month', () => {
    render(view());

    const year = screen.getByRole('table', {
      name: 'Balanço dos últimos 12 meses',
    });
    expect(within(year).getAllByRole('row')).toHaveLength(14);
    expect(within(year).getByText('Total')).toBeInTheDocument();
    const calendar = screen.getByRole('table', {
      name: 'Movimento de cada dia do mês',
    });
    expect(
      within(calendar).getByText(/Recebido R\$\s4\.000,00/),
    ).toBeInTheDocument();
    expect(within(calendar).getByText(/2 atend\./)).toBeInTheDocument();
  });

  it('CA-13 shows no goal until one is set, then the progress with the percentage', () => {
    const { unmount } = render(view());
    expect(screen.getByText('Nenhuma meta definida.')).toBeInTheDocument();
    unmount();

    render(view({ goal: 2000000 }));
    expect(screen.getByText(/de R\$\s20\.000,00 \(65%\)/)).toBeInTheDocument();
    expect(
      screen.getByRole('progressbar', { name: 'Progresso da meta' }),
    ).toHaveAttribute('value', '65');
  });

  it('CA-13 sets the goal in cents and shows the progress', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Definir meta' }));
    const dialog = screen.getByRole('dialog', { name: 'Meta do mês' });
    await user.type(within(dialog).getByLabelText(/^Meta mensal/), '20000');
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar meta' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PUT', 'finance/goal', {
        body: { monthly_goal_cents: 2000000 },
      }),
    );
    expect(await screen.findByText('Meta salva.')).toBeInTheDocument();
    expect(await screen.findByText(/\(65%\)/)).toBeInTheDocument();
  });

  it('CA-13 removes the goal when the field is left empty and shows API errors in the field', async () => {
    const user = userEvent.setup();
    render(view({ goal: 2000000 }));

    await user.click(screen.getByRole('button', { name: 'Alterar meta' }));
    const dialog = screen.getByRole('dialog', { name: 'Meta do mês' });
    expect(within(dialog).getByLabelText(/^Meta mensal/)).toHaveValue(
      '20000,00',
    );
    await user.clear(within(dialog).getByLabelText(/^Meta mensal/));
    failure = new ApiError('x', 422, {
      monthly_goal_cents: 'A meta é inválida.',
    });
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar meta' }),
    );

    expect(
      await within(dialog).findByText('A meta é inválida.'),
    ).toBeInTheDocument();
  });

  it('CA-13 loads the other months with the period and reloads all three views', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Mês anterior' }));

    await waitFor(() => expect(call).toHaveBeenCalledTimes(3));
    const paths = call.mock.calls.map((c) => String(c[1]));
    expect(paths).toContain('finance/summary?from=2026-10-01&to=2026-10-31');
    expect(paths).toContain('finance/monthly?to=2026-10');
    expect(paths).toContain('finance/calendar?month=2026-10');
    expect(
      await screen.findByRole('heading', { name: 'outubro de 2026' }),
    ).toBeInTheDocument();
  });

  it('CA-13 shows an error when the numbers cannot be loaded', () => {
    render(view({ initial: null }));

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar o financeiro agora.',
    );
  });

  it('CA-13 blocks the goal when read only or in a support access', () => {
    const { unmount } = render(view({ me: me('owner', { can_write: false }) }));
    expect(screen.getByRole('button', { name: 'Definir meta' })).toBeDisabled();
    unmount();

    render(view({ me: me('owner', { impersonating: true }) }));
    expect(
      screen.getByText(/não estão disponíveis em um acesso de suporte/),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Definir meta' })).toBeDisabled();
  });

  it('CA-13 has no accessibility violations (page, goal and dialog)', async () => {
    const user = userEvent.setup();
    const { container } = render(view({ goal: 2000000 }));
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Alterar meta' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
