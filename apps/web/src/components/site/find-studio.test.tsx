import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { describe, expect, it, vi } from 'vitest';
import { FindStudio } from './find-studio';
import { SignupDone } from './signup-done';

describe('FindStudio', () => {
  it('CA-14 goes to the login of the business on the same domain and port', async () => {
    const go = vi.fn();
    const user = userEvent.setup();
    render(<FindStudio go={go} />);

    await user.type(
      screen.getByLabelText(/Endereço do negócio/),
      '  EstudioTinta ',
    );
    await user.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(go).toHaveBeenCalledWith(
      `${window.location.protocol}//estudiotinta.${window.location.host}/entrar`,
    );
  });

  it.each(['ab', 'meu estudio', 'admin', '-x-', ''])(
    'CA-14 refuses %j with an error on the field and does not go anywhere',
    async (value) => {
      const go = vi.fn();
      const user = userEvent.setup();
      render(<FindStudio go={go} />);

      if (value)
        await user.type(screen.getByLabelText(/Endereço do negócio/), value);
      await user.click(screen.getByRole('button', { name: 'Continuar' }));

      expect(screen.getByLabelText(/Endereço do negócio/)).toHaveAttribute(
        'aria-invalid',
        'true',
      );
      expect(screen.getByText(/Endereço inválido/)).toBeInTheDocument();
      expect(go).not.toHaveBeenCalled();
    },
  );

  it('CA-15 has no accessibility violations and links to the signup', async () => {
    const { container } = render(<FindStudio go={() => {}} />);

    expect(await axe(container)).toHaveNoViolations();
    expect(screen.getByRole('link', { name: 'Criar conta' })).toHaveAttribute(
      'href',
      '/cadastro',
    );
  });
});

describe('SignupDone', () => {
  it('CA-13 shows the address, the trial end and the link to the login', () => {
    render(
      <SignupDone
        slug="estudio-tinta"
        trialEndsAt="2026-10-30T12:00:00+00:00"
      />,
    );

    expect(
      screen.getByText(
        `${window.location.protocol}//estudio-tinta.${window.location.host}`,
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Teste grátis até 30\/10\/2026/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Entrar no meu painel' }),
    ).toHaveAttribute(
      'href',
      `${window.location.protocol}//estudio-tinta.${window.location.host}/entrar`,
    );
  });

  it('shows a helpful message when the data is missing or invalid (no open redirect through the address)', () => {
    const { rerender } = render(<SignupDone />);
    expect(screen.getByRole('status')).toHaveTextContent(
      /Não encontramos os dados/,
    );

    rerender(<SignupDone slug="evil.com/x" trialEndsAt="2026-10-30" />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('ignores an invalid trial date', () => {
    render(<SignupDone slug="estudio-tinta" trialEndsAt="não é data" />);

    expect(screen.queryByText(/Teste grátis até/)).not.toBeInTheDocument();
  });
});
