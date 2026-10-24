import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type {
  Service,
  ServiceCategory,
  ServicePage,
} from '@/lib/service-types';
import { me } from './panel-fixtures';
import { ServicesView } from './services-view';

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

const service = (over: Partial<Service> = {}): Service => ({
  id: 's1',
  name: 'Corte',
  description: null,
  category_id: 'k1',
  duration_minutes: 90,
  price_cents: 8000,
  buffer_before_minutes: 0,
  buffer_after_minutes: 10,
  requires_deposit: true,
  status: 'active',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const category = (over: Partial<ServiceCategory> = {}): ServiceCategory => ({
  id: 'k1',
  name: 'Cabelo',
  services_count: 2,
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const page = (cursor: string | null = null): ServicePage => ({
  data: [
    service(),
    service({
      id: 's2',
      name: 'Pigmentação',
      category_id: null,
      price_cents: null,
      requires_deposit: false,
      duration_minutes: 60,
    }),
  ],
  next_cursor: cursor,
});

const view = (
  props: Partial<React.ComponentProps<typeof ServicesView>> = {},
) => (
  <ServicesView
    me={me()}
    initial={page()}
    categories={[category()]}
    {...props}
  />
);

describe('ServicesView', () => {
  it('CA-10 lists services with category, duration, price (or on request) and deposit', () => {
    render(view());

    const table = screen.getByRole('table', { name: 'Serviços' });
    expect(within(table).getByText('1 h 30 min')).toBeInTheDocument();
    expect(within(table).getByText(/R\$\s?80,00/)).toBeInTheDocument();
    expect(within(table).getByText('Sob orçamento')).toBeInTheDocument();
    expect(within(table).getByText('Sem categoria')).toBeInTheDocument();
    expect(within(table).getByText('Exige')).toBeInTheDocument();
    expect(within(table).getByText('Não exige')).toBeInTheDocument();
  });

  it.each(['professional', 'reception'] as const)(
    'CA-03 gives %s a read-only catalog',
    (role) => {
      render(view({ me: me(role) }));

      expect(
        screen.queryByRole('button', { name: 'Novo serviço' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('button', { name: /Editar|Arquivar|Excluir/ }),
      ).not.toBeInTheDocument();
      expect(
        screen.getByText(/Seu papel consulta o catálogo/),
      ).toBeInTheDocument();
      expect(
        screen.getByRole('button', { name: 'Categorias' }),
      ).toBeInTheDocument();
    },
  );

  it('CA-03 disables every write in a read-only business and in a support access', () => {
    const { unmount } = render(view({ me: me('owner', { can_write: false }) }));
    expect(screen.getByRole('button', { name: 'Novo serviço' })).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Editar: Corte' }),
    ).toBeDisabled();
    expect(screen.getByText(/somente leitura/)).toBeInTheDocument();
    unmount();

    render(view({ me: me('owner', { impersonating: true }) }));
    expect(screen.getByRole('button', { name: 'Novo serviço' })).toBeDisabled();
    expect(
      screen.getByText(/acesso de suporte você só consulta/),
    ).toBeInTheDocument();
  });

  it('CA-04 searches, filters by category and shows archived, sending only the filled fields', async () => {
    const user = userEvent.setup();
    render(view());

    await user.type(screen.getByLabelText('Buscar pelo nome'), 'cor');
    await user.selectOptions(
      screen.getByLabelText('Categoria', { selector: 'select' }),
      'k1',
    );
    await user.click(screen.getByLabelText('Mostrar também os arquivados'));
    await user.click(screen.getByRole('button', { name: 'Buscar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        'services?limit=20&status=all&q=cor&category_id=k1',
      ),
    );
    expect(
      await screen.findByText('Nenhum serviço encontrado.'),
    ).toBeInTheDocument();
  });

  it('CA-04 loads more keeping the applied filters', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [service({ id: 's9', name: 'Zebra' })], next_cursor: 'c2' },
    });
    const user = userEvent.setup();
    render(view());

    await user.type(screen.getByLabelText('Buscar pelo nome'), 'zeb');
    await user.click(screen.getByRole('button', { name: 'Buscar' }));
    await screen.findByRole('button', { name: 'Carregar mais' });
    await user.type(screen.getByLabelText('Buscar pelo nome'), 'x');
    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'services?limit=20&status=active&q=zeb&cursor=c2',
    );
  });

  it('CA-04 asks for two characters before searching', async () => {
    const user = userEvent.setup();
    render(view());

    await user.type(screen.getByLabelText('Buscar pelo nome'), 'a');
    await user.click(screen.getByRole('button', { name: 'Buscar' }));

    expect(screen.getByRole('alert')).toHaveTextContent(
      'ao menos 2 caracteres',
    );
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-01 creates a service converting reais to cents and keeps the list in order', async () => {
    call.mockResolvedValue({
      status: 201,
      data: service({
        id: 'new',
        name: 'Barba',
        price_cents: 4550,
        category_id: 'k1',
      }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Novo serviço' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Barba');
    await user.selectOptions(
      screen.getByLabelText('Categoria', {
        selector: 'dialog select, [role="dialog"] select',
      }),
      'k1',
    );
    await user.clear(screen.getByLabelText(/^Duração/));
    await user.type(screen.getByLabelText(/^Duração/), '30');
    await user.click(screen.getByLabelText('Sob orçamento (sem preço fixo)'));
    await user.type(screen.getByLabelText(/^Preço/), '45,50');
    await user.click(screen.getByLabelText('Exige sinal para reservar'));
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'services', {
        body: {
          name: 'Barba',
          description: null,
          category_id: 'k1',
          duration_minutes: 30,
          price_cents: 4550,
          buffer_before_minutes: 0,
          buffer_after_minutes: 0,
          requires_deposit: true,
        },
      }),
    );
    expect(await screen.findByRole('status')).toHaveTextContent(
      'Serviço cadastrado.',
    );
    const rows = screen.getAllByRole('row').slice(1);
    expect(rows[0]).toHaveTextContent('Barba');
    expect(rows[1]).toHaveTextContent('Corte');
  });

  it('CA-01 refuses an invalid price without calling the API and shows the field error from the API', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Novo serviço' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Barba');
    await user.click(screen.getByLabelText('Sob orçamento (sem preço fixo)'));
    await user.type(screen.getByLabelText(/^Preço/), 'abc');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    expect(
      await screen.findByText(/Informe um valor em reais/),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();

    failure = new ApiError('x', 422, {
      name: 'Já existe um registro com este nome.',
    });
    await user.clear(screen.getByLabelText(/^Preço/));
    await user.type(screen.getByLabelText(/^Preço/), '10');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));
    expect(
      await screen.findByText('Já existe um registro com este nome.'),
    ).toBeInTheDocument();
  });

  it('CA-05 edits starting from the stored values', async () => {
    call.mockResolvedValue({
      status: 200,
      data: service({ name: 'Corte social' }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Editar: Corte' }));
    expect(screen.getByLabelText(/^Nome/)).toHaveValue('Corte');
    expect(screen.getByLabelText(/^Duração/)).toHaveValue(90);
    expect(screen.getByLabelText(/^Preço/)).toHaveValue('80,00');
    expect(screen.getByLabelText('Exige sinal para reservar')).toBeChecked();
    await user.clear(screen.getByLabelText(/^Nome/));
    await user.type(screen.getByLabelText(/^Nome/), 'Corte social');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'services/s1', {
        body: expect.objectContaining({
          name: 'Corte social',
          price_cents: 8000,
          buffer_after_minutes: 10,
          requires_deposit: true,
        }),
      }),
    );
  });

  it('CA-05 archives and the archived service leaves the default list', async () => {
    call.mockResolvedValue({
      status: 200,
      data: service({ status: 'archived' }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Arquivar: Corte' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'services/s1', {
        body: { status: 'archived' },
      }),
    );
    expect(await screen.findByText('Serviço arquivado.')).toBeInTheDocument();
    expect(
      screen.queryByRole('cell', { name: 'Corte' }),
    ).not.toBeInTheDocument();
  });

  it('CA-05 deletes only after the confirmation', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Excluir: Corte' }));
    expect(call).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Excluir de vez' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'services/s1'),
    );
    expect(
      screen.queryByRole('cell', { name: 'Corte' }),
    ).not.toBeInTheDocument();
  });

  it('CA-07 offers the suggested services on first use and reloads the lists', async () => {
    call.mockImplementation(async (method: string, path: string) => {
      if (method === 'POST')
        return {
          status: 200,
          data: {
            niche: 'tatuagem',
            created: { categories: 2, services: 7 },
            skipped: { categories: 0, services: 0 },
          },
        };
      if (path.startsWith('services'))
        return { status: 200, data: { data: [service()], next_cursor: null } };

      return { status: 200, data: { data: [category()] } };
    });
    const user = userEvent.setup();
    render(view({ initial: { data: [], next_cursor: null }, categories: [] }));

    expect(screen.getByText(/ainda não tem serviços/)).toBeInTheDocument();
    await user.click(
      screen.getByRole('button', { name: 'Usar serviços sugeridos' }),
    );

    expect(
      await screen.findByText(
        'Serviços sugeridos: 7 criados e 0 que já existiam.',
      ),
    ).toBeInTheDocument();
    expect(await screen.findByText('Corte')).toBeInTheDocument();
  });

  it('CA-07 tells when the business has no niche for the suggestions', async () => {
    failure = new ApiError('x', 422, {
      niche: 'O negócio não tem um nicho com serviços iniciais.',
    });
    const user = userEvent.setup();
    render(view({ initial: { data: [], next_cursor: null }, categories: [] }));

    await user.click(
      screen.getByRole('button', { name: 'Usar serviços sugeridos' }),
    );

    expect(
      await screen.findByText(/ainda não tem um tipo definido/),
    ).toBeInTheDocument();
  });

  it('CA-06 manages categories: add, rename, delete and the refusal of a category in use', async () => {
    const user = userEvent.setup();
    call.mockImplementation(async (method: string) => {
      if (method === 'POST')
        return {
          status: 201,
          data: category({ id: 'k2', name: 'Barba', services_count: 0 }),
        };
      if (method === 'PATCH')
        return { status: 200, data: category({ name: 'Cabelos' }) };

      return { status: 204, data: null };
    });
    render(
      view({
        categories: [
          category(),
          category({ id: 'k3', name: 'Vazia', services_count: 0 }),
        ],
      }),
    );

    await user.click(screen.getByRole('button', { name: 'Categorias' }));
    const dialog = screen.getByRole('dialog');
    expect(within(dialog).getByText('2 serviço(s)')).toBeInTheDocument();

    await user.type(
      within(dialog).getByLabelText('Nome da categoria'),
      'Barba',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Adicionar' }));
    expect(await within(dialog).findByText('Barba')).toBeInTheDocument();

    await user.click(
      within(dialog).getByRole('button', { name: 'Renomear: Cabelo' }),
    );
    await user.clear(within(dialog).getByLabelText('Nome novo para Cabelo'));
    await user.type(
      within(dialog).getByLabelText('Nome novo para Cabelo'),
      'Cabelos',
    );
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar nome' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'service-categories/k1', {
        body: { name: 'Cabelos' },
      }),
    );

    await user.click(
      within(dialog).getByRole('button', { name: 'Excluir: Vazia' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'service-categories/k3'),
    );
    expect(within(dialog).queryByText('Vazia')).not.toBeInTheDocument();

    failure = new ApiError(
      'Esta categoria tem serviços. Mova ou apague os serviços antes.',
      409,
      {},
      'category_in_use',
    );
    await user.click(
      within(dialog).getByRole('button', { name: 'Excluir: Barba' }),
    );
    expect(
      await within(dialog).findByText(/Esta categoria tem serviços/),
    ).toBeInTheDocument();
  });

  it('CA-06 refreshes the count of services of the categories after the catalog changes', async () => {
    call.mockImplementation(async (_method: string, path: string) => {
      if (path === 'service-categories')
        return {
          status: 200,
          data: { data: [category({ services_count: 3 })] },
        };

      return { status: 200, data: service({ status: 'archived' }) };
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Arquivar: Corte' }));
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('GET', 'service-categories'),
    );
    await user.click(screen.getByRole('button', { name: 'Categorias' }));

    expect(await screen.findByText('3 serviço(s)')).toBeInTheDocument();
  });

  it('CA-10 shows the load error', () => {
    render(view({ initial: null }));

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar os serviços',
    );
  });

  it('CA-10 has no accessibility violations (page, form, delete and categories dialogs)', async () => {
    const user = userEvent.setup();
    const { container } = render(view({ initial: page('c') }));

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Novo serviço' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    await user.click(screen.getByRole('button', { name: 'Excluir: Corte' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    await user.click(screen.getByRole('button', { name: 'Categorias' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
