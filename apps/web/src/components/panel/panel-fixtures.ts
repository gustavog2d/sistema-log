import type {
  Invoice,
  Me,
  Role,
  Subscription,
  TeamInvitation,
  TeamMember,
} from '@/lib/panel-types';

export const NOW = Date.parse('2026-10-25T12:00:00Z');

export function me(role: Role = 'owner', extra: Partial<Me> = {}): Me {
  return {
    user: { id: 'u-1', name: 'Ana Souza', email: 'ana@exemplo.com', role },
    tenant: { id: 't-1', name: 'Estúdio Tinta Viva', slug: 'tintaviva' },
    can_write: true,
    two_factor_enabled: false,
    impersonating: false,
    ...extra,
  };
}

export function subscription(extra: Partial<Subscription> = {}): Subscription {
  return {
    status: 'active',
    plan: { key: 'profissional', name: 'Profissional' },
    cycle: 'monthly',
    price_cents: 5000,
    currency: 'BRL',
    trial_ends_at: null,
    current_period_end: '2026-11-20T12:00:00Z',
    next_charge_at: '2026-11-20T12:00:00Z',
    suspended_at: null,
    can_write: true,
    payment_method: { type: 'card', brand: 'visa', last4: '4242' },
    cancel_at_period_end: false,
    ...extra,
  };
}

export const INVOICES: Invoice[] = [
  {
    id: 'i-1',
    period_start: '2026-09-20T12:00:00Z',
    period_end: '2026-10-20T12:00:00Z',
    amount_cents: 5000,
    currency: 'BRL',
    status: 'paid',
    paid_at: '2026-09-20T12:00:00Z',
  },
  {
    id: 'i-2',
    period_start: '2026-08-20T12:00:00Z',
    period_end: '2026-09-20T12:00:00Z',
    amount_cents: 5000,
    currency: 'BRL',
    status: 'failed',
    paid_at: null,
  },
];

export const MEMBERS: TeamMember[] = [
  {
    id: 'u-1',
    name: 'Ana Souza',
    email: 'ana@exemplo.com',
    role: 'owner',
    status: 'active',
  },
  {
    id: 'u-2',
    name: 'Beto Lima',
    email: 'beto@exemplo.com',
    role: 'professional',
    status: 'active',
  },
  {
    id: 'u-3',
    name: 'Cris Dias',
    email: 'cris@exemplo.com',
    role: 'reception',
    status: 'inactive',
  },
];

export const INVITATIONS: TeamInvitation[] = [
  {
    id: 'inv-1',
    name: 'Dani Rocha',
    email: 'dani@exemplo.com',
    role: 'manager',
    status: 'invited',
    expires_at: '2026-11-01T12:00:00Z',
  },
];
