import type {
  AdminMe,
  AdminRole,
  Coupon,
  Lead,
  Plan,
  TenantDetail,
  TenantItem,
} from '@/lib/admin-types';

export const adminMe = (role: AdminRole = 'super_admin'): AdminMe => ({
  id: 'u-1',
  name: 'Gustavo',
  email: 'gustavo@exemplo.com',
  role,
});

export const TENANT_ID = '0194a7c0-0000-7000-8000-000000000001';

export const tenantItem = (over: Partial<TenantItem> = {}): TenantItem => ({
  id: TENANT_ID,
  name: 'Estúdio Tinta Viva',
  slug: 'tintaviva',
  niche: 'tatuagem',
  created_at: '2026-10-01T12:00:00Z',
  plan: 'profissional',
  cycle: 'monthly',
  status: 'trialing',
  price_cents: 5000,
  currency: 'BRL',
  trial_ends_at: '2026-11-08T12:00:00Z',
  current_period_end: null,
  cancel_at_period_end: false,
  suspended_reason: null,
  ...over,
});

export const tenantDetail = (
  over: Partial<TenantDetail> = {},
): TenantDetail => ({
  ...tenantItem(),
  owner: { name: 'Ana Souza', email: 'ana@exemplo.com' },
  active_people: 2,
  payment_method: { type: 'card', brand: 'visa', last4: '4242' },
  coupon: null,
  invoices: [
    {
      id: 'i-1',
      period_start: '2026-10-01T12:00:00Z',
      period_end: '2026-11-01T12:00:00Z',
      amount_cents: 5000,
      currency: 'BRL',
      status: 'paid',
      paid_at: '2026-10-01T12:00:00Z',
    },
  ],
  entitlements: [
    {
      feature: 'usuarios.max',
      enabled: true,
      limit: 5,
      visible: true,
      source: 'plan',
    },
    {
      feature: 'agenda',
      enabled: true,
      limit: null,
      visible: true,
      source: 'override',
    },
  ],
  ...over,
});

export const coupon = (over: Partial<Coupon> = {}): Coupon => ({
  id: 'c-1',
  code: 'AMIGA-2026',
  percent_off: 20,
  status: 'active',
  max_redemptions: 10,
  redeemed_count: 3,
  valid_from: null,
  valid_until: '2026-12-31T02:59:59Z',
  description: null,
  created_at: '2026-10-01T12:00:00Z',
  ...over,
});

export const plan = (over: Partial<Plan> = {}): Plan => ({
  id: 'p-1',
  key: 'profissional',
  name: 'Profissional',
  position: 2,
  status: 'active',
  trial_days: 7,
  currency: 'BRL',
  monthly_price_cents: 5000,
  yearly_discount_percent: 10,
  yearly_price_cents: 54000,
  ...over,
});

export const lead = (over: Partial<Lead> = {}): Lead => ({
  id: 'l-1',
  name: 'Bia Lima',
  email: 'bia@exemplo.com',
  phone: '+5511999998888',
  niche: 'manicure',
  message: 'Quero conhecer',
  utm_source: 'instagram',
  utm_medium: null,
  utm_campaign: null,
  utm_term: null,
  utm_content: null,
  referrer: null,
  landing_path: '/',
  status: 'new',
  notes: null,
  created_at: '2026-10-20T12:00:00Z',
  updated_at: '2026-10-20T12:00:00Z',
  ...over,
});
