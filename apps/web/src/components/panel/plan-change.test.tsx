import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { PlanChangePreview } from '@/lib/panel-types';
import { subscription } from './panel-fixtures';
import { PlanChangeCard } from './plan-change';

const call = vi.fn();
const refresh = vi.fn();
const onNotice = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha da confirmação vai por uma função comum.
let putFailure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (method: string, ...rest: unknown[]) =>
    method === 'PUT' && putFailure
      ? Promise.reject(putFailure)
      : call(method, ...rest),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ refresh, replace: vi.fn(), push: vi.fn() }),
}));

const PLANS = [
  {
    key: 'essencial',
    name: 'Essencial',
    position: 1,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 1500,
    yearly_price_cents: 16200,
    features: [],
  },
  {
    key: 'profissional',
    name: 'Profissional',
    position: 2,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 5000,
    yearly_price_cents: 54000,
    features: [],
  },
  {
    key: 'estudio',
    name: 'Estúdio',
    position: 3,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 25000,
    yearly_price_cents: 270000,
    features: [],
  },
];

const preview = (over: Partial<PlanChangePreview> = {}): PlanChangePreview => ({
  kind: 'now',
  plan: { key: 'estudio', name: 'Estúdio' },
  price_cents: 25000,
  currency: 'BRL',
  charge_now_cents: 13333,
  effective_at: '2026-11-30T12:00:00Z',
  violations: [],
  ...over,
});

function respond(previewBody: PlanChangePreview) {
  call.mockImplementation(async (method: string, path: string) => {
    if (path === 'plans') return { status: 200, data: PLANS };
    if (path.startsWith('subscription/plan-change/preview'))
      return { status: 200, data: previewBody };

    return { status: 200, data: {} };
  });
}

beforeEach(() => {
  putFailure = null;
  call.mockReset();
  refresh.mockReset();
  onNotice.mockReset();
});

const card = (s = subscription(), disabled = false) => (
  <PlanChangeCard subscription={s} disabled={disabled} onNotice={onNotice} />
);

async function choose(user: ReturnType<typeof userEvent.setup>, name: string) {
  await user.click(screen.getByRole('button', { name: 'Trocar de plano' }));
  await user.click(await screen.findByLabelText(new RegExp(name)));
}

