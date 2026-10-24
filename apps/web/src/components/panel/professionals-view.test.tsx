import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type {
  Professional,
  ProfessionalPage,
  TimeOff,
  TimeOffPage,
} from '@/lib/professional-types';
import { me } from './panel-fixtures';
import { ProfessionalsView } from './professionals-view';
import { validateRanges } from './working-hours-dialog';

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

const professional = (over: Partial<Professional> = {}): Professional => ({
  id: 'p1',
  name: 'Ana Tattoo',
  color: '#2563EB',
  commission_percent: 40,
  user_id: 'u1',
  status: 'active',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const off = (over: Partial<TimeOff> = {}): TimeOff => ({
  id: 'o1',
  professional_id: null,
  starts_at: '2026-12-24T03:00:00+00:00',
  ends_at: '2026-12-26T03:00:00+00:00',
  reason: 'Recesso',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const people: ProfessionalPage = {
  data: [
    professional(),
    professional({
      id: 'p2',
      name: 'Bia Souza',
      status: 'inactive',
      user_id: null,
      commission_percent: 0,
    }),
  ],
  next_cursor: null,
};
const offs: TimeOffPage = {
  data: [off(), off({ id: 'o2', professional_id: 'p1', reason: null })],
  next_cursor: null,
};

const view = (
  props: Partial<React.ComponentProps<typeof ProfessionalsView>> = {},
) => (
  <ProfessionalsView
    me={me()}
    initial={people}
    timeOffs={offs}
    team={[
      { id: 'u1', name: 'Ana Souza' },
      { id: 'u2', name: 'Caio Reis' },
    ]}
    {...props}
  />
);

describe('validateRanges', () => {
  it('AGD-002 accepts touching ranges and refuses overlap, wrong order and steps', () => {
    expect(
      validateRanges({
        1: [
          { start: '09:00', end: '12:00' },
          { start: '12:00', end: '15:00' },
        ],
      }),
    ).toBeNull();
    expect(
      validateRanges({
        1: [
          { start: '09:00', end: '12:00' },
          { start: '11:00', end: '14:00' },
        ],
      }),
    ).toBe('professionals.hoursOverlap');
    expect(validateRanges({ 1: [{ start: '12:00', end: '09:00' }] })).toBe(
      'professionals.hoursOrder',
    );
    expect(validateRanges({ 1: [{ start: '09:00', end: '09:00' }] })).toBe(
      'professionals.hoursOrder',
    );
    expect(validateRanges({ 1: [{ start: '', end: '09:00' }] })).toBe(
      'professionals.hoursOrder',
    );
    expect(validateRanges({ 1: [{ start: '09:03', end: '10:00' }] })).toBe(
      'professionals.hoursStep',
    );
    expect(validateRanges({})).toBeNull();
  });
});

describe('ProfessionalsView', () => {
  it('CA-10 lists the active professionals, with commission and access, hiding the inactive ones', async () => {
    const user = userEvent.setup();
    render(view());

    const table = screen.getByRole('table', { name: 'Profissionais' });
    expect(within(table).getByText('Ana Tattoo')).toBeInTheDocument();
    expect(within(table).getByText('40%')).toBeInTheDocument();
    expect(within(table).getByText('Tem acesso')).toBeInTheDocument();
    expect(within(table).queryByText('Bia Souza')).not.toBeInTheDocument();

    await user.click(screen.getByLabelText('Mostrar também os inativos'));
    expect(
      within(screen.getByRole('table', { name: 'Profissionais' })).getByText(
        'Bia Souza',
      ),
    ).toBeInTheDocument();
  });

  it.each(['professional', 'reception'] as const)(
    'CA-03 gives %s a read-only page that can still look at the hours',
    (role) => {
      render(view({ me: me(role) }));

      expect(
        screen.queryByRole('button', { name: 'Novo profissional' }),
      ).not.toBeInTheDocument();
      expect(
        screen.queryByRole('button', {
          name: /Editar|Desativar|Excluir|Nova folga/,
        }),
      ).not.toBeInTheDocument();
      expect(
        screen.getByRole('button', { name: 'Ver expediente: Ana Tattoo' }),
      ).toBeInTheDocument();
      expect(screen.getByText(/Seu papel consulta/)).toBeInTheDocument();
    },
  );

  it('CA-03 disables every write in a read-only business and in a support access', () => {
    const { unmount } = render(view({ me: me('owner', { can_write: false }) }));
    expect(
      screen.getByRole('button', { name: 'Novo profissional' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Editar: Ana Tattoo' }),
    ).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Nova folga' })).toBeDisabled();
    unmount();

    render(view({ me: me('owner', { impersonating: true }) }));
    expect(
      screen.getByRole('button', { name: 'Novo profissional' }),
    ).toBeDisabled();
    expect(
      screen.getAllByText(/acesso de suporte você só consulta/).length,
    ).toBeGreaterThan(0);
  });

  it('CA-01 creates a professional and keeps the list alphabetical', async () => {
    call.mockResolvedValue({
      status: 201,
      data: professional({
        id: 'new',
        name: 'Alice',
        commission_percent: 30,
        user_id: null,
      }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Novo profissional' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Alice');
    await user.clear(screen.getByLabelText(/^Comissão/));
    await user.type(screen.getByLabelText(/^Comissão/), '30');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'professionals', {
        body: {
          name: 'Alice',
          color: '#2563EB',
          commission_percent: 30,
          user_id: null,
        },
      }),
    );
    expect(
      await screen.findByText('Profissional cadastrado.'),
    ).toBeInTheDocument();
    expect(screen.getAllByRole('row')[1]).toHaveTextContent('Alice');
  });

  it('CA-02 shows the API error on the field and keeps the dialog open', async () => {
    failure = new ApiError('x', 422, {
      name: 'Já existe um profissional com este nome.',
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Novo profissional' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Ana');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    expect(
      await screen.findByText('Já existe um profissional com este nome.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('CA-04 edits, deactivates and deletes only after the confirmation', async () => {
    call.mockResolvedValue({
      status: 200,
      data: professional({ status: 'inactive' }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Editar: Ana Tattoo' }),
    );
    expect(screen.getByLabelText(/^Nome/)).toHaveValue('Ana Tattoo');
    expect(screen.getByLabelText(/^Comissão/)).toHaveValue(40);
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    await user.click(
      screen.getByRole('button', { name: 'Desativar: Ana Tattoo' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'professionals/p1', {
        body: { status: 'inactive' },
      }),
    );
    expect(
      await screen.findByText('Profissional desativado.'),
    ).toBeInTheDocument();

    call.mockResolvedValue({ status: 204, data: null });
    await user.click(screen.getByLabelText('Mostrar também os inativos'));
    await user.click(
      screen.getByRole('button', { name: 'Excluir: Ana Tattoo' }),
    );
    expect(call).not.toHaveBeenCalledWith('DELETE', 'professionals/p1');
    await user.click(screen.getByRole('button', { name: 'Excluir de vez' }));
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'professionals/p1'),
    );
  });

  it('CA-05 loads the schedule, edits ranges, copies a day to the weekdays and saves', async () => {
    call.mockImplementation(async (method: string) =>
      method === 'GET'
        ? {
            status: 200,
            data: { data: [{ weekday: 1, start: '09:00', end: '12:00' }] },
          }
        : { status: 200, data: { data: [] } },
    );
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Expediente: Ana Tattoo' }),
    );
    const dialog = await screen.findByRole('dialog');
    expect(
      within(dialog).getByLabelText('Início 1: Segunda-feira'),
    ).toHaveValue('09:00');

    await user.click(
      within(dialog).getByRole('button', {
        name: 'Adicionar faixa: Segunda-feira',
      }),
    );
    expect(
      within(dialog).getByLabelText('Início 2: Segunda-feira'),
    ).toBeInTheDocument();
    await user.click(
      within(dialog).getByRole('button', {
        name: 'Remover faixa 2: Segunda-feira',
      }),
    );
    await user.click(
      within(dialog).getByRole('button', {
        name: 'Copiar para os outros dias úteis: Segunda-feira',
      }),
    );
    expect(within(dialog).getByLabelText('Início 1: Sexta-feira')).toHaveValue(
      '09:00',
    );
    expect(
      within(dialog).queryByLabelText('Início 1: Sábado'),
    ).not.toBeInTheDocument();
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar expediente' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'PUT',
        'professionals/p1/working-hours',
        {
          body: {
            ranges: [1, 2, 3, 4, 5].map((weekday) => ({
              weekday,
              start: '09:00',
              end: '12:00',
            })),
          },
        },
      ),
    );
    expect(await screen.findByText('Expediente salvo.')).toBeInTheDocument();
  });

  it('CA-05 shows and saves a range that goes until midnight (24:00)', async () => {
    call.mockImplementation(async (method: string) =>
      method === 'GET'
        ? {
            status: 200,
            data: { data: [{ weekday: 6, start: '20:00', end: '24:00' }] },
          }
        : { status: 200, data: { data: [] } },
    );
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Expediente: Ana Tattoo' }),
    );
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByLabelText('Fim 1: Sábado')).toHaveValue('24:00');
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar expediente' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'PUT',
        'professionals/p1/working-hours',
        { body: { ranges: [{ weekday: 6, start: '20:00', end: '24:00' }] } },
      ),
    );
  });

  it('CA-01 sends the person linked to the professional and shows the commission as hidden when the API hides it', async () => {
    call.mockResolvedValue({
      status: 201,
      data: professional({ id: 'new', name: 'Zeca', user_id: 'u2' }),
    });
    const user = userEvent.setup();
    render(
      view({
        initial: {
          data: [professional({ commission_percent: null })],
          next_cursor: null,
        },
      }),
    );

    expect(
      screen.getByRole('table', { name: 'Profissionais' }),
    ).toHaveTextContent('—');
    await user.click(screen.getByRole('button', { name: 'Novo profissional' }));
    await user.type(screen.getByLabelText(/^Nome/), 'Zeca');
    await user.selectOptions(screen.getByLabelText('Acesso'), 'u2');
    await user.click(screen.getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'professionals', {
        body: expect.objectContaining({ name: 'Zeca', user_id: 'u2' }),
      }),
    );
  });

  it('CA-04 loads more professionals by cursor', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [professional({ id: 'p9', name: 'Zélia' })],
        next_cursor: null,
      },
    });
    const user = userEvent.setup();
    render(view({ initial: { data: people.data, next_cursor: 'c2' } }));

    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        'professionals?limit=50&status=all&cursor=c2',
      ),
    );
    expect(
      await within(
        screen.getByRole('table', { name: 'Profissionais' }),
      ).findByText('Zélia'),
    ).toBeInTheDocument();
  });

  it('CA-04 removes the time offs of a professional that was deleted', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(view());
    expect(
      screen.getByRole('table', { name: 'Lista de folgas' }),
    ).toHaveTextContent('Ana Tattoo');

    await user.click(
      screen.getByRole('button', { name: 'Excluir: Ana Tattoo' }),
    );
    await user.click(screen.getByRole('button', { name: 'Excluir de vez' }));

    await waitFor(() =>
      expect(
        screen.getByRole('table', { name: 'Lista de folgas' }),
      ).not.toHaveTextContent('Ana Tattoo'),
    );
  });

  it('CA-06 refuses overlapping ranges on the screen before sending', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [
          { weekday: 1, start: '09:00', end: '12:00' },
          { weekday: 1, start: '11:00', end: '14:00' },
        ],
      },
    });
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Expediente: Ana Tattoo' }),
    );
    const dialog = await screen.findByRole('dialog');
    call.mockClear();
    await user.click(
      within(dialog).getByRole('button', { name: 'Salvar expediente' }),
    );

    expect(await within(dialog).findByText(/se sobrepõem/)).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-05 only shows the schedule to a role that cannot write', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [{ weekday: 2, start: '10:00', end: '16:00' }] },
    });
    const user = userEvent.setup();
    render(view({ me: me('reception') }));

    await user.click(
      screen.getByRole('button', { name: 'Ver expediente: Ana Tattoo' }),
    );
    const dialog = await screen.findByRole('dialog');

    expect(
      within(dialog).getByLabelText('Início 1: Terça-feira'),
    ).toBeDisabled();
    expect(
      within(dialog).queryByRole('button', { name: 'Salvar expediente' }),
    ).not.toBeInTheDocument();
    expect(
      within(dialog).queryByRole('button', { name: /Adicionar faixa/ }),
    ).not.toBeInTheDocument();
  });

  it('CA-07 lists the time offs with the person or the whole business and the period on the business clock', () => {
    render(view());

    const table = screen.getByRole('table', { name: 'Lista de folgas' });
    expect(within(table).getAllByText('Negócio todo').length).toBeGreaterThan(
      0,
    );
    expect(within(table).getByText('Recesso')).toBeInTheDocument();
    expect(
      within(table).getAllByText(/24\/12\/2026 00:00 – 26\/12\/2026 00:00/),
    ).toHaveLength(2);
  });

  it('CA-07 creates a time off converting the local clock to an instant with the offset', async () => {
    call.mockResolvedValue({
      status: 201,
      data: off({
        id: 'new',
        professional_id: 'p1',
        starts_at: '2027-01-10T03:00:00+00:00',
        ends_at: '2027-01-20T03:00:00+00:00',
        reason: 'Férias',
      }),
    });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Nova folga' }));
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByLabelText('Quem'), 'p1');
    await user.type(
      within(dialog).getByLabelText(/^Início/),
      '2027-01-10T00:00',
    );
    await user.type(within(dialog).getByLabelText(/^Fim/), '2027-01-20T00:00');
    await user.type(within(dialog).getByLabelText(/^Motivo/), 'Férias');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'time-offs', {
        body: {
          professional_id: 'p1',
          starts_at: '2027-01-10T00:00:00-03:00',
          ends_at: '2027-01-20T00:00:00-03:00',
          reason: 'Férias',
        },
      }),
    );
    expect(await screen.findByText('Folga cadastrada.')).toBeInTheDocument();
    expect(screen.getByText('Férias')).toBeInTheDocument();
  });

  it('CA-07 refuses an end before the start on the screen and deletes after the confirmation', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Nova folga' }));
    const dialog = screen.getByRole('dialog');
    await user.type(
      within(dialog).getByLabelText(/^Início/),
      '2027-01-10T10:00',
    );
    await user.type(within(dialog).getByLabelText(/^Fim/), '2027-01-10T09:00');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(
      await within(dialog).findByText(/fim precisa ser depois do início/),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));

    await user.click(
      screen.getByRole('button', { name: /^Excluir: Negócio todo/ }),
    );
    await user.click(screen.getByRole('button', { name: 'Excluir de vez' }));
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'time-offs/o1'),
    );
  });

  it('CA-07 filters the time offs by person', async () => {
    const user = userEvent.setup();
    render(view());

    await user.selectOptions(screen.getByLabelText('Ver folgas de'), 'p1');

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        expect.stringMatching(
          /^time-offs\?limit=20&from=.+&professional_id=p1$/,
        ),
      ),
    );
  });

  it('CA-10 shows the load errors and the first-use message', () => {
    const { unmount } = render(view({ initial: null, timeOffs: null }));
    expect(screen.getAllByRole('alert').length).toBe(2);
    unmount();

    render(
      view({
        initial: { data: [], next_cursor: null },
        timeOffs: { data: [], next_cursor: null },
      }),
    );
    expect(screen.getByText(/ainda não tem profissionais/)).toBeInTheDocument();
    expect(screen.getByText('Nenhuma folga marcada.')).toBeInTheDocument();
  });

  it('CA-10 has no accessibility violations (page and dialogs)', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [{ weekday: 1, start: '09:00', end: '12:00' }] },
    });
    const user = userEvent.setup();
    const { container } = render(view());

    expect(await axe(container)).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Novo profissional' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
    await user.click(screen.getByRole('button', { name: 'Cancelar' }));
    await user.click(
      screen.getByRole('button', { name: 'Expediente: Ana Tattoo' }),
    );
    expect(await axe(await screen.findByRole('dialog'))).toHaveNoViolations();
    await user.click(screen.getAllByRole('button', { name: 'Fechar' })[0]!);
    await user.click(screen.getByRole('button', { name: 'Nova folga' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});
