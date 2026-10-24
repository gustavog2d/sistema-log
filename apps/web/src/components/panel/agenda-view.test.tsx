import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { Appointment, AppointmentList } from '@/lib/appointment-types';
import type { Professional } from '@/lib/professional-types';
import type { Service } from '@/lib/service-types';
import { me } from './panel-fixtures';
import { AgendaView } from './agenda-view';

const call = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) =>
    failure ? Promise.reject(failure) : call(...args),
}));

const appointment = (over: Partial<Appointment> = {}): Appointment => ({
  id: 'a1',
  client: { id: 'c1', name: 'Bia Lima' },
  professional: { id: 'p1', name: 'Ana Tattoo', color: '#2563EB' },
  service: { id: 's1', name: 'Tatuagem pequena' },
  starts_at: '2026-11-03T13:00:00+00:00',
  ends_at: '2026-11-03T14:00:00+00:00',
  description: null,
  status: 'scheduled',
  type: 'appointment',
  origin: 'panel',
  session_number: null,
  sessions_total: null,
  value_total_cents: 15000,
  notes: null,
  client_review: null,
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
  ...over,
});

const professional: Professional = {
  id: 'p1',
  name: 'Ana Tattoo',
  color: '#2563EB',
  commission_percent: 40,
  user_id: null,
  status: 'active',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
};

const service: Service = {
  id: 's1',
  name: 'Tatuagem pequena',
  description: null,
  category_id: null,
  duration_minutes: 60,
  price_cents: 15000,
  buffer_before_minutes: 0,
  buffer_after_minutes: 10,
  requires_deposit: false,
  status: 'active',
  created_at: '2026-11-01T12:00:00Z',
  updated_at: '2026-11-01T12:00:00Z',
};

const list = (...rows: Appointment[]): AppointmentList => ({ data: rows });

const view = (props: Partial<React.ComponentProps<typeof AgendaView>> = {}) => (
  <AgendaView
    me={me()}
    initial={list(appointment())}
    weekStart="2026-11-02"
    professionals={[professional]}
    services={[service]}
    {...props}
  />
);

beforeEach(() => {
  failure = null;
  call.mockReset();
  call.mockResolvedValue({ status: 200, data: { data: [] } });
});

