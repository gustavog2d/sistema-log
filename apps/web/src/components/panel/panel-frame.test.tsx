import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { me, NOW, subscription } from './panel-fixtures';
import { bannersFor, daysLeft, navFor } from './panel-model';
import { PanelFrame } from './panel-frame';
import { HomeView } from './home-view';

const call = vi.fn();
const replace = vi.fn();
const refresh = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, refresh, push: vi.fn() }),
  usePathname: () => '/equipe',
}));

beforeEach(() => {
  call.mockReset();
  replace.mockReset();
});

describe('navFor', () => {
  it.each([
    [
      'owner',
      [
        'Início',
        'Assinatura',
        'Agenda',
        'Clientes',
        'Serviços',
        'Profissionais',
        'Equipe',
        'Histórico',
        'Minha conta',
      ],
    ],
    [
      'manager',
      [
        'Início',
        'Agenda',
        'Clientes',
        'Serviços',
        'Profissionais',
        'Equipe',
        'Histórico',
        'Minha conta',
      ],
    ],
    [
      'professional',
      [
        'Início',
        'Agenda',
        'Clientes',
        'Serviços',
        'Profissionais',
        'Minha conta',
      ],
    ],
    [
      'reception',
      [
        'Início',
        'Agenda',
        'Clientes',
        'Serviços',
        'Profissionais',
        'Minha conta',
      ],
    ],
  ] as const)('CA-04 shows only what a %s can use', (role, labels) => {
    expect(navFor(me(role)).map((item) => item.label)).toEqual(labels);
  });
});

describe('bannersFor', () => {
  const keys = (m = me(), s = subscription(), now = NOW) =>
    bannersFor(m, s, now).map((banner) => banner.key);

  it('CA-05 has no banner for an active subscription', () => {
    expect(keys()).toEqual([]);
  });

  it('CA-05 warns about a suspension, with the shortcut only for the owner', () => {
    const suspended = subscription({ status: 'suspended', can_write: false });
    const owner = bannersFor(me('owner'), suspended, NOW);
    const manager = bannersFor(me('manager'), suspended, NOW);

    expect(owner[0]!.action).toEqual({
      href: '/assinatura',
      label: 'Regularizar agora',
    });
    expect(manager[0]!.action).toBeUndefined();
    expect(manager.map((b) => b.key)).toEqual(['suspended', 'suspended-other']);
  });

  it('CA-05 says the subscription ended', () => {
    expect(
      keys(me(), subscription({ status: 'canceled', can_write: false })),
    ).toEqual(['canceled']);
  });

  it('CA-05 shows the pending cancellation with the date, in a trial and when active', () => {
    const active = bannersFor(
      me(),
      subscription({ cancel_at_period_end: true }),
      NOW,
    );
    const trial = bannersFor(
      me(),
      subscription({
        status: 'trialing',
        cancel_at_period_end: true,
        trial_ends_at: '2026-10-30T12:00:00Z',
        current_period_end: null,
      }),
      NOW,
    );

    expect(active[0]!.text).toContain('20/11/2026');
    expect(trial[0]!.text).toContain('30/10/2026');
  });

  it('CA-05 counts the days left in the trial and has a special text for the last day', () => {
    const trial = (iso: string) =>
      bannersFor(
        me(),
        subscription({
          status: 'trialing',
          trial_ends_at: iso,
          current_period_end: null,
        }),
        NOW,
      )[0]!.text;

    expect(trial('2026-10-30T12:00:00Z')).toBe(
      'Teste grátis: faltam 5 dias. A primeira cobrança é em 30/10/2026.',
    );
    expect(trial('2026-10-25T20:00:00Z')).toContain('Último dia');
    expect(daysLeft('2026-10-24T12:00:00Z', NOW)).toBe(0);
  });

  it('CA-05 shows the support banner on top, even without a subscription', () => {
    expect(
      bannersFor(me('owner', { impersonating: true }), null, NOW).map(
        (b) => b.key,
      ),
    ).toEqual(['support']);
  });
});

describe('PanelFrame', () => {
  const frame = (m = me(), s = subscription()) => (
    <PanelFrame me={m} subscription={s} now={NOW}>
      <h1>Conteúdo</h1>
    </PanelFrame>
  );

  it('CA-04 shows the menu for the role, marks the current page and shows the business and the person', () => {
    render(frame(me('manager')));
    const nav = screen.getByRole('navigation', { name: 'Navegação principal' });

    expect(
      within(nav)
        .getAllByRole('link')
        .map((link) => link.textContent),
    ).toEqual([
      'Início',
      'Agenda',
      'Clientes',
      'Serviços',
      'Profissionais',
      'Equipe',
      'Histórico',
      'Minha conta',
    ]);
    expect(within(nav).getByRole('link', { name: 'Equipe' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(screen.getByText('Ana Souza')).toBeInTheDocument();
    expect(screen.getByText('Estúdio Tinta Viva')).toBeInTheDocument();
  });

  it('CA-05 shows the banners above the content with the shortcut link', () => {
    render(
      frame(me(), subscription({ status: 'suspended', can_write: false })),
    );

    expect(screen.getByRole('alert')).toHaveTextContent('suspensa');
    expect(
      screen.getByRole('link', { name: 'Regularizar agora' }),
    ).toHaveAttribute('href', '/assinatura');
  });

  it('logs out through the bridge and goes to the login even if the call fails', async () => {
    call.mockRejectedValue(new Error('rede'));
    const user = userEvent.setup();
    render(frame());

    await user.click(screen.getByRole('button', { name: 'Sair' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/entrar'));
    expect(call).toHaveBeenCalledWith('POST', 'auth/logout');
  });

  it('CA-16 has no accessibility violations (normal, suspended and support access)', async () => {
    const { container, rerender } = render(frame());
    expect(await axe(container)).toHaveNoViolations();

    rerender(
      frame(
        me('owner', { impersonating: true }),
        subscription({ status: 'suspended', can_write: false }),
      ),
    );
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('HomeView', () => {
  it('shows a greeting with the first name, the business and the subscription for the owner', () => {
    render(<HomeView me={me()} subscription={subscription()} />);

    expect(
      screen.getByRole('heading', { level: 1, name: 'Olá, Ana' }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/painel de Estúdio Tinta Viva/),
    ).toBeInTheDocument();
    expect(screen.getByText('Profissional')).toBeInTheDocument();
    expect(screen.getByText('Ativa')).toBeInTheDocument();
  });

  it('CA-04 hides the subscription card and the shortcuts a role cannot use', () => {
    render(<HomeView me={me('professional')} subscription={subscription()} />);

    expect(
      screen.queryByText('Situação da assinatura'),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('link', { name: 'Assinatura' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('link', { name: 'Equipe' }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Minha conta' }),
    ).toBeInTheDocument();
  });

  it('CA-16 has no accessibility violations', async () => {
    const { container } = render(
      <HomeView
        me={me()}
        subscription={subscription({
          status: 'trialing',
          cancel_at_period_end: true,
        })}
      />,
    );

    expect(await axe(container)).toHaveNoViolations();
  });
});
