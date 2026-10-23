import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { ContactForm } from './contact-form';

const call = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));

beforeEach(() => {
  vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', 'fake');
  sessionStorage.clear();
  window.history.pushState(
    {},
    '',
    '/?utm_source=instagram&utm_campaign=lancamento',
  );
  call.mockReset();
});

afterEach(() => vi.unstubAllEnvs());

async function fill(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/Nome/), 'Marina Costa');
  await user.type(screen.getByLabelText(/^E-mail/), 'marina@exemplo.com');
  await user.click(screen.getByLabelText(/política de privacidade/));
}

describe('ContactForm', () => {
  it('CA-02 sends the lead with the origin, the consent and the captcha token', async () => {
    call.mockResolvedValue({ status: 202, data: null });
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);
    await user.type(screen.getByLabelText(/Telefone/), '+5511999998888');
    await user.selectOptions(screen.getByLabelText(/nicho/i), 'tatuagem');

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    await waitFor(() => expect(call).toHaveBeenCalled());
    const [method, path, options] = call.mock.calls[0] as [
      string,
      string,
      { body: Record<string, unknown> },
    ];
    expect([method, path]).toEqual(['POST', 'leads']);
    expect(options.body).toMatchObject({
      name: 'Marina Costa',
      email: 'marina@exemplo.com',
      phone: '+5511999998888',
      niche: 'tatuagem',
      message: null,
      accept_privacy: true,
      captcha_token: 'captcha_ok',
      company_site: '',
      utm_source: 'instagram',
      utm_campaign: 'lancamento',
      landing_path: '/',
    });
    expect(
      await screen.findByText(/Recebemos seu contato/),
    ).toBeInTheDocument();
  });

  it('CA-03 hides the decoy field from people, from the keyboard and from screen readers, and sends what a bot fills', async () => {
    call.mockResolvedValue({ status: 202, data: null });
    const user = userEvent.setup();
    const { container } = render(<ContactForm />);
    const decoy = container.querySelector<HTMLInputElement>(
      'input[name="company_site"]',
    )!;

    expect(decoy.value).toBe('');
    expect(decoy).toHaveAttribute('tabindex', '-1');
    expect(decoy).toHaveAttribute('autocomplete', 'off');
    expect(decoy.closest('[aria-hidden="true"]')).not.toBeNull();
    expect(
      screen.queryByRole('textbox', { name: 'Não preencha este campo' }),
    ).not.toBeInTheDocument();

    await fill(user);
    await user.type(decoy, 'http://golpe.com');
    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    await waitFor(() =>
      expect(
        (
          call.mock.calls[0] as [
            string,
            string,
            { body: Record<string, unknown> },
          ]
        )[2].body.company_site,
      ).toBe('http://golpe.com'),
    );
  });

  it('CA-04 shows the field errors on the fields and keeps what was typed', async () => {
    call.mockRejectedValue(
      new ApiError('Dados inválidos.', 422, {
        email: 'E-mail inválido.',
        phone: 'Telefone fora do padrão.',
      }),
    );
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    expect(await screen.findByText('E-mail inválido.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail/)).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(screen.getByLabelText(/Nome/)).toHaveValue('Marina Costa');
    expect(screen.queryByText(/Recebemos seu contato/)).not.toBeInTheDocument();
  });

  it('CA-04 asks to try later on 429 and keeps the form', async () => {
    call.mockRejectedValue(new ApiError('Too Many', 429));
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Tente de novo mais tarde',
    );
    expect(screen.getByLabelText(/Nome/)).toHaveValue('Marina Costa');
  });

  it('shows a general message for a network failure', async () => {
    call.mockRejectedValue(
      new ApiError('Não foi possível falar com o servidor. Tente de novo.', 0),
    );
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('servidor');
  });

  it('CA-05 does not send and warns when the real captcha is not configured', async () => {
    vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', '');
    vi.stubEnv('NEXT_PUBLIC_RECAPTCHA_SITE_KEY', '');
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'confirmar que você é uma pessoa',
    );
    expect(call).not.toHaveBeenCalled();
  });

  it('is busy and disabled while sending, so a double click sends once', async () => {
    let resolve!: () => void;
    call.mockReturnValue(new Promise<void>((done) => (resolve = done)));
    const user = userEvent.setup();
    render(<ContactForm />);
    await fill(user);

    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));
    const button = await screen.findByRole('button', { name: /Enviando/ });
    await user.click(button);

    expect(button).toBeDisabled();
    expect(call).toHaveBeenCalledTimes(1);
    resolve();
  });

  it('CA-15 has no accessibility violations (empty and with errors)', async () => {
    call.mockRejectedValue(new ApiError('x', 422, { email: 'Inválido' }));
    const user = userEvent.setup();
    const { container } = render(<ContactForm />);
    expect(await axe(container)).toHaveNoViolations();

    await fill(user);
    await user.click(screen.getByRole('button', { name: 'Enviar contato' }));
    await screen.findByText('Inválido');
    expect(await axe(container)).toHaveNoViolations();
  });
});
