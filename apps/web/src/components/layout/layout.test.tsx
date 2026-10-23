import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { describe, expect, it } from 'vitest';
import { AppShell } from './app-shell';
import { MarketingLayout } from './marketing-layout';
import { PageHeader } from './page-header';

describe('MarketingLayout', () => {
  const layout = (
    <MarketingLayout
      links={[{ href: '/planos', label: 'Planos' }]}
      cta={{ href: '/cadastro', label: 'Começar' }}
      footerText="Rodapé de teste"
    >
      <h1>Título</h1>
    </MarketingLayout>
  );

  it('CA-06 has no accessibility violations', async () => {
    const { container } = render(layout);

    expect(await axe(container)).toHaveNoViolations();
  });

  it('CA-06 has the landmarks and a skip link that points to the main content', () => {
    render(layout);

    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(
      screen.getByRole('navigation', { name: 'Navegação principal' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('main')).toHaveAttribute('id', 'conteudo');
    expect(screen.getByRole('contentinfo')).toHaveTextContent(
      'Rodapé de teste',
    );
    const skip = screen.getByRole('link', { name: 'Pular para o conteúdo' });
    expect(skip).toHaveAttribute('href', '#conteudo');
    expect(skip).toBe(document.querySelector('a'));
  });

  it('shows the links and the call to action', () => {
    render(layout);
    const nav = screen.getByRole('navigation');

    expect(within(nav).getByRole('link', { name: 'Planos' })).toHaveAttribute(
      'href',
      '/planos',
    );
    expect(within(nav).getByRole('link', { name: 'Começar' })).toHaveAttribute(
      'href',
      '/cadastro',
    );
  });
});

describe('AppShell', () => {
  const shell = (onLogout = () => {}) => (
    <AppShell
      nav={[
        { href: '/', label: 'Início' },
        { href: '/equipe', label: 'Equipe' },
      ]}
      currentHref="/equipe"
      user={{ name: 'Ana' }}
      onLogout={onLogout}
      areaLabel="Assinante"
    >
      <PageHeader title="Equipe" description="Quem trabalha com você" />
    </AppShell>
  );

  it('CA-06 has no accessibility violations', async () => {
    const { container } = render(shell());

    expect(await axe(container)).toHaveNoViolations();
  });

  it('CA-06 marks the current page and has a skip link and the main landmark', () => {
    render(shell());

    expect(screen.getByRole('link', { name: 'Equipe' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(screen.getByRole('link', { name: 'Início' })).not.toHaveAttribute(
      'aria-current',
    );
    expect(screen.getByRole('main')).toHaveAttribute('id', 'conteudo');
    expect(
      screen.getByRole('link', { name: 'Pular para o conteúdo' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { level: 1, name: 'Equipe' }),
    ).toBeInTheDocument();
  });

  it('CA-06 opens and closes the mobile menu by keyboard with the right aria state', async () => {
    const user = userEvent.setup();
    render(shell());
    const toggle = screen.getByRole('button', { name: 'Abrir menu' });

    expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(toggle).toHaveAttribute('aria-controls', 'menu-principal');
    toggle.focus();
    await user.keyboard('{Enter}');

    expect(screen.getByRole('button', { name: 'Fechar menu' })).toHaveAttribute(
      'aria-expanded',
      'true',
    );
  });

  it('shows the person and calls onLogout', async () => {
    const user = userEvent.setup();
    let called = 0;
    render(shell(() => (called += 1)));

    expect(screen.getByText('Ana')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Sair' }));

    expect(called).toBe(1);
  });
});