describe('PlanChangeCard', () => {
  it('CA-04 lists the other plans for the same cycle and previews an upgrade with the charge of today', async () => {
    respond(preview());
    const user = userEvent.setup();
    render(card());

    await choose(user, 'Estúdio');

    expect(screen.queryByLabelText(/^Profissional/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/^Essencial/)).toBeInTheDocument();
    expect(
      await screen.findByText(/Você paga R\$\s?133,33 hoje/),
    ).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'subscription/plan-change/preview?plan=estudio',
    );
  });

  it('CA-04 confirms an upgrade with an idempotency key, refreshes and tells it is active', async () => {
    respond(preview());
    const user = userEvent.setup();
    render(card());

    await choose(user, 'Estúdio');
    await screen.findByText(/Você paga/);
    await user.click(screen.getByRole('button', { name: 'Confirmar a troca' }));

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    const put = call.mock.calls.find(([method]) => method === 'PUT')!;
    expect(put[1]).toBe('subscription/plan');
    expect(put[2]).toMatchObject({ body: { plan: 'estudio' } });
    expect((put[2] as { idempotencyKey: string }).idempotencyKey).toMatch(
      /\S{8,}/,
    );
    expect(onNotice).toHaveBeenCalledWith({
      tone: 'success',
      text: 'Plano trocado. Você já usa o plano Estúdio.',
    });
  });

  it('CA-06 explains a downgrade only takes effect on the date of the next period', async () => {
    respond(
      preview({
        kind: 'next_cycle',
        plan: { key: 'essencial', name: 'Essencial' },
        price_cents: 1500,
        charge_now_cents: 0,
      }),
    );
    const user = userEvent.setup();
    render(card());

    await choose(user, 'Essencial');

    expect(
      await screen.findByText(/Vale a partir de 30\/11\/2026/),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Confirmar a troca' }));
    await waitFor(() =>
      expect(onNotice).toHaveBeenCalledWith({
        tone: 'success',
        text: 'Troca agendada para 30/11/2026.',
      }),
    );
  });

  it('CA-03 tells there is no charge during the trial', async () => {
    respond(preview({ charge_now_cents: 0 }));
    const user = userEvent.setup();
    render(card(subscription({ status: 'trialing' })));

    await choose(user, 'Estúdio');

    expect(
      await screen.findByText(/sem cobrança durante o teste/),
    ).toBeInTheDocument();
  });

  it('CA-07 blocks the confirmation and lists what passes the limit of the new plan', async () => {
    respond(
      preview({
        kind: 'next_cycle',
        violations: [{ feature: 'usuarios.max', limit: 2, usage: 3 }],
      }),
    );
    const user = userEvent.setup();
    render(card());

    await choose(user, 'Essencial');

    expect(
      await screen.findByText(/o plano permite 2, você tem 3/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Confirmar a troca' }),
    ).toBeDisabled();
  });

  it('CA-05 shows the declined payment and asks a new idempotency key on the next try', async () => {
    respond(preview());
    putFailure = new ApiError('x', 402, {}, 'payment_failed');
    const user = userEvent.setup();
    render(card());

    await choose(user, 'Estúdio');
    await screen.findByText(/Você paga/);
    await user.click(screen.getByRole('button', { name: 'Confirmar a troca' }));
    expect(
      await screen.findByText(/pagamento da diferença foi recusado/),
    ).toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();

    putFailure = null;
    await user.click(screen.getByRole('button', { name: 'Confirmar a troca' }));
    await waitFor(() => expect(refresh).toHaveBeenCalled());
    const keys = call.mock.calls
      .filter(([method]) => method === 'PUT')
      .map(
        ([, , options]) =>
          (options as { idempotencyKey: string }).idempotencyKey,
      );
    expect(keys).toHaveLength(1);
  });

  it('CA-06 uses a new idempotency key for the next change after one succeeded', async () => {
    respond(preview());
    const user = userEvent.setup();
    render(card());

    for (let round = 0; round < 2; round += 1) {
      await user.click(screen.getByRole('button', { name: 'Trocar de plano' }));
      await user.click(await screen.findByLabelText(/^Estúdio/));
      await screen.findByText(/Você paga/);
      await user.click(
        screen.getByRole('button', { name: 'Confirmar a troca' }),
      );
      await waitFor(() => expect(refresh).toHaveBeenCalledTimes(round + 1));
    }

    const keys = call.mock.calls
      .filter(([method]) => method === 'PUT')
      .map(
        ([, , options]) =>
          (options as { idempotencyKey: string }).idempotencyKey,
      );
    expect(keys).toHaveLength(2);
    expect(keys[0]).not.toBe(keys[1]);
  });

  it('CA-04 ignores a late simulation of a plan that is no longer the chosen one', async () => {
    let releaseFirst!: (value: unknown) => void;
    call.mockImplementation((method: string, path: string) => {
      if (path === 'plans')
        return Promise.resolve({ status: 200, data: PLANS });
      if (path.endsWith('plan=essencial'))
        return new Promise((done) => (releaseFirst = done));

      return Promise.resolve({ status: 200, data: preview() });
    });
    const user = userEvent.setup();
    render(card());

    await user.click(screen.getByRole('button', { name: 'Trocar de plano' }));
    await user.click(await screen.findByLabelText(/^Essencial/));
    await user.click(screen.getByLabelText(/^Estúdio/));
    await screen.findByText(/Você paga R\$\s?133,33/);
    releaseFirst({
      status: 200,
      data: preview({
        kind: 'next_cycle',
        plan: { key: 'essencial', name: 'Essencial' },
        price_cents: 1500,
        charge_now_cents: 0,
      }),
    });

    await waitFor(() =>
      expect(screen.queryByText(/Vale a partir de/)).not.toBeInTheDocument(),
    );
    expect(screen.getByText(/Você paga R\$\s?133,33/)).toBeInTheDocument();
  });

  it('CA-11 shows the scheduled change and undoes it', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(
      card(
        subscription({
          pending_plan_change: {
            plan: { key: 'essencial', name: 'Essencial' },
            price_cents: 1500,
            effective_at: '2026-11-30T12:00:00Z',
          },
        }),
      ),
    );

    expect(
      screen.getByText(/Troca agendada: Essencial a partir de 30\/11\/2026/),
    ).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Desfazer a troca' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'subscription/plan-change'),
    );
    expect(refresh).toHaveBeenCalled();
    expect(onNotice).toHaveBeenCalledWith({
      tone: 'success',
      text: 'Troca de plano desfeita.',
    });
  });

  it.each([
    ['cancellation pending', { cancel_at_period_end: true }],
    ['canceled', { status: 'canceled' as const }],
    ['suspended', { status: 'suspended' as const }],
  ])('CA-12 does not offer the change when %s', (_name, over) => {
    render(card(subscription(over)));

    expect(
      screen.queryByRole('button', { name: 'Trocar de plano' }),
    ).not.toBeInTheDocument();
  });

  it('CA-01 disables the change in a support access', () => {
    render(card(subscription(), true));

    expect(
      screen.getByRole('button', { name: 'Trocar de plano' }),
    ).toBeDisabled();
  });

  it('CA-12 shows the message of the API when the plan list fails', async () => {
    call.mockRejectedValue(new ApiError('x', 500));
    const user = userEvent.setup();
    render(card());

    await user.click(screen.getByRole('button', { name: 'Trocar de plano' }));

    expect(
      await screen.findByText(/Não foi possível carregar os planos/),
    ).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations (card and dialog)', async () => {
    respond(preview());
    const user = userEvent.setup();
    const { container } = render(card());

    expect(await axe(container)).toHaveNoViolations();
    await choose(user, 'Estúdio');
    await screen.findByText(/Você paga/);
    expect(
      await axe(within(screen.getByRole('dialog')).getByRole('group')),
    ).toHaveNoViolations();
  });
});
