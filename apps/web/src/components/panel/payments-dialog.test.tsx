import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type {
  Appointment,
  Payment,
  PaymentList,
} from '@/lib/appointment-types';
import { PaymentsDialog } from './payments-dialog';

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

const appointment = {
  id: 'a1',
  client: { id: 'c1', name: 'Bia Lima' },
  value_total_cents: 15000,
} as Appointment;

const payment = (over: Partial<Payment> = {}): Payment => ({
  id: 'p1',
  appointment_id: 'a1',
  type: 'deposit',
  method: 'pix',
  amount_cents: 5000,
  paid_at: '2026-11-03T13:00:00+00:00',
  status: 'received',
  voided_at: null,
  created_at: '2026-11-03T13:00:00Z',
  updated_at: '2026-11-03T13:00:00Z',
  ...over,
});

const list = (
  rows: Payment[] = [payment()],
  remaining = 10000,
): PaymentList => ({
  data: rows,
  summary: {
    total_cents: 15000,
    received_cents: 15000 - remaining,
    remaining_cents: remaining,
    payment_status: remaining === 0 ? 'paid' : 'partial',
    commission_percent: 40,
    commission_cents: 6000,
  },
});

const onChanged = vi.fn();
const onClose = vi.fn();

const open = (canWrite = true) =>
  render(
    <PaymentsDialog
      appointment={appointment}
      canWrite={canWrite}
      onClose={onClose}
      onChanged={onChanged}
    />,
  );

beforeEach(() => {
  failure = null;
  call.mockReset();
  onChanged.mockReset();
  onClose.mockReset();
  call.mockImplementation((method: string) =>
    Promise.resolve({
      status: 200,
      data: method === 'GET' ? list() : payment(),
    }),
  );
});

describe('PaymentsDialog', () => {
  it('CA-14 shows the summary and the list of payments', async () => {
    open();
    const dialog = screen.getByRole('dialog');

    expect(await within(dialog).findByText('R$ 150,00')).toBeInTheDocument();
    expect(within(dialog).getByText('R$ 100,00')).toBeInTheDocument();
    expect(within(dialog).getByText('40% · R$ 60,00')).toBeInTheDocument();
    const table = within(dialog).getByRole('table', {
      name: 'Pagamentos recebidos',
    });
    expect(within(table).getByText('Sinal')).toBeInTheDocument();
    expect(within(table).getByText('PIX')).toBeInTheDocument();
    expect(within(table).getByText('Recebido')).toBeInTheDocument();
  });

  it('CA-14 shows an empty state and a load error', async () => {
    call.mockResolvedValue({ status: 200, data: list([], 15000) });
    const { unmount } = open();
    expect(
      await screen.findByText('Nenhum pagamento registrado ainda.'),
    ).toBeInTheDocument();
    unmount();

    call.mockRejectedValue(new ApiError('x', 500));
    open();
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Não foi possível carregar os pagamentos agora.',
    );
  });

  it('CA-14 registers a payment with the amount in cents and the idempotency key, then reloads', async () => {
    const user = userEvent.setup();
    open();
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByText('R$ 150,00');

    await user.selectOptions(
      within(dialog).getByLabelText('Forma de pagamento'),
      'cash',
    );
    await user.type(within(dialog).getByLabelText(/^Valor \(R\$\)/), '50,50');
    await user.click(
      within(dialog).getByRole('button', { name: 'Registrar pagamento' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'appointments/a1/payments', {
        body: { type: 'deposit', method: 'cash', amount_cents: 5050 },
        idempotencyKey: expect.any(String),
      }),
    );
    expect(
      await within(dialog).findByText('Pagamento registrado.'),
    ).toBeInTheDocument();
    expect(onChanged).toHaveBeenCalled();
  });

  it('CA-14 refuses an empty or invalid amount before sending', async () => {
    const user = userEvent.setup();
    open();
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByText('R$ 150,00');

    await user.click(
      within(dialog).getByRole('button', { name: 'Registrar pagamento' }),
    );

    expect(within(dialog).getByLabelText(/^Valor \(R\$\)/)).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(call.mock.calls.filter((c) => c[0] === 'POST')).toHaveLength(0);
  });

  it('CA-14 explains when the amount passes what is left', async () => {
    const user = userEvent.setup();
    open();
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByText('R$ 150,00');
    await user.type(within(dialog).getByLabelText(/^Valor \(R\$\)/), '200');

    failure = new ApiError('x', 422, {}, 'payment_exceeds_total');
    await user.click(
      within(dialog).getByRole('button', { name: 'Registrar pagamento' }),
    );

    expect(
      await within(dialog).findByText(
        'O valor passa do que falta receber (R$ 100,00).',
      ),
    ).toBeInTheDocument();
  });

  it('CA-14 voids only after the confirmation', async () => {
    const user = userEvent.setup();
    open();
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByText('R$ 150,00');

    await user.click(
      within(dialog).getByRole('button', { name: /^Estornar: R\$\s50,00$/ }),
    );
    expect(call.mock.calls.filter((c) => c[0] === 'POST')).toHaveLength(0);
    await user.click(
      within(dialog).getByRole('button', { name: 'Confirmar estorno' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'payments/p1/void'),
    );
    expect(
      await within(dialog).findByText('Pagamento estornado.'),
    ).toBeInTheDocument();
    expect(onChanged).toHaveBeenCalled();
  });

  it('CA-14 offers no void on a voided payment and no form when it is read only', async () => {
    call.mockResolvedValue({
      status: 200,
      data: list(
        [payment({ status: 'voided', voided_at: '2026-11-03T14:00:00Z' })],
        15000,
      ),
    });
    open(false);
    const dialog = screen.getByRole('dialog');

    expect(await within(dialog).findByText('Estornado')).toBeInTheDocument();
    expect(
      within(dialog).queryByRole('button', { name: /Estornar/ }),
    ).not.toBeInTheDocument();
    expect(
      within(dialog).queryByRole('button', { name: 'Registrar pagamento' }),
    ).not.toBeInTheDocument();
    expect(within(dialog).getByText(/Somente consulta/)).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations', async () => {
    open();
    await screen.findByText('R$ 150,00');

    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});

describe('PaymentsDialog extra states', () => {
  it('CA-14 explains an appointment without value instead of hiding the form silently', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [],
        summary: {
          total_cents: 0,
          received_cents: 0,
          remaining_cents: 0,
          payment_status: 'no_value',
          commission_percent: 0,
          commission_cents: 0,
        },
      },
    });
    open();

    expect(
      await screen.findByText(
        'Defina o valor do agendamento antes de registrar pagamentos.',
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Registrar pagamento' }),
    ).not.toBeInTheDocument();
  });

  it('CA-14 shows a field error from the API next to the date field', async () => {
    const user = userEvent.setup();
    open();
    const dialog = screen.getByRole('dialog');
    await within(dialog).findByText('R$ 150,00');
    await user.type(within(dialog).getByLabelText(/^Valor \(R\$\)/), '10');

    failure = new ApiError('x', 422, {
      paid_at: 'A data do pagamento não pode estar no futuro.',
    });
    await user.click(
      within(dialog).getByRole('button', { name: 'Registrar pagamento' }),
    );

    expect(
      await within(dialog).findByText(
        'A data do pagamento não pode estar no futuro.',
      ),
    ).toBeInTheDocument();
  });
});
