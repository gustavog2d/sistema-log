export type AdminRole = 'super_admin' | 'suporte';

export type AdminMe = {
  id: string;
  name: string;
  email: string;
  role: AdminRole;
};

export type Page<T> = { data: T[]; next_cursor: string | null };

export type TenantStatus = 'trialing' | 'active' | 'suspended' | 'canceled';

export type TenantItem = {
  id: string;
  name: string;
  slug: string;
  niche: string | null;
  created_at: string;
  plan: string | null;
  cycle: 'monthly' | 'yearly' | null;
  status: TenantStatus | null;
  price_cents: number | null;
  currency: string | null;
  trial_ends_at: string | null;
  current_period_end: string | null;
  cancel_at_period_end: boolean;
  suspended_reason: string | null;
};

export type Entitlement = {
  feature: string;
  enabled: boolean;
  limit: number | null;
  visible: boolean;
  source: 'override' | 'plan' | 'default';
};

export type TenantInvoice = {
  id: string;
  period_start: string;
  period_end: string;
  amount_cents: number;
  currency: string;
  status: 'paid' | 'failed';
  paid_at: string | null;
};

export type TenantDetail = TenantItem & {
  owner: { name: string; email: string } | null;
  active_people: number;
  payment_method: {
    type: string;
    brand: string | null;
    last4: string | null;
  } | null;
  coupon: { code: string; percent_off: number } | null;
  invoices: TenantInvoice[];
  entitlements: Entitlement[];
};

export type Coupon = {
  id: string;
  code: string;
  percent_off: number;
  status: 'active' | 'inactive';
  max_redemptions: number | null;
  redeemed_count: number;
  valid_from: string | null;
  valid_until: string | null;
  description: string | null;
  created_at: string;
};

export type PlanStatus = 'draft' | 'active' | 'archived';

export type Plan = {
  id: string;
  key: string;
  name: string;
  position: number;
  status: PlanStatus;
  trial_days: number;
  currency: string;
  monthly_price_cents: number | null;
  yearly_discount_percent: number | null;
  yearly_price_cents: number | null;
};

export type LeadStatus = 'new' | 'contacted' | 'converted' | 'discarded';

export type Lead = {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  niche: string | null;
  message: string | null;
  utm_source: string | null;
  utm_medium: string | null;
  utm_campaign: string | null;
  utm_term: string | null;
  utm_content: string | null;
  referrer: string | null;
  landing_path: string | null;
  status: LeadStatus;
  notes: string | null;
  created_at: string;
  updated_at: string;
};

/** Só o super admin escreve; o suporte lê e entra como assinante (a API decide, a tela só reflete). */
export function canWrite(role: AdminRole): boolean {
  return role === 'super_admin';
}
