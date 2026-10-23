import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';

import { lead } from './admin-fixtures';
import { LeadsView } from './leads-view';

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

const page = (cursor: string | null = null) => ({
  data: [
    lead(),
    lead({
      id: 'l-2',
      name: 'Caio Reis',
      email: 'caio@exemplo.com',
      status: 'contacted',
      utm_source: null,
    }),
  ],
  next_cursor: cursor,
});

describe('LeadsView', () => {
  it('CA-12 lists the leads with status and origin', () => {
    render(<LeadsView initial={page()} canWrite />);

    const table = screen.getByRole('table', { name: 'Leads' });
    expect(within(table).getByText('Bia Lima')).toBeInTheDocument();
    expect(within(table).getByText('Novo')).toBeInTheDocument();
    expect(within(table).getByText('Contatado')).toBeInTheDocument();
    expect(within(table).getByText('instagram')).toBeInTheDocument();
  });

  it('CA-12 searches with the filled filters and loads more', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [lead({ id: 'l-9', name: 'Dora' })], next_cursor: 'c2' },
    });
    const user = userEvent.setup();
    render(<LeadsView initial={page('c1')} canWrite />);

    await user.type(screen.getByLabelText('Buscar por nome ou e-mail'), 'bia');
    await user.selectOptions(screen.getByLabelText('Situação'), 'new');
    await user.type(screen.getByLabelText('Origem (UTM)'), 'instagram');
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        'platform/leads?q=bia&status=new&utm_source=instagram&limit=20',
      ),
    );

    await user.click(
      await screen.findByRole('button', { name: 'Carregar mais' }),
    );
    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'platform/leads?q=bia&status=new&utm_source=instagram&limit=20&cursor=c2',
    );
  });

  it('CA-12 changes status and notes of a lead', async () => {
    call.mockResolvedValue({
      status: 200,
      data: lead({ status: 'contacted', notes: 'Ligar amanhã' }),
    });
    const user = userEvent.setup();
    render(<LeadsView initial={page()} canWrite={false} />);

    await user.click(screen.getByRole('button', { name: 'Abrir: Bia Lima' }));
    expect(screen.getByText('Quero conhecer')).toBeInTheDocument();
    await user.selectOptions(
      within(screen.getByRole('dialog')).getByLabelText('Situação'),
      'contacted',
    );
    await user.type(screen.getByLabelText('Notas'), 'Ligar amanhã');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'platform/leads/l-1', {
        body: { status: 'contacted', notes: 'Ligar amanhã' },
      }),
    );
    expect(screen.getByRole('status')).toHaveTextContent('Lead atualizado.');
    expect(
      screen.queryByRole('button', { name: 'Apagar lead' }),
    ).not.toBeInTheDocument();
  });

  it('CA-12 deletes only after the confirmation, for the super admin', async () => {
    const user = userEvent.setup();
    render(<LeadsView initial={page()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Abrir: Bia Lima' }));
    await user.click(screen.getByRole('button', { name: 'Apagar lead' }));
    expect(call).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Apagar de vez' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'platform/leads/l-1'),
    );
    expect(screen.queryByText('Bia Lima')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Lead apagado.');
  });

  it('CA-12 shows the API error inside the dialog', async () => {
    failure = new ApiError('x', 403);
    const user = userEvent.setup();
    render(<LeadsView initial={page()} canWrite />);

    await user.click(screen.getByRole('button', { name: 'Abrir: Bia Lima' }));
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText('Você não tem permissão para esta ação.'),
    ).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations', async () => {
    const user = userEvent.setup();
    const { container } = render(<LeadsView initial={page('c1')} canWrite />);

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Abrir: Bia Lima' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
