import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { SignupFlow } from './signup-flow';
import { PLANS } from './site-helpers';

const call = vi.fn();
const push = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

type Call = [
  string,
  string,
  { body?: Record<string, unknown>; idempotencyKey?: string }?,
];
const calls = (path: string) =>
  (call.mock.calls as Call[]).filter(([, p]) => p.startsWith(path));

beforeEach(() => {
  vi.stubEnv('NEXT_PUBLIC_PAYMENT_MODE', 'fake');
  vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', 'fake');
  call.mockReset();
  push.mockReset();
  call.mockImplementation(async (_method: string, path: string) => {
    if (path.startsWith('signup/slug-availability'))
      return { status: 200, data: { available: true } };
    if (path === 'signup')
      return {
        status: 201,
        data: { subscription: { trial_ends_at: '2026-10-30T12:00:00+00:00' } },
      };

    return { status: 200, data: null };
  });
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.useRealTimers();
});

type User = ReturnType<typeof userEvent.setup>;

async function step1(user: User, name = 'Estúdio Tinta Viva') {
  await user.type(screen.getByLabelText(/Nome do negócio/), name);
  await user.selectOptions(screen.getByLabelText(/Seu nicho/), 'tatuagem');
}

async function toStep2(user: User) {
  await step1(user);
  await waitFor(() =>
    expect(screen.getByText('Endereço livre')).toBeInTheDocument(),
  );
  await user.click(screen.getByRole('button', { name: 'Continuar' }));
}

async function step2(user: User, password = 'uma-senha-forte-123') {
  await user.type(screen.getByLabelText(/Seu nome/), 'Ana Souza');
  await user.type(screen.getByLabelText(/Seu e-mail/), 'ana@exemplo.com');
  await user.type(screen.getByLabelText(/^Senha/), password);
  await user.click(screen.getByRole('button', { name: 'Continuar' }));
}

async function toStep4(user: User) {
  await toStep2(user);
  await step2(user);
  await user.click(screen.getByRole('button', { name: 'Continuar' }));
}

describe('SignupFlow: step 1 (business)', () => {
  it('CA-06 suggests the address from the name and checks it after a pause', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);

    await user.type(
      screen.getByLabelText(/Nome do negócio/),
      'Estúdio Tinta Viva',
    );

    expect(screen.getByLabelText(/Endereço do seu painel/)).toHaveValue(
      'estudio-tinta-viva',
    );
    expect(await screen.findByText('Endereço livre')).toBeInTheDocument();
    expect(calls('signup/slug-availability').length).toBeLessThanOrEqual(2);
    expect(calls('signup/slug-availability').at(-1)![1]).toBe(
      'signup/slug-availability?slug=estudio-tinta-viva',
    );
  });

  it('CA-06 does not call the API for each key typed (debounce) and ignores stale answers', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    render(<SignupFlow plans={PLANS} />);

    await user.type(screen.getByLabelText(/Nome do negócio/), 'Estudio');
    expect(calls('signup/slug-availability')).toHaveLength(0);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(500);
    });

    expect(calls('signup/slug-availability')).toHaveLength(1);
  });

  it.each([
    ['taken', 'Este endereço já está em uso'],
    ['reserved', 'Este endereço não pode ser usado'],
  ])(
    'CA-06 shows when the address is %s and does not move on',
    async (reason, text) => {
      call.mockImplementation(async (_m: string, path: string) => ({
        status: 200,
        data: path.startsWith('signup/slug')
          ? { available: false, reason }
          : null,
      }));
      const user = userEvent.setup();
      render(<SignupFlow plans={PLANS} />);
      await step1(user);

      expect(await screen.findByText(text)).toBeInTheDocument();
      await user.click(screen.getByRole('button', { name: 'Continuar' }));

      expect(
        screen.getByRole('heading', { level: 1, name: 'Seu negócio' }),
      ).toBeInTheDocument();
    },
  );

  it('CA-06 shows an invalid address at once, without calling the API', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await user.type(screen.getByLabelText(/Nome do negócio/), 'Estúdio');
    const slug = screen.getByLabelText(/Endereço do seu painel/);
    await user.clear(slug);
    await user.type(slug, 'Ab');

    expect(
      screen.getByText(/Use de 3 a 30 letras minúsculas/),
    ).toBeInTheDocument();
    expect(slug).toHaveValue('ab');
    expect(calls('signup/slug-availability')).toHaveLength(0);
  });

  it('asks for the required fields before moving on', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(
      screen.getAllByText('Preencha este campo').length,
    ).toBeGreaterThanOrEqual(2);
    expect(screen.getByLabelText(/Nome do negócio/)).toHaveAttribute(
      'aria-invalid',
      'true',
    );
  });
});