describe('AgendaView', () => {
  it('CA-14 lists the week grouped by day with time, client, professional, service, value and status', () => {
    render(view());
    const day = screen.getByRole('region', { name: /terça-feira, 03\/11/i });

    expect(within(day).getByText('10:00–11:00')).toBeInTheDocument();
    expect(within(day).getByText('Bia Lima')).toBeInTheDocument();
    expect(
      within(day).getByText(/Ana Tattoo · Tatuagem pequena/),
    ).toBeInTheDocument();
    expect(within(day).getByText(/150,00/)).toBeInTheDocument();
    expect(within(day).getByText('Agendado')).toBeInTheDocument();
    expect(screen.getByText('Semana de 02/11 a 08/11')).toBeInTheDocument();
  });

  it('CA-14 shows an empty state and a load error', () => {
    const { unmount } = render(view({ initial: list() }));
    expect(
      screen.getByText('Nenhum agendamento nesta semana.'),
    ).toBeInTheDocument();
    unmount();

    render(view({ initial: null }));
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar a agenda agora.',
    );
  });

  it('CA-14 loads the next week with the period on the business clock', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(screen.getByRole('button', { name: 'Próxima semana' }));

    await waitFor(() => expect(call).toHaveBeenCalled());
    const url = decodeURIComponent(String(call.mock.calls[0]?.[1]));
    expect(url).toContain('from=2026-11-09T00:00:00-03:00');
    expect(url).toContain('to=2026-11-16T00:00:00-03:00');
  });

  it('CA-14 filters by professional', async () => {
    const user = userEvent.setup();
    render(view());

    await user.selectOptions(screen.getByLabelText('Profissional'), 'p1');

    await waitFor(() => expect(call).toHaveBeenCalled());
    expect(String(call.mock.calls[0]?.[1])).toContain('professional_id=p1');
  });

  it('CA-14 offers only the actions the status allows and changes the status', async () => {
    call.mockImplementation((method: string) =>
      Promise.resolve({
        status: 200,
        data: method === 'GET' ? list() : appointment({ status: 'completed' }),
      }),
    );
    const user = userEvent.setup();
    render(view());

    expect(
      screen.getByRole('button', { name: 'Finalizar: Bia Lima' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Confirmar: Bia Lima' }),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole('button', { name: 'Finalizar: Bia Lima' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'appointments/a1', {
        body: { status: 'completed' },
      }),
    );
    expect(await screen.findByText('Situação atualizada.')).toBeInTheDocument();
  });

  it('CA-14 gives a completed appointment no status action and no delete', () => {
    render(view({ initial: list(appointment({ status: 'completed' })) }));

    expect(
      screen.queryByRole('button', { name: /Finalizar|Cancelar|Faltou/ }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: 'Excluir: Bia Lima' }),
    ).not.toBeInTheDocument();
  });

  it('CA-14 reopens a canceled appointment through the dialog so the time is checked again', async () => {
    const user = userEvent.setup();
    render(view({ initial: list(appointment({ status: 'canceled' })) }));

    await user.click(
      screen.getByRole('button', { name: 'Reagendar: Bia Lima' }),
    );

    expect(
      screen.getByRole('dialog', { name: 'Reagendar' }),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-14 books: searches the client, sends the instant on the business offset and refreshes the week', async () => {
    call.mockImplementation((method: string, path: string) =>
      Promise.resolve(
        path.startsWith('clients')
          ? {
              status: 200,
              data: {
                data: [{ id: 'c1', name: 'Bia Lima', phone: '+5511999998888' }],
                next_cursor: null,
              },
            }
          : {
              status: method === 'POST' ? 201 : 200,
              data:
                method === 'POST' ? appointment() : { data: [appointment()] },
            },
      ),
    );
    const user = userEvent.setup();
    render(view({ initial: list() }));

    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    const dialog = screen.getByRole('dialog', { name: 'Novo agendamento' });
    await user.type(within(dialog).getByLabelText(/^Buscar cliente/), 'Bia');
    await user.click(within(dialog).getByRole('button', { name: 'Buscar' }));
    await within(dialog).findByLabelText('Cliente');
    await user.selectOptions(within(dialog).getByLabelText('Serviço'), 's1');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'POST',
        'appointments',
        expect.objectContaining({
          body: expect.objectContaining({
            client_id: 'c1',
            professional_id: 'p1',
            service_id: 's1',
          }),
        }),
      ),
    );
    const post = call.mock.calls.find((c) => c[0] === 'POST');
    expect(JSON.stringify(post?.[2])).toContain('-03:00');
    expect(await screen.findByText('Agendamento criado.')).toBeInTheDocument();
  });

  it('CA-14 asks to confirm the fit when the time is outside the hours, then resends with the permission', async () => {
    call.mockImplementation((method: string, path: string) => {
      if (path.startsWith('clients')) {
        return Promise.resolve({
          status: 200,
          data: {
            data: [{ id: 'c1', name: 'Bia Lima', phone: '+5511999998888' }],
            next_cursor: null,
          },
        });
      }

      return Promise.resolve({ status: 200, data: { data: [] } });
    });
    const user = userEvent.setup();
    render(view({ initial: list() }));
    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    const dialog = screen.getByRole('dialog', { name: 'Novo agendamento' });
    await user.type(within(dialog).getByLabelText(/^Buscar cliente/), 'Bia');
    await user.click(within(dialog).getByRole('button', { name: 'Buscar' }));
    await within(dialog).findByLabelText('Cliente');

    failure = new ApiError('x', 422, {}, 'outside_availability');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));
    expect(
      await within(dialog).findByText('Fora do expediente'),
    ).toBeInTheDocument();

    failure = null;
    call.mockClear();
    await user.click(
      within(dialog).getByRole('button', { name: 'Encaixar mesmo assim' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'POST',
        'appointments',
        expect.objectContaining({
          body: expect.objectContaining({ allow_outside_hours: true }),
        }),
      ),
    );
  });

  it('CA-14 explains a conflict and keeps the dialog open', async () => {
    call.mockImplementation((_m: string, path: string) =>
      Promise.resolve(
        path.startsWith('clients')
          ? {
              status: 200,
              data: {
                data: [{ id: 'c1', name: 'Bia Lima', phone: '+5511999998888' }],
                next_cursor: null,
              },
            }
          : { status: 200, data: { data: [] } },
      ),
    );
    const user = userEvent.setup();
    render(view({ initial: list() }));
    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    const dialog = screen.getByRole('dialog', { name: 'Novo agendamento' });
    await user.type(within(dialog).getByLabelText(/^Buscar cliente/), 'Bia');
    await user.click(within(dialog).getByRole('button', { name: 'Buscar' }));
    await within(dialog).findByLabelText('Cliente');

    failure = new ApiError('x', 409, {}, 'appointment_conflict');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent(
      'Este horário já está ocupado por outro agendamento do profissional.',
    );
  });

  it('CA-14 suggests free slots for the chosen day and fills the start', async () => {
    call.mockImplementation((_m: string, path: string) =>
      Promise.resolve(
        path.includes('availability')
          ? {
              status: 200,
              data: {
                data: [
                  {
                    starts_at: '2026-11-03T12:00:00+00:00',
                    ends_at: '2026-11-03T13:00:00+00:00',
                  },
                ],
              },
            }
          : { status: 200, data: { data: [] } },
      ),
    );
    const user = userEvent.setup();
    render(view({ initial: list() }));
    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    const dialog = screen.getByRole('dialog', { name: 'Novo agendamento' });
    await user.selectOptions(within(dialog).getByLabelText('Serviço'), 's1');

    await user.click(
      within(dialog).getByRole('button', { name: 'Ver horários livres' }),
    );
    await user.click(
      await within(dialog).findByRole('button', { name: '09:00' }),
    );

    expect(within(dialog).getByLabelText('Início')).toHaveValue(
      '2026-11-03T09:00',
    );
    expect(String(call.mock.calls[0]?.[1])).toContain('service_id=s1');
  });

  it('CA-14 lets a completed appointment change only notes, value and review', async () => {
    const user = userEvent.setup();
    render(view({ initial: list(appointment({ status: 'completed' })) }));

    await user.click(screen.getByRole('button', { name: 'Editar: Bia Lima' }));
    const dialog = screen.getByRole('dialog', { name: 'Editar agendamento' });

    expect(within(dialog).queryByLabelText('Início')).not.toBeInTheDocument();
    await user.selectOptions(within(dialog).getByLabelText(/^Avaliação/), '5');
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'appointments/a1', {
        body: { notes: null, value_total_cents: 15000, client_review: 5 },
      }),
    );
  });

  it('CA-07 hides the value from the reception', () => {
    render(
      view({
        me: me('reception'),
        initial: list(appointment({ value_total_cents: null })),
      }),
    );

    expect(screen.queryByText(/150,00/)).not.toBeInTheDocument();
  });

  it('CA-10 deletes after confirming, only for owner and manager', async () => {
    const user = userEvent.setup();
    const { unmount } = render(view());

    await user.click(screen.getByRole('button', { name: 'Excluir: Bia Lima' }));
    const dialog = screen.getByRole('dialog', {
      name: 'Excluir este agendamento?',
    });
    await user.click(
      within(dialog).getByRole('button', { name: 'Sim, excluir' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'appointments/a1'),
    );
    unmount();

    render(view({ me: me('reception') }));
    expect(
      screen.queryByRole('button', { name: 'Excluir: Bia Lima' }),
    ).not.toBeInTheDocument();
  });

  it('CA-05 disables every change when read-only or in a support access', () => {
    const { unmount } = render(view({ me: me('owner', { can_write: false }) }));
    expect(
      screen.getByRole('button', { name: 'Novo agendamento' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Editar: Bia Lima' }),
    ).toBeDisabled();
    unmount();

    render(view({ me: me('owner', { impersonating: true }) }));
    expect(
      screen.getByText(/não estão disponíveis em um acesso de suporte/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Novo agendamento' }),
    ).toBeDisabled();
  });

  it('CA-14 has no accessibility violations (list and dialog)', async () => {
    const user = userEvent.setup();
    const { container } = render(view());
    expect(await axe(container)).toHaveNoViolations();

    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    expect(await axe(screen.getByRole('dialog'))).toHaveNoViolations();
  });
});

describe('AgendaView reactivation and edit', () => {
  it('CA-05 reactivates a canceled appointment sending only the status and notes', async () => {
    call.mockImplementation((method: string) =>
      Promise.resolve({
        status: 200,
        data: method === 'GET' ? list() : appointment(),
      }),
    );
    const user = userEvent.setup();
    render(view({ initial: list(appointment({ status: 'canceled' })) }));

    await user.click(
      screen.getByRole('button', { name: 'Reagendar: Bia Lima' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Reagendar' });
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'appointments/a1', {
        body: { status: 'scheduled', notes: null },
      }),
    );
  });

  it('CA-05 keeps the archived service and the inactive professional as options when editing', async () => {
    const user = userEvent.setup();
    render(
      view({
        services: [],
        professionals: [],
        initial: list(appointment()),
      }),
    );

    await user.click(screen.getByRole('button', { name: 'Editar: Bia Lima' }));
    const dialog = screen.getByRole('dialog', { name: 'Editar agendamento' });

    expect(within(dialog).getByLabelText('Serviço')).toHaveValue('s1');
    expect(within(dialog).getByLabelText('Profissional')).toHaveValue('p1');
  });

  it('CA-07 offers a professional only the own profile', async () => {
    const user = userEvent.setup();
    const mine = { ...professional, id: 'p9', name: 'Eu', user_id: 'u-1' };
    render(
      view({
        me: me('professional'),
        professionals: [professional, mine],
        initial: list(),
      }),
    );

    await user.click(screen.getByRole('button', { name: 'Novo agendamento' }));
    const dialog = screen.getByRole('dialog', { name: 'Novo agendamento' });

    expect(within(dialog).getByLabelText('Profissional')).toHaveValue('p9');
    expect(
      within(dialog).queryByRole('option', { name: 'Ana Tattoo' }),
    ).not.toBeInTheDocument();
  });
});
