import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { Client, ClientPage } from '@/lib/client-types';
import { me } from './panel-fixtures';
import { ClientsView } from './clients-view';

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
  call.mockResolvedValue({
    status: 200,
    data: { data: [], next_cursor: null },
  });
});

const client = (over: Partial<Client> = {}): Client => ({
  id: 'c1',
  name: 'Bia Lima',
  phone: '+5511999998888',
  email: 'bia@exemplo.com',
  instagram: 'bia.lima',
  notes: 'Prefere à tarde',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const page = (cursor: string | null = null): ClientPage => ({
  data: [
    client(),
    client({
      id: 'c2',
      name: 'Caio Reis',
      phone: '+351912345678',
      email: null,
      instagram: null,
    }),
  ],
  next_cursor: cursor,
});

describe('ClientsView', () => {
  it('CA-09 lists clients with phone, contact and actions for the owner', () => {
    render(<ClientsView me={me('owner')} initial={page()} />);

    const table = screen.getByRole('table', { name: 'Clientes' });
    expect(within(table).getByText('+55 (11) 99999-8888')).toBeInTheDocument();
    expect(
      within(table).getByText('bia@exemplo.com · @bia.lima'),
    ).toBeInTheDocument();
    expect(within(table).getByText('+351 912 345 678')).toBeInTheDocument();
    expect(within(table).getByText('—')).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Excluir: Bia Lima' }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Histórico: Bia Lima' }),
    ).toBeInTheDocument();
  });

  it.each(['professional', 'reception'] as const)(
    'CA-03 hides delete and history from %s but lets edit and create',
    (role) => {
      render(<ClientsView me={me(role)} initial={page()} />);

      expect(
        screen.queryByRole('button', { name: /Excluir/ }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('button', { name: /Histórico/ }),
      ).not.toBeInTheDocument();
      expect(
        screen.getByRole('button', { name: 'Editar: Bia Lima' }),
      ).toBeEnabled();
      expect(
        screen.getByRole('button', { name: 'Novo cliente' }),
      ).toBeEnabled();
    },
  );

  it('CA-08 disables every write in a read-only business and in a support access', () => {
    const { unmount } = render(
      <ClientsView me={me('owner', { can_write: false })} initial={page()} />,
    );
    expect(screen.getByRole('button', { name: 'Novo cliente' })).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Editar: Bia Lima' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Excluir: Bia Lima' }),
    ).toBeDisabled();
    expect(screen.getByText(/somente leitura/)).toBeInTheDocument();
    unmount();

    render(
      <ClientsView
        me={me('owner', { impersonating: true })}
        initial={page()}
      />,
    );
    expect(screen.getByRole('button', { name: 'Novo cliente' })).toBeDisabled();
    expect(
      screen.getByText(/acesso de suporte você só consulta/),
    ).toBeInTheDocument();
  });

  it('CA-09 searches sending only the filled text and tells when nothing was found', async () => {
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.type(
      screen.getByLabelText('Buscar por nome, telefone, e-mail ou Instagram'),
      ' maria ',
    );
    await user.click(screen.getByRole('button', { name: 'Buscar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('GET', 'clients?limit=20&q=maria'),
    );
    expect(
      await screen.findByText('Nenhum cliente encontrado.'),
    ).toBeInTheDocument();
  });

  it('CA-09 loads more keeping the applied search even if the typed text changed', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [client({ id: 'c9', name: 'Dora' })], next_cursor: 'c2' },
    });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.type(
      screen.getByLabelText('Buscar por nome, telefone, e-mail ou Instagram'),
      'ana',
    );
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await screen.findByRole('button', { name: 'Carregar mais' });
    await user.type(
      screen.getByLabelText('Buscar por nome, telefone, e-mail ou Instagram'),
      'x',
    );
    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'clients?limit=20&q=ana&cursor=c2',
    );
  });

  it('CA-09 creates a client with the phone in E.164 and puts it on top of the list', async () => {
    call.mockResolvedValue({
      status: 201,
      data: client({
        id: 'new',
        name: 'Eva Nunes',
        phone: '+5511988887777',
        email: null,
        instagram: null,
        notes: null,
      }),
    });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(screen.getByRole('button', { name: 'Novo cliente' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Eva Nunes');
    await user.type(screen.getByLabelText(/^Telefone/), '11988887777');
    expect(screen.getByLabelText(/^Telefone/)).toHaveValue('(11) 98888-7777');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'clients', {
        body: {
          name: 'Eva Nunes',
          phone: '+5511988887777',
          email: null,
          instagram: null,
          notes: null,
        },
      }),
    );
    expect(await screen.findByRole('status')).toHaveTextContent(
      'Cliente cadastrado.',
    );
    expect(screen.getAllByRole('row')[1]).toHaveTextContent('Eva Nunes');
  });

  it('CA-02 shows the API error on the field and keeps the dialog open', async () => {
    failure = new ApiError('x', 422, {
      phone: 'Já existe um cliente com este telefone.',
    });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(screen.getByRole('button', { name: 'Novo cliente' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Bia');
    await user.type(screen.getByLabelText(/^Telefone/), '11999998888');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText('Já existe um cliente com este telefone.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('CA-09 edits a client starting from the stored values, converting the phone back', async () => {
    call.mockResolvedValue({
      status: 200,
      data: client({ name: 'Beatriz Lima' }),
    });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(screen.getByRole('button', { name: 'Editar: Bia Lima' }));
    expect(screen.getByLabelText(/^Nome/)).toHaveValue('Bia Lima');
    expect(screen.getByLabelText(/^Telefone/)).toHaveValue('(11) 99999-8888');
    expect(screen.getByLabelText('País')).toHaveValue('BR');
    await user.clear(screen.getByLabelText(/^Nome/));
    await user.type(screen.getByLabelText(/^Nome/), 'Beatriz Lima');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'clients/c1', {
        body: {
          name: 'Beatriz Lima',
          phone: '+5511999998888',
          email: 'bia@exemplo.com',
          instagram: 'bia.lima',
          notes: 'Prefere à tarde',
        },
      }),
    );
    expect(await screen.findByText('Beatriz Lima')).toBeInTheDocument();
  });

  it('CA-09 edits a foreign number with its country', async () => {
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(screen.getByRole('button', { name: 'Editar: Caio Reis' }));

    expect(screen.getByLabelText('País')).toHaveValue('PT');
    expect(screen.getByLabelText(/^Telefone/)).toHaveValue('912 345 678');
  });

  it('CA-09 deletes only after the confirmation and removes the row', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(screen.getByRole('button', { name: 'Excluir: Bia Lima' }));
    expect(call).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Excluir de vez' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'clients/c1'),
    );
    expect(screen.queryByText('Bia Lima')).not.toBeInTheDocument();
    expect(screen.getByRole('status')).toHaveTextContent('Cliente excluído.');
  });

  it('CA-09 shows the history of the client for the owner', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [
          {
            id: 'l1',
            entity_type: 'client',
            entity_id: 'c1',
            action: 'created',
            actor: { id: 'u', name: 'Ana', support: false },
            before: null,
            after: { name: 'Bia Lima' },
            created_at: '2026-11-01T12:00:00Z',
          },
        ],
        next_cursor: null,
      },
    });
    const user = userEvent.setup();
    render(<ClientsView me={me()} initial={page()} />);

    await user.click(
      screen.getByRole('button', { name: 'Histórico: Bia Lima' }),
    );

    expect(await screen.findByText('criou')).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'audit-logs?entity_type=client&entity_id=c1&limit=20',
    );
  });

  it('CA-09 shows the first-use message and the load error', () => {
    const { unmount } = render(
      <ClientsView me={me()} initial={{ data: [], next_cursor: null }} />,
    );
    expect(screen.getByText(/ainda não tem clientes/)).toBeInTheDocument();
    unmount();

    render(<ClientsView me={me()} initial={null} />);
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar os clientes',
    );
  });

  it('CA-09 has no accessibility violations (page, form and delete dialogs)', async () => {
    const user = userEvent.setup();
    const { container } = render(<ClientsView me={me()} initial={page('c')} />);

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Novo cliente' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
