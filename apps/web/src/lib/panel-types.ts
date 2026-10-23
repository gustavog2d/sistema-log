/** Formatos que a API do assinante devolve (PLT-006, PLT-007 e PLT-008), só o que as telas usam. */
export type Role = 'owner' | 'manager' | 'professional' | 'reception';

export type Me = {
  user: { id: string; name: string; email: string; role: Role };
  tenant: { id: string; name: string; slug: string };
  can_write: boolean;
  two_factor_enabled: boolean;
  impersonating: boolean;
};

export type SubscriptionStatus =
  'trialing' | 'active' | 'suspended' | 'canceled';

export type PaymentMethod = {
  type: string;
  brand: string | null;
  last4: string | null;
};

export type Subscription = {
  status: SubscriptionStatus;
  plan: { key: string; name: string };
  cycle: 'monthly' | 'yearly';
  price_cents: number;
  currency: string;
  trial_ends_at: string | null;
  current_period_end: string | null;
  next_charge_at: string | null;
  suspended_at: string | null;
  can_write: boolean;
  payment_method: PaymentMethod | null;
  cancel_at_period_end: boolean;
  pending_plan_change?: PendingPlanChange | null;
};

export type PendingPlanChange = {
  plan: { key: string; name: string };
  price_cents: number;
  effective_at: string;
};

export type PlanChangePreview = {
  kind: 'now' | 'next_cycle';
  plan: { key: string; name: string };
  price_cents: number;
  currency: string;
  charge_now_cents: number;
  effective_at: string;
  violations: Array<{ feature: string; limit: number; usage: number }>;
};

export type Invoice = {
  id: string;
  period_start: string;
  period_end: string;
  amount_cents: number;
  currency: string;
  status: 'paid' | 'failed';
  paid_at: string | null;
};

export type TeamMember = {
  id: string;
  name: string;
  email: string;
  role: Role;
  status: 'active' | 'inactive';
};

export type TeamInvitation = {
  id: string;
  name: string;
  email: string;
  role: Exclude<Role, 'owner'>;
  status: 'invited';
  expires_at: string;
};

/** Quem vê cada área do painel (a API também decide; a tela só reflete). */
export const AREA_ROLES = {
  subscription: ['owner'],
  team: ['owner', 'manager'],
  audit: ['owner', 'manager'],
  account: ['owner', 'manager', 'professional', 'reception'],
  home: ['owner', 'manager', 'professional', 'reception'],
} as const satisfies Record<string, readonly Role[]>;

export type Area = keyof typeof AREA_ROLES;

export function canSee(role: Role, area: Area): boolean {
  return (AREA_ROLES[area] as readonly Role[]).includes(role);
}
