/** Plano como a API pública o devolve (`GET /plans`). */
export type PublicPlan = {
  key: string;
  name: string;
  position: number;
  trial_days: number;
  currency: string;
  monthly_price_cents: number;
  yearly_price_cents: number;
  features: Array<{ feature: string; enabled: boolean; limit: number | null }>;
};

export type Cycle = 'monthly' | 'yearly';

export function priceOf(plan: PublicPlan, cycle: Cycle): number {
  return cycle === 'yearly'
    ? plan.yearly_price_cents
    : plan.monthly_price_cents;
}
