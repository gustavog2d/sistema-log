import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';

import { coupon } from './admin-fixtures';
import { CouponsView } from './coupons-view';

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

describe('CouponsView', () => {
  it('CA-10 lists the coupons with discount, uses and validity, and flags the courtesy ones', () => {
    render(
      <CouponsView
        canWrite
        coupons={[
          coupon(),
          coupon({
            id: 'c-2',
            code: 'CORTESIA-1',
            percent_off: 100,
            max_redemptions: 1,
            redeemed_count: 0,
          }),
        ]}
      />,
    );

    const table = screen.getByRole('table', { name: 'Cupons' });
    expect(within(table).getByText('AMIGA-2026')).toBeInTheDocument();
    expect(within(table).getByText('3 / 10')).toBeInTheDocument();
    expect(within(table).getByText('Cortesia')).toBeInTheDocument();
  });

  it('CA-10 hides create and edit from the support profile', () => {
    render(<CouponsView canWrite={false} coupons={[coupon()]} />);

    expect(
      screen.queryByRole('button', { name: 'Novo cupom' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /Alterar/ }),
    ).not.toBeInTheDocument();
    expect(screen.getByText(/Seu perfil é de consulta/)).toBeInTheDocument();
  });

  it('CA-10 creates a coupon with the validity in Brasília time and refreshes the list', async () => {
    const user = userEvent.setup();
    render(<CouponsView canWrite coupons={[]} />);

    expect(screen.getByText('Nenhum cupom criado.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Novo cupom' }));
    await user.type(screen.getByLabelText(/^Código/), 'VOLTA-2026');
    await user.type(screen.getByLabelText(/^Desconto/), '30');
    await user.type(screen.getByLabelText(/^Limite de usos/), '5');
    await user.type(screen.getByLabelText(/^Válido até/), '2026-12-31');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    expect(call).toHaveBeenCalledWith('POST', 'platform/coupons', {
      body: {
        code: 'VOLTA-2026',
        percent_off: 30,
        max_redemptions: 5,
        valid_from: null,
        valid_until: '2026-12-31T23:59:59-03:00',
        description: null,
      },
    });
    expect(screen.getByRole('status')).toHaveTextContent('Cupom criado.');
  });

  it('CA-10 warns about the courtesy and shows the API errors by field', async () => {
    failure = new ApiError('x', 422, {
      max_redemptions: 'Cupom de 100% precisa de limite.',
    });
    const user = userEvent.setup();
    render(<CouponsView canWrite coupons={[]} />);

    await user.click(screen.getByRole('button', { name: 'Novo cupom' }));
    await user.type(screen.getByLabelText(/^Código/), 'CORTESIA-9');
    await user.type(screen.getByLabelText(/^Desconto/), '100');
    expect(screen.getByText(/conta sem cartão/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText('Cupom de 100% precisa de limite.'),
    ).toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
  });

  it('CA-10 edits status and limits but not the code or the discount', async () => {
    const user = userEvent.setup();
    render(<CouponsView canWrite coupons={[coupon()]} />);

    await user.click(
      screen.getByRole('button', { name: 'Alterar: AMIGA-2026' }),
    );
    expect(screen.getByLabelText(/^Código/)).toBeDisabled();
    expect(screen.getByLabelText(/^Desconto/)).toBeDisabled();
    expect(screen.getByLabelText(/^Válido até/)).toHaveValue('2026-12-30');
    await user.selectOptions(screen.getByLabelText('Situação'), 'inactive');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'platform/coupons/c-1', {
        body: expect.objectContaining({
          status: 'inactive',
          max_redemptions: 10,
        }),
      }),
    );
  });

  it('CA-14 has no accessibility violations', async () => {
    const user = userEvent.setup();
    const { container } = render(<CouponsView canWrite coupons={[coupon()]} />);

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Novo cupom' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