describe('SignupFlow: step 2 (owner)', () => {
  it('CA-07 says how many characters are missing and does not move on with a short password', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep2(user);
    await user.type(screen.getByLabelText(/Seu nome/), 'Ana');
    await user.type(screen.getByLabelText(/Seu e-mail/), 'ana@exemplo.com');
    await user.type(screen.getByLabelText(/^Senha/), 'curta123');

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(screen.getByText('Faltam 4 caracteres')).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: 'Você' }),
    ).toBeInTheDocument();
  });

  it('CA-07 refuses a password above 72 bytes and an invalid e-mail', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep2(user);
    await user.type(screen.getByLabelText(/Seu nome/), 'Ana');
    await user.type(screen.getByLabelText(/Seu e-mail/), 'sem-arroba');
    await user.type(screen.getByLabelText(/^Senha/), 'a'.repeat(73));

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(screen.getByText('Informe um e-mail válido')).toBeInTheDocument();
    expect(
      screen.getByText('A senha pode ter até 72 caracteres'),
    ).toBeInTheDocument();
  });

  it('hides the password by default and shows it on request', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep2(user);
    const password = screen.getByLabelText(/^Senha/);

    expect(password).toHaveAttribute('type', 'password');
    expect(password).toHaveAttribute('autocomplete', 'new-password');
    await user.click(screen.getByLabelText('Mostrar senha'));
    expect(password).toHaveAttribute('type', 'text');
  });

  it('CA-12 keeps what was typed when going back and forth', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep2(user);
    await user.type(screen.getByLabelText(/Seu nome/), 'Ana');

    await user.click(screen.getByRole('button', { name: 'Voltar' }));
    expect(screen.getByLabelText(/Nome do negócio/)).toHaveValue(
      'Estúdio Tinta Viva',
    );
    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(screen.getByLabelText(/Seu nome/)).toHaveValue('Ana');
  });
});

describe('SignupFlow: step 3 (plan and coupon)', () => {
  async function toStep3(user: User) {
    await toStep2(user);
    await step2(user);
  }

  it('CA-08 preselects the plan from the link and shows prices of the cycle with the yearly saving', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="estudio" />);
    await toStep3(user);

    expect(screen.getByRole('radio', { name: /Estúdio/ })).toBeChecked();
    expect(screen.getByText(/R\$\s?250,00/)).toBeInTheDocument();
    await user.click(screen.getByRole('radio', { name: /Anual/ }));
    expect(screen.getByText(/R\$\s?2\.700,00/)).toBeInTheDocument();
    expect(
      screen.getAllByText(/economize R\$\s?300,00/).length,
    ).toBeGreaterThan(0);
  });

  it('CA-08 applies a coupon and shows the discount', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path === 'signup/coupon-check')
        return {
          status: 200,
          data: {
            percent_off: 20,
            price_cents: 5000,
            final_price_cents: 4000,
            requires_payment_method: true,
          },
        };
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="profissional" />);
    await toStep3(user);

    await user.type(screen.getByLabelText(/Cupom/), 'PROMO20');
    await user.click(screen.getByRole('button', { name: 'Aplicar cupom' }));

    expect(await screen.findByText(/20% de desconto/)).toBeInTheDocument();
    expect(
      (calls('signup/coupon-check')[0]![2] as { body: unknown }).body,
    ).toEqual({ code: 'PROMO20', plan: 'profissional', cycle: 'monthly' });
  });

  it('CA-08 shows the same message for any unavailable coupon', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path === 'signup/coupon-check')
        throw new ApiError('Cupom inválido ou indisponível.', 422, {
          code: 'Cupom inválido ou indisponível.',
        });
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep3(user);

    await user.type(screen.getByLabelText(/Cupom/), 'NADA');
    await user.click(screen.getByRole('button', { name: 'Aplicar cupom' }));

    expect(
      await screen.findByText('Cupom inválido ou indisponível.'),
    ).toBeInTheDocument();
  });

  it('CA-08 asks again when the plan changes after a coupon was applied', async () => {
    call.mockImplementation(
      async (
        _m: string,
        path: string,
        options?: { body?: { plan?: string } },
      ) => {
        if (path === 'signup/coupon-check')
          return {
            status: 200,
            data: {
              percent_off: 10,
              price_cents: 1,
              final_price_cents:
                options?.body?.plan === 'essencial' ? 1350 : 4500,
              requires_payment_method: true,
            },
          };
        if (path.startsWith('signup/slug'))
          return { status: 200, data: { available: true } };

        return { status: 200, data: null };
      },
    );
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="profissional" />);
    await toStep3(user);
    await user.type(screen.getByLabelText(/Cupom/), 'PROMO10');
    await user.click(screen.getByRole('button', { name: 'Aplicar cupom' }));
    await screen.findByText(/10% de desconto/);

    await user.click(screen.getByRole('radio', { name: /Essencial/ }));

    await waitFor(() => expect(calls('signup/coupon-check')).toHaveLength(2));
    expect(
      (calls('signup/coupon-check')[1]![2] as { body: { plan: string } }).body
        .plan,
    ).toBe('essencial');
  });
});

