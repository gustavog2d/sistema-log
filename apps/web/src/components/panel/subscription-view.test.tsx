import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { INVOICES, me, subscription } from './panel-fixtures';
import { SubscriptionView } from './subscription-view';

const call = vi.fn();
const refresh = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ refresh, replace: vi.fn(), push: vi.fn() }),
}));

beforeEach(() => {
  vi.stubEnv('NEXT_PUBLIC_PAYMENT_MODE', 'fake');
  call.mockReset();
  refresh.mockReset();
});

const view = (
  s = subscription(),
  m = me(),
  invoices = { data: INVOICES, next_cursor: null as string | null },
) => <SubscriptionView me={m} subscription={s} invoices={invoices} />;

async function openChange(user: ReturnType<typeof userEvent.setup>) {
  await user.click(
    screen.getByRole('button', { name: 'Trocar o meio de pagamento' }),
  );
}

describe('SubscriptionView: overview', () => {
  it('CA-06 shows plan, cycle, price, next charge and only the brand and the last digits of the card', () => {
    render(view());

    expect(screen.getAllByText('Profissional').length).toBeGreaterThan(0);
    expect(screen.getByText('Mensal')).toBeInTheDocument();
    expect(screen.getAllByText(/R\$\s?50,00/).length).toBeGreaterThan(0);
    expect(screen.getByText('20/11/2026')).toBeInTheDocument();
    expect(screen.getByText('visa final 4242')).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/pm_|cus_|gateway/);
  });

  it('CA-06 lists the invoices with period, amount, situation and date', () => {
    render(view());
    const table = screen.getByRole('table', { name: 'Faturas da assinatura' });

    expect(within(table).getAllByRole('row')).toHaveLength(3);
    expect(within(table).getByText('Paga')).toBeInTheDocument();
    expect(within(table).getByText('Falhou')).toBeInTheDocument();
    expect(
      within(table).getByText(/20\/09\/2026 – 20\/10\/2026/),
    ).toBeInTheDocument();
  });

  it('CA-06 loads more invoices by cursor and appends them', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [
          {
            ...INVOICES[0]!,
            id: 'i-3',
            period_start: '2026-07-20T12:00:00Z',
            period_end: '2026-08-20T12:00:00Z',
          },
        ],
        next_cursor: null,
      },
    });
    const user = userEvent.setup();
    render(
      view(subscription(), me(), { data: INVOICES, next_cursor: 'abc def' }),
    );

    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    expect(
      await screen.findByText(/20\/07\/2026 – 20\/08\/2026/),
    ).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'subscription/invoices?limit=20&cursor=abc%20def',
    );
    expect(
      screen.queryByRole('button', { name: 'Carregar mais' }),
    ).not.toBeInTheDocument();
  });

  it('shows an empty message without invoices and a message without a payment method', () => {
    render(
      view(subscription({ payment_method: null }), me(), {
        data: [],
        next_cursor: null,
      }),
    );

    expect(screen.getByText('Nenhuma fatura ainda.')).toBeInTheDocument();
    expect(
      screen.getByText('Nenhum meio de pagamento cadastrado.'),
    ).toBeInTheDocument();
  });

  it('CA-16 has no accessibility violations (active, suspended, canceled)', async () => {
    const { container, rerender } = render(view());
    expect(await axe(container)).toHaveNoViolations();

    rerender(view(subscription({ status: 'suspended', can_write: false })));
    expect(await axe(container)).toHaveNoViolations();

    rerender(view(subscription({ status: 'canceled', can_write: false })));
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('SubscriptionView: payment method', () => {
  it('CA-07 swaps the card with an Idempotency-Key and refreshes the page', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(view());
    await openChange(user);

    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    const [method, path, options] = call.mock.calls[0] as [
      string,
      string,
      { body: unknown; idempotencyKey: string },
    ];
    expect([method, path, options.body]).toEqual([
      'PUT',
      'subscription/payment-method',
      { payment_method_token: 'tok_ok' },
    ]);
    expect(options.idempotencyKey).toBeTruthy();
    expect(screen.getByRole('status')).toHaveTextContent(
      'Meio de pagamento atualizado.',
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('CA-07 says the subscription is back when a suspended one is regularized', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(view(subscription({ status: 'suspended', can_write: false })));
    await openChange(user);

    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText(
        'Pagamento aprovado: a assinatura voltou a funcionar.',
      ),
    ).toBeInTheDocument();
  });

  it('CA-07 shows a refused charge inside the dialog and keeps it open to try another card', async () => {
    call.mockRejectedValue(new ApiError('x', 402, {}, 'payment_failed'));
    const user = userEvent.setup();
    render(view(subscription({ status: 'suspended', can_write: false })));
    await openChange(user);

    await user.click(
      screen.getByRole('radio', {
        name: 'Cartão de teste que recusa na cobrança',
      }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText(
        'O pagamento foi recusado. Tente outro meio de pagamento.',
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
  });

  it('CA-07 explains a suspension made by the staff', async () => {
    call.mockRejectedValue(new ApiError('x', 409, {}, 'suspended_by_support'));
    const user = userEvent.setup();
    render(view(subscription({ status: 'suspended', can_write: false })));
    await openChange(user);

    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText(/suspensa pela nossa equipe/),
    ).toBeInTheDocument();
  });

  it('CA-07 shows a declined card from the validation error', async () => {
    call.mockRejectedValue(
      new ApiError('x', 422, {
        payment_method_token: 'O meio de pagamento foi recusado.',
      }),
    );
    const user = userEvent.setup();
    render(view());
    await openChange(user);

    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste recusado agora' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText(
        'O pagamento foi recusado. Tente outro meio de pagamento.',
      ),
    ).toBeInTheDocument();
  });

  it('CA-07 reuses the key for the same card and asks for a choice before sending', async () => {
    call.mockRejectedValue(new ApiError('Tente de novo.', 0));
    const user = userEvent.setup();
    render(view());
    await openChange(user);

    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    expect(call).not.toHaveBeenCalled();
    expect(screen.getByText('Preencha este campo')).toBeInTheDocument();

    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    await screen.findByText('Tente de novo.');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));

    const keys = (
      call.mock.calls as Array<[string, string, { idempotencyKey: string }]>
    ).map((c) => c[2].idempotencyKey);
    expect(keys[0]).toBe(keys[1]);
  });

  it('does not offer a real card change while the gateway is not available', async () => {
    vi.stubEnv('NEXT_PUBLIC_PAYMENT_MODE', '');
    const user = userEvent.setup();
    render(view());
    await openChange(user);

    expect(
      screen.getByText(/troca de pagamento real ainda não está disponível/),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Salvar' })).toBeDisabled();
  });
});

describe('SubscriptionView: cancel', () => {
  it('CA-08 explains the rule with the date before canceling, then registers and refreshes', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Cancelar a assinatura' }),
    );
    const dialog = screen.getByRole('dialog', {
      name: 'Cancelar a assinatura?',
    });
    expect(dialog).toHaveAccessibleDescription(
      /até 20\/11\/2026.*Nada mais será cobrado.*não é devolvido/,
    );
    await user.click(
      within(dialog).getByRole('button', { name: 'Sim, cancelar' }),
    );

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    expect(call).toHaveBeenCalledWith('POST', 'subscription/cancel');
    expect(screen.getByRole('status')).toHaveTextContent(
      'Cancelamento registrado.',
    );
  });

  it('CA-08 explains that a trial never charges and that a suspended subscription ends now', async () => {
    const user = userEvent.setup();
    const { unmount } = render(
      view(
        subscription({
          status: 'trialing',
          trial_ends_at: '2026-10-30T12:00:00Z',
          current_period_end: null,
        }),
      ),
    );
    await user.click(
      screen.getByRole('button', { name: 'Cancelar a assinatura' }),
    );
    expect(screen.getByRole('dialog')).toHaveAccessibleDescription(
      /até 30\/10\/2026 e nada será cobrado/,
    );
    unmount();

    render(view(subscription({ status: 'suspended', can_write: false })));
    await user.click(
      screen.getByRole('button', { name: 'Cancelar a assinatura' }),
    );
    expect(screen.getByRole('dialog')).toHaveAccessibleDescription(
      /será encerrada agora/,
    );
  });

  it('CA-08 keeps the subscription when the person gives up', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Cancelar a assinatura' }),
    );
    await user.click(
      screen.getByRole('button', { name: 'Manter a assinatura' }),
    );

    expect(call).not.toHaveBeenCalled();
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('CA-08 shows the pending cancellation and lets the person undo it', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(view(subscription({ cancel_at_period_end: true })));

    expect(
      screen.getByText(
        /Cancelamento pedido: o acesso continua até 20\/11\/2026/,
      ),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Cancelar a assinatura' }),
    ).not.toBeInTheDocument();
    await user.click(
      screen.getByRole('button', { name: 'Desfazer o cancelamento' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'subscription/reactivate'),
    );
    expect(
      await screen.findByText('Cancelamento desfeito.'),
    ).toBeInTheDocument();
  });

  it('CA-08 hides the card change and the cancel for an ended subscription', () => {
    render(view(subscription({ status: 'canceled', can_write: false })));

    expect(
      screen.queryByRole('button', { name: 'Trocar o meio de pagamento' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Cancelar a assinatura' }),
    ).not.toBeInTheDocument();
  });

  it('CA-14 disables the changes in a support access and says why', () => {
    render(view(subscription(), me('owner', { impersonating: true })));

    expect(
      screen.getByText('Indisponível em um acesso de suporte.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Trocar o meio de pagamento' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Cancelar a assinatura' }),
    ).toBeDisabled();
  });
});
