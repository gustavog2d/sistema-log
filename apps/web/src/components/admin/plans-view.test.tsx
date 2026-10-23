import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';

import { plan } from './admin-fixtures';
import { PlansView } from './plans-view';

const call = vi.fn();
const refresh = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) =>
    failure ? Promise.reject(failure) : call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace: vi.fn(), refresh, push: vi.fn() }),
}));

beforeEach(() => {
  failure = null;
  call.mockReset();
  call.mockResolvedValue({ status: 200, data: {} });
  refresh.mockReset();
});

describe('PlansView', () => {
  it('CA-11 lists plans with prices and status', () => {
    render(
      <PlansView
        canWrite
        plans={[
          plan(),
          plan({
            id: 'p-2',
            key: 'novo',
            name: 'Novo',
            status: 'draft',
            monthly_price_cents: null,
            yearly_discount_percent: null,
            yearly_price_cents: null,
          }),
        ]}
      />,
    );

    const table = screen.getByRole('table', { name: 'Planos' });
    expect(within(table).getByText('Profissional')).toBeInTheDocument();
    expect(within(table).getByText('Rascunho')).toBeInTheDocument();
    expect(within(table).getAllByText('—').length).toBeGreaterThanOrEqual(3);
  });

  it('CA-11 hides editing from the support profile', () => {
    render(<PlansView canWrite={false} plans={[plan()]} />);

    expect(
      screen.queryByRole('button', { name: /Alterar/ }),
    ).not.toBeInTheDocument();
  });

  it('CA-11 shows the rules of a plan read-only, for any profile', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [
          {
            feature: 'usuarios.max',
            enabled: true,
            limit: 5,
            visible: true,
            source: 'plan',
          },
        ],
      },
    });
    const user = userEvent.setup();
    render(<PlansView canWrite={false} plans={[plan()]} />);

    await user.click(
      screen.getByRole('button', { name: 'Regras: Profissional' }),
    );

    const table = await screen.findByRole('table', { name: 'Regras do plano' });
    expect(within(table).getByText('usuarios.max')).toBeInTheDocument();
    expect(within(table).getByText('5')).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'platform/plans/profissional/entitlements',
    );
    expect(
      screen.queryByRole('button', { name: /Alterar/ }),
    ).not.toBeInTheDocument();
  });

  it('CA-11 tells when the plan has no rules or the rules fail to load', async () => {
    call.mockResolvedValue({ status: 200, data: { data: [] } });
    const user = userEvent.setup();
    const { unmount } = render(<PlansView canWrite plans={[plan()]} />);

    await user.click(
      screen.getByRole('button', { name: 'Regras: Profissional' }),
    );
    expect(await screen.findByText(/não tem regras/)).toBeInTheDocument();
    unmount();

    failure = new ApiError('Falhou', 500);
    render(<PlansView canWrite plans={[plan()]} />);
    await user.click(
      screen.getByRole('button', { name: 'Regras: Profissional' }),
    );
    expect(await screen.findByRole('alert')).toBeInTheDocument();
  });

  it('CA-11 edits a plan converting reais to cents', async () => {
    const user = userEvent.setup();
    render(<PlansView canWrite plans={[plan()]} />);

    await user.click(
      screen.getByRole('button', { name: 'Alterar: Profissional' }),
    );
    expect(screen.getByLabelText(/^Preço mensal/)).toHaveValue('50,00');
    await user.clear(screen.getByLabelText(/^Preço mensal/));
    await user.type(screen.getByLabelText(/^Preço mensal/), '54,90');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'PATCH',
        'platform/plans/profissional',
        {
          body: {
            name: 'Profissional',
            position: 2,
            status: 'active',
            trial_days: 7,
            monthly_price_cents: 5490,
            yearly_discount_percent: 10,
          },
        },
      ),
    );
    expect(screen.getByRole('status')).toHaveTextContent('Plano atualizado.');
  });

  it('CA-11 refuses an invalid price without calling the API', async () => {
    const user = userEvent.setup();
    render(<PlansView canWrite plans={[plan()]} />);

    await user.click(
      screen.getByRole('button', { name: 'Alterar: Profissional' }),
    );
    await user.clear(screen.getByLabelText(/^Preço mensal/));
    await user.type(screen.getByLabelText(/^Preço mensal/), 'abc');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText(/Informe um valor em reais/),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-11 shows the API error (activating without price)', async () => {
    failure = new ApiError('x', 422, {
      status: 'Defina o preço antes de ativar.',
    });
    const user = userEvent.setup();
    render(<PlansView canWrite plans={[plan({ status: 'draft' })]} />);

    await user.click(
      screen.getByRole('button', { name: 'Alterar: Profissional' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText('Defina o preço antes de ativar.'),
    ).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations', async () => {
    const user = userEvent.setup();
    const { container } = render(<PlansView canWrite plans={[plan()]} />);

    expect(await axe(container)).toHaveNoViolations();
    await user.click(
      screen.getByRole('button', { name: 'Alterar: Profissional' }),
    );
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