describe('SignupFlow: step 4 (payment) and submit', () => {
  it('CA-09 offers the test cards with a test notice and sends the chosen token with the terms', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="profissional" />);
    await toStep4(user);

    expect(screen.getByText(/Ambiente de teste/)).toBeInTheDocument();
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    await waitFor(() =>
      expect(calls('signup').filter((c) => c[1] === 'signup')).toHaveLength(1),
    );
    const [, , options] = calls('signup').find((c) => c[1] === 'signup')!;
    expect(options!.body).toEqual({
      business_name: 'Estúdio Tinta Viva',
      slug: 'estudio-tinta-viva',
      niche: 'tatuagem',
      owner_name: 'Ana Souza',
      owner_email: 'ana@exemplo.com',
      password: 'uma-senha-forte-123',
      plan: 'profissional',
      cycle: 'monthly',
      accept_terms: true,
      payment_method_token: 'tok_ok',
      captcha_token: 'captcha_ok',
    });
  });

  it('CA-13 goes to the confirmation page with the address and the trial end', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    await waitFor(() =>
      expect(push).toHaveBeenCalledWith(
        `/cadastro/pronto?negocio=estudio-tinta-viva&fim=${encodeURIComponent('2026-10-30T12:00:00+00:00')}`,
      ),
    );
  });

  it('shows the trial rule and the price in the summary', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="profissional" />);
    await toStep4(user);

    const summary = screen.getByRole('region', { name: 'Resumo' });
    expect(
      within(summary).getByText(/Plano Profissional, cobrança mensal/),
    ).toBeInTheDocument();
    expect(
      within(summary).getByText(/R\$\s?50,00 por mês/),
    ).toBeInTheDocument();
    expect(within(summary).getByText(/7 dias grátis/)).toBeInTheDocument();
  });

  it('requires a payment choice and the terms', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    expect(
      screen.getByText('É preciso aceitar os termos para continuar'),
    ).toBeInTheDocument();
    expect(calls('signup').filter((c) => c[1] === 'signup')).toHaveLength(0);
  });

  it('CA-10 drops the payment step for a courtesy coupon and sends no token', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path === 'signup/coupon-check')
        return {
          status: 200,
          data: {
            percent_off: 100,
            price_cents: 5000,
            final_price_cents: 0,
            requires_payment_method: false,
          },
        };
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        return {
          status: 201,
          data: {
            subscription: { trial_ends_at: '2026-10-30T12:00:00+00:00' },
          },
        };

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep2(user);
    await step2(user);
    await user.type(screen.getByLabelText(/Cupom/), 'CORTESIA');
    await user.click(screen.getByRole('button', { name: 'Aplicar cupom' }));
    await screen.findByText(/Sem cobrança com este cupom/);
    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(
      screen.getByText('Não é preciso cadastrar pagamento com este cupom.'),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('radio', { name: /Cartão de teste/ }),
    ).not.toBeInTheDocument();
    expect(screen.getByText('Sem cobrança.')).toBeInTheDocument();
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    await waitFor(() => expect(push).toHaveBeenCalled());
    const body = (
      calls('signup').find((c) => c[1] === 'signup')![2] as {
        body: Record<string, unknown>;
      }
    ).body;
    expect(body).not.toHaveProperty('payment_method_token');
    expect(body.coupon_code).toBe('CORTESIA');
  });

  it('CA-09 does not offer a real payment while the gateway is not available', async () => {
    vi.stubEnv('NEXT_PUBLIC_PAYMENT_MODE', '');
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);

    expect(
      screen.getByText(/pagamento real ainda não está disponível/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Criar minha conta' }),
    ).toBeDisabled();
  });

  async function finish(user: User) {
    await toStep4(user);
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
  }

  it('CA-11 sends the same Idempotency-Key for the same data and a new one when the data changes', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup') throw new ApiError('Tente de novo.', 0);

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await finish(user);

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    await screen.findByRole('alert');
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    await waitFor(() =>
      expect(calls('signup').filter((c) => c[1] === 'signup')).toHaveLength(2),
    );
    await user.click(
      screen.getByRole('radio', {
        name: 'Cartão de teste que recusa na cobrança',
      }),
    );
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    await waitFor(() =>
      expect(calls('signup').filter((c) => c[1] === 'signup')).toHaveLength(3),
    );

    const keys = calls('signup')
      .filter((c) => c[1] === 'signup')
      .map((c) => c[2]!.idempotencyKey);
    expect(keys[0]).toBeTruthy();
    expect(keys[1]).toBe(keys[0]);
    expect(keys[2]).not.toBe(keys[0]);
  });

  it('CA-11 is busy while sending, so a double click creates one account', async () => {
    let resolve!: (value: unknown) => void;
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup') return new Promise((done) => (resolve = done));

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await finish(user);

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    const busy = await screen.findByRole('button', {
      name: /Criando sua conta/,
    });
    await user.click(busy);

    expect(busy).toBeDisabled();
    expect(calls('signup').filter((c) => c[1] === 'signup')).toHaveLength(1);
    resolve({
      status: 201,
      data: { subscription: { trial_ends_at: '2026-10-30T12:00:00+00:00' } },
    });
  });

  it('CA-12 goes back to the step of the field the API refused, with the message, keeping the data', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        throw new ApiError('Dados inválidos.', 422, {
          owner_email: 'Este e-mail já está cadastrado.',
        });

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await finish(user);

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    expect(
      await screen.findByText('Este e-mail já está cadastrado.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: 'Você' }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/Seu e-mail/)).toHaveValue('ana@exemplo.com');
    expect(push).not.toHaveBeenCalled();
  });

  it('CA-12 shows a refused card on the payment step', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        throw new ApiError('Dados inválidos.', 422, {
          payment_method_token: 'O meio de pagamento foi recusado.',
        });

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await finish(user);

    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    expect(
      await screen.findByText('O meio de pagamento foi recusado.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: 'Forma de pagamento' }),
    ).toBeInTheDocument();
  });

  it.each([
    [429, /Muitos pedidos/],
    [409, /ainda está sendo processado/],
  ])(
    'CA-12 shows a notice for status %s and keeps the data',
    async (status, text) => {
      call.mockImplementation(async (_m: string, path: string) => {
        if (path.startsWith('signup/slug'))
          return { status: 200, data: { available: true } };
        if (path === 'signup') throw new ApiError('x', status as number);

        return { status: 200, data: null };
      });
      const user = userEvent.setup();
      render(<SignupFlow plans={PLANS} />);
      await finish(user);

      await user.click(
        screen.getByRole('button', { name: 'Criar minha conta' }),
      );

      expect(await screen.findByRole('alert')).toHaveTextContent(text);
      expect(
        screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
      ).toBeChecked();
    },
  );

  it('CA-16 never puts the password in the URL, in local/session storage or in the console', async () => {
    const log = vi.spyOn(console, 'log');
    const error = vi.spyOn(console, 'error');
    sessionStorage.clear();
    localStorage.clear();
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await finish(user);
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    await waitFor(() => expect(push).toHaveBeenCalled());

    const everything = JSON.stringify([
      window.location.href,
      { ...localStorage },
      { ...sessionStorage },
      push.mock.calls,
      log.mock.calls,
      error.mock.calls,
    ]);
    expect(everything).not.toContain('uma-senha-forte-123');
  });
});

