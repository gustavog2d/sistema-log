import { render, screen, within } from '@testing-library/react';
import { axe } from 'vitest-axe';
import { describe, expect, it } from 'vitest';
import { Landing } from './landing';
import { SiteLayout } from './site-layout';
import { PLANS } from './site-helpers';

const page = (plans = PLANS) => (
  <SiteLayout>
    <Landing plans={plans} highlight="profissional" />
  </SiteLayout>
);

describe('Landing', () => {
  it('CA-01 shows each plan with monthly and yearly prices and the saving, from the data', () => {
    render(page());
    const essencial = screen
      .getByRole('heading', { name: 'Essencial' })
      .closest('li')!;

    expect(within(essencial).getByText(/R\$\s?15,00/)).toBeInTheDocument();
    expect(within(essencial).getByText(/R\$\s?162,00/)).toBeInTheDocument();
    expect(
      within(essencial).getByText(/economiza R\$\s?18,00/),
    ).toBeInTheDocument();
    expect(within(essencial).getByText(/Orçamentos/)).toBeInTheDocument();
    expect(
      within(essencial).getByText(/Usuários \(até 2\)/),
    ).toBeInTheDocument();
    expect(
      within(essencial).queryByText(/Agenda pública/),
    ).not.toBeInTheDocument();
  });

  it('CA-01 changes with the plans it receives (nothing fixed in the page)', () => {
    render(
      page([
        {
          ...PLANS[0]!,
          name: 'Plano Novo',
          monthly_price_cents: 9900,
          yearly_price_cents: 99000,
        },
      ]),
    );

    expect(
      screen.getByRole('heading', { name: 'Plano Novo' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/R\$\s?99,00/)).toBeInTheDocument();
    expect(screen.queryByText('Profissional')).not.toBeInTheDocument();
  });

  it('CA-01 warns when the plans could not be loaded and keeps the rest of the page', () => {
    render(
      <SiteLayout>
        <Landing plans={null} />
      </SiteLayout>,
    );

    expect(screen.getByRole('alert')).toHaveTextContent(/planos/i);
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { name: 'Ficou com dúvida?' }),
    ).toBeInTheDocument();
  });

  it('links each plan to the signup with that plan selected, and the call to action to the signup', () => {
    render(page());

    expect(
      within(
        screen.getByRole('heading', { name: 'Estúdio' }).closest('li')!,
      ).getByRole('link'),
    ).toHaveAttribute('href', '/cadastro?plano=estudio');
    expect(
      screen
        .getAllByRole('link', { name: 'Começar o teste grátis' })
        .every((link) => link.getAttribute('href') === '/cadastro'),
    ).toBe(true);
  });

  it('CA-15 has one h1, sections with names, and no accessibility violations', async () => {
    const { container } = render(page());

    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1);
    expect(screen.getByRole('region', { name: 'Planos' })).toBeInTheDocument();
    expect(await axe(container)).toHaveNoViolations();
  });

  it('uses the trial days of the plans in the intro', () => {
    render(page([{ ...PLANS[0]!, trial_days: 14 }]));

    expect(screen.getByText(/14 dias de teste/)).toBeInTheDocument();
  });

  it('CA-17 does not carry a fixed product name', () => {
    const { container } = render(page());

    expect(container.textContent).not.toContain(['sistema', 'log'].join('-'));
  });
});
