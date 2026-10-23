import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';

import { TENANT_ID, tenantDetail } from './admin-fixtures';
import { TenantDetailView } from './tenant-detail';

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

const BASE = `platform/tenants/${TENANT_ID}`;

describe('TenantDetailView', () => {
  it('CA-06 shows the owner, the plan, the card (brand and last digits only), invoices and features', () => {
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    expect(
      screen.getByRole('heading', { name: 'Estúdio Tinta Viva' }),
    ).toBeInTheDocument();
    expect(screen.getByText('ana@exemplo.com')).toBeInTheDocument();
    expect(screen.getByText('visa •••• 4242')).toBeInTheDocument();
    expect(
      screen.getByRole('table', { name: 'Faturas do negócio' }),
    ).toBeInTheDocument();
    const features = screen.getByRole('table', {
      name: 'Funcionalidades efetivas do negócio',
    });
    expect(within(features).getByText('usuarios.max')).toBeInTheDocument();
    expect(within(features).getByText('Exceção')).toBeInTheDocument();
  });

  it('CA-04 hides every write action from the support profile but keeps the support access', () => {
    render(<TenantDetailView tenant={tenantDetail()} canWrite={false} />);

    expect(
      screen.queryByRole('button', { name: 'Suspender' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Estender o teste' }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /Definir exceção/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Entrar como assinante' }),
    ).toBeInTheDocument();
    expect(screen.getByText(/Seu perfil é de consulta/)).toBeInTheDocument();
  });

  it('CA-07 suspends with a reason, showing the result and refreshing', async () => {
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Suspender' }));
    await user.type(screen.getByLabelText(/^Motivo/), 'Chargeback no cartão');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    expect(call).toHaveBeenCalledWith('POST', `${BASE}/suspend`, {
      body: { reason: 'Chargeback no cartão' },
    });
    expect(screen.getByRole('status')).toHaveTextContent(
      'Assinatura suspensa.',
    );
  });

  it('CA-07 shows the API validation error inside the dialog and keeps it open', async () => {
    failure = new ApiError('x', 422, { reason: 'O motivo é curto demais.' });
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Suspender' }));
    await user.type(screen.getByLabelText(/^Motivo/), 'curto');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(
      await screen.findByText('O motivo é curto demais.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(refresh).not.toHaveBeenCalled();
  });

  it('CA-07 unsuspends a suspended business and shows the suspension reason', async () => {
    const tenant = tenantDetail({
      status: 'suspended',
      suspended_reason: 'Pagamento recusado',
    });
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenant} canWrite />);

    expect(screen.getByText(/Pagamento recusado/)).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Suspender' }),
    ).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Reativar' }));
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', `${BASE}/unsuspend`),
    );
  });

  it('CA-07 extends the trial only while the business is in trial', async () => {
    const user = userEvent.setup();
    const { unmount } = render(
      <TenantDetailView tenant={tenantDetail()} canWrite />,
    );

    await user.click(screen.getByRole('button', { name: 'Estender o teste' }));
    await user.clear(screen.getByLabelText(/^Dias/));
    await user.type(screen.getByLabelText(/^Dias/), '5');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', `${BASE}/extend-trial`, {
        body: { days: 5 },
      }),
    );
    unmount();
    render(
      <TenantDetailView tenant={tenantDetail({ status: 'active' })} canWrite />,
    );
    expect(
      screen.queryByRole('button', { name: 'Estender o teste' }),
    ).not.toBeInTheDocument();
  });

  it('CA-08 sets and removes a feature exception', async () => {
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(
      screen.getByRole('button', { name: 'Definir exceção: usuarios.max' }),
    );
    await user.clear(screen.getByLabelText(/^Limite/));
    await user.type(screen.getByLabelText(/^Limite/), '9');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'PUT',
        `${BASE}/entitlements/usuarios.max`,
        {
          body: { enabled: true, limit: 9, visible: true },
        },
      ),
    );
    expect(
      screen.queryByRole('button', { name: 'Remover exceção: usuarios.max' }),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole('button', { name: 'Remover exceção: agenda' }),
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'DELETE',
        `${BASE}/entitlements/agenda`,
      ),
    );
  });

  it('CA-09 asks for the reason and opens the business with the token only in the fragment of a new tab', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        access_token: 'id|segredo',
        url: 'http://tintaviva.localhost:3000',
      },
    });
    const opened = { opener: 'x' } as unknown as Window;
    const open = vi.spyOn(window, 'open').mockReturnValue(opened);
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite={false} />);

    await user.click(
      screen.getByRole('button', { name: 'Entrar como assinante' }),
    );
    await user.type(
      screen.getByLabelText(/^Motivo/),
      'Cliente pediu ajuda com a agenda',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(open).toHaveBeenCalled());
    expect(call).toHaveBeenCalledWith('POST', `${BASE}/impersonate`, {
      body: { reason: 'Cliente pediu ajuda com a agenda' },
    });
    const [url, target] = open.mock.calls[0]!;
    expect(url).toBe(
      'http://tintaviva.localhost:3000/suporte#token=id%7Csegredo',
    );
    expect(target).toBe('_blank');
    expect(opened.opener).toBeNull();
    expect(document.body.textContent).not.toContain('segredo');
    open.mockRestore();
  });

  it('CA-09 refuses an address that is not http or https', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { access_token: 'id|segredo', url: 'javascript:alert(1)' },
    });
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(
      screen.getByRole('button', { name: 'Entrar como assinante' }),
    );
    await user.type(
      screen.getByLabelText(/^Motivo/),
      'Cliente pediu ajuda com a agenda',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(await screen.findByRole('alert')).toBeInTheDocument();
    expect(open).not.toHaveBeenCalled();
    open.mockRestore();
  });

  it('CA-09 tells when the browser blocked the new tab', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        access_token: 'id|segredo',
        url: 'http://tintaviva.localhost:3000',
      },
    });
    const open = vi.spyOn(window, 'open').mockReturnValue(null);
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(
      screen.getByRole('button', { name: 'Entrar como assinante' }),
    );
    await user.type(
      screen.getByLabelText(/^Motivo/),
      'Cliente pediu ajuda com a agenda',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(await screen.findByText(/bloqueou a nova aba/)).toBeInTheDocument();
    expect(
      screen.queryByText(
        'Acesso de suporte aberto em outra aba (vale 30 minutos).',
      ),
    ).not.toBeInTheDocument();
    open.mockRestore();
  });

  it('CA-08 shows the API message even when the field has no input in the dialog', async () => {
    failure = new ApiError('x', 422, {
      feature: 'Funcionalidade desconhecida.',
    });
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(
      screen.getByRole('button', { name: 'Definir exceção: usuarios.max' }),
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Funcionalidade desconhecida.',
    );
  });

  it('CA-07 validates the extension days on the API and shows the error in the dialog', async () => {
    failure = new ApiError('x', 422, { days: 'Informe de 1 a 30 dias.' });
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Estender o teste' }));
    await user.clear(screen.getByLabelText(/^Dias/));
    await user.type(screen.getByLabelText(/^Dias/), '99');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(
      await screen.findByText('Informe de 1 a 30 dias.'),
    ).toBeInTheDocument();
  });

  it('CA-04 shows "no permission" when the API refuses with 403', async () => {
    failure = new ApiError('x', 403);
    const user = userEvent.setup();
    render(<TenantDetailView tenant={tenantDetail()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Suspender' }));
    await user.type(screen.getByLabelText(/^Motivo/), 'Chargeback no cartão');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    expect(
      await screen.findByText('Você não tem permissão para esta ação.'),
    ).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations (page and dialog)', async () => {
    const user = userEvent.setup();
    const { container } = render(
      <TenantDetailView tenant={tenantDetail()} canWrite />,
    );

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Suspender' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