describe('SignupFlow: review fixes', () => {
  async function toPlanStep(user: User) {
    await toStep2(user);
    await step2(user);
  }

  it('I-1 checks a typed but not applied coupon before moving on, and stays when it is invalid', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path === 'signup/coupon-check')
        throw new ApiError('x', 422, {
          code: 'Cupom inválido ou indisponível.',
        });
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toPlanStep(user);
    await user.type(screen.getByLabelText(/Cupom/), 'ERRADO');

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(
      await screen.findByText('Cupom inválido ou indisponível.'),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: 'Escolha o plano' }),
    ).toBeInTheDocument();
  });

  it('I-1 applies a typed coupon on Continue so the summary matches what is sent', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path === 'signup/coupon-check')
        return {
          status: 200,
          data: {
            percent_off: 100,
            price_cents: 5000,
            final_price_cents: 0,
            requires_payment_method: false,
          },
        };
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toPlanStep(user);
    await user.type(screen.getByLabelText(/Cupom/), 'CORTESIA');

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText('Sem cobrança.')).toBeInTheDocument();
    expect(
      screen.queryByRole('radio', { name: /Cartão de teste/ }),
    ).not.toBeInTheDocument();
  });

  it('I-2 ignores a slow answer of an old plan when the plan changed again', async () => {
    const pending: Record<string, (value: unknown) => void> = {};
    call.mockImplementation(
      async (
        _m: string,
        path: string,
        options?: { body?: { plan?: string } },
      ) => {
        if (path === 'signup/coupon-check') {
          const plan = options?.body?.plan ?? '';
          if (plan === 'essencial')
            return new Promise((done) => (pending.essencial = done));

          return {
            status: 200,
            data: {
              percent_off: 10,
              price_cents: 5000,
              final_price_cents: 4500,
              requires_payment_method: true,
            },
          };
        }
        if (path.startsWith('signup/slug'))
          return { status: 200, data: { available: true } };

        return { status: 200, data: null };
      },
    );
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} initialPlan="profissional" />);
    await toPlanStep(user);
    await user.type(screen.getByLabelText(/Cupom/), 'PROMO10');
    await user.click(screen.getByRole('button', { name: 'Aplicar cupom' }));
    await screen.findByText(/10% de desconto/);

    await user.click(screen.getByRole('radio', { name: /Essencial/ }));
    await user.click(screen.getByRole('radio', { name: /Estúdio/ }));
    await waitFor(() => expect(calls('signup/coupon-check')).toHaveLength(3));
    await act(async () =>
      pending.essencial!({
        status: 200,
        data: {
          percent_off: 99,
          price_cents: 1,
          final_price_cents: 1,
          requires_payment_method: true,
        },
      }),
    );

    expect(screen.getByText(/10% de desconto/)).toBeInTheDocument();
    expect(screen.queryByText(/99% de desconto/)).not.toBeInTheDocument();
  });

  it('I-3 moves the focus to the first invalid field when the step is refused', async () => {
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);

    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(screen.getByLabelText(/Nome do negócio/)).toHaveFocus();
  });

  it('I-3 also focuses the field of a server error that stays on the same step', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        throw new ApiError('x', 422, { password: 'A senha é muito comum.' });

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    expect(
      await screen.findByText('A senha é muito comum.'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/^Senha/)).toHaveFocus();
  });

  it('clears the server error of a field as soon as the person edits it', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        throw new ApiError('x', 422, {
          owner_email: 'Este e-mail já está cadastrado.',
        });

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste aprovado' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));
    await screen.findByText('Este e-mail já está cadastrado.');

    await user.type(screen.getByLabelText(/Seu e-mail/), 'x');

    expect(
      screen.queryByText('Este e-mail já está cadastrado.'),
    ).not.toBeInTheDocument();
  });

  it('announces a payment error and a plan error', async () => {
    call.mockImplementation(async (_m: string, path: string) => {
      if (path.startsWith('signup/slug'))
        return { status: 200, data: { available: true } };
      if (path === 'signup')
        throw new ApiError('x', 422, {
          payment_method_token: 'O meio de pagamento foi recusado.',
        });

      return { status: 200, data: null };
    });
    const user = userEvent.setup();
    render(<SignupFlow plans={PLANS} />);
    await toStep4(user);
    await user.click(
      screen.getByRole('radio', { name: 'Cartão de teste recusado agora' }),
    );
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Criar minha conta' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'O meio de pagamento foi recusado.',
    );
  });
});

