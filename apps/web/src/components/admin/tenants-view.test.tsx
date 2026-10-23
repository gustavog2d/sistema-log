import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { tenantItem } from './admin-fixtures';
import { TenantsView } from './tenants-view';

const call = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) =>
    failure ? Promise.reject(failure) : call(...args),
}));

beforeEach(() => {
  failure = null;
  call.mockReset();
});

const page = (cursor: string | null = null) => ({
  data: [
    tenantItem(),
    tenantItem({
      id: '0194a7c0-0000-7000-8000-000000000002',
      name: 'Barbearia Dois',
      slug: 'dois',
      status: 'suspended',
    }),
  ],
  next_cursor: cursor,
});

describe('TenantsView', () => {
  it('CA-05 lists the businesses with situation, price and next date', () => {
    render(<TenantsView initial={page()} />);

    const table = screen.getByRole('table', { name: 'Assinantes' });
    expect(
      within(table).getByRole('link', { name: 'Estúdio Tinta Viva' }),
    ).toHaveAttribute(
      'href',
      '/admin/assinantes/0194a7c0-0000-7000-8000-000000000001',
    );
    expect(within(table).getByText('Em teste')).toBeInTheDocument();
    expect(within(table).getByText('Suspensa')).toBeInTheDocument();
    expect(within(table).getAllByText(/R\$/).length).toBeGreaterThan(0);
  });

  it('CA-05 searches and filters, sending only the filled fields', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [], next_cursor: null },
    });
    const user = userEvent.setup();
    render(<TenantsView initial={page()} />);

    await user.type(
      screen.getByLabelText('Buscar por nome ou endereço'),
      'tinta',
    );
    await user.selectOptions(screen.getByLabelText('Situação'), 'suspended');
    await user.click(screen.getByRole('button', { name: 'Buscar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        'platform/tenants?q=tinta&status=suspended&limit=20',
      ),
    );
    expect(
      await screen.findByText('Nenhum assinante encontrado.'),
    ).toBeInTheDocument();
  });

  it('CA-05 loads more by cursor and appends the rows', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [tenantItem({ id: 'x', name: 'Terceiro' })],
        next_cursor: null,
      },
    });
    const user = userEvent.setup();
    render(<TenantsView initial={page('abc')} />);

    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    expect(await screen.findByText('Terceiro')).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'platform/tenants?limit=20&cursor=abc',
    );
    expect(screen.getByText('Estúdio Tinta Viva')).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Carregar mais' }),
    ).not.toBeInTheDocument();
  });

  it('CA-05 keeps the applied filters on "load more" even if the typed text changed', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [tenantItem({ id: 'y' })], next_cursor: 'c2' },
    });
    const user = userEvent.setup();
    render(<TenantsView initial={page()} />);

    await user.type(
      screen.getByLabelText('Buscar por nome ou endereço'),
      'tinta',
    );
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await screen.findByRole('button', { name: 'Carregar mais' });
    await user.type(
      screen.getByLabelText('Buscar por nome ou endereço'),
      'outra',
    );
    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'platform/tenants?q=tinta&limit=20&cursor=c2',
    );
  });

  it('CA-05 shows an error when the first load failed and when a search fails', async () => {
    failure = new ApiError('Falhou', 500);
    const user = userEvent.setup();
    render(<TenantsView initial={null} />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar',
    );
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    expect(await screen.findByText('Falhou')).toBeInTheDocument();
  });

  it('CA-14 has no accessibility violations', async () => {
    const { container } = render(<TenantsView initial={page('abc')} />);

    expect(await axe(container)).toHaveNoViolations();
  });
});