describe('SignupFlow: accessibility', () => {
  it('CA-15 marks the current step, moves the focus to the step title and has no violations in any step', async () => {
    const user = userEvent.setup();
    const { container } = render(<SignupFlow plans={PLANS} />);

    expect(screen.getByRole('listitem', { current: 'step' })).toHaveTextContent(
      '1. Seu negócio',
    );
    expect(await axe(container)).toHaveNoViolations();

    await toStep2(user);
    expect(
      screen.getByRole('heading', { level: 1, name: 'Você' }),
    ).toHaveFocus();
    expect(screen.getByRole('listitem', { current: 'step' })).toHaveTextContent(
      '2. Você',
    );
    expect(await axe(container)).toHaveNoViolations();

    await step2(user);
    expect(
      screen.getByRole('heading', { level: 1, name: 'Escolha o plano' }),
    ).toHaveFocus();
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(
      screen.getByRole('heading', { level: 1, name: 'Forma de pagamento' }),
    ).toHaveFocus();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('does not steal the focus when the page opens', () => {
    render(<SignupFlow plans={PLANS} />);

    expect(document.body).toHaveFocus();
  });

  it('shows a warning instead of the form when there are no plans', () => {
    render(<SignupFlow plans={null} />);

    expect(screen.getByRole('alert')).toHaveTextContent(/planos/i);
    expect(screen.queryByLabelText(/Nome do negócio/)).not.toBeInTheDocument();
  });
});
