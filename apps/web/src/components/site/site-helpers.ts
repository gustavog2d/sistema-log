import type { PublicPlan } from '@/lib/plans';

/** Planos de exemplo no formato da API pública (usados nos testes das telas). */
export const PLANS: PublicPlan[] = [
  {
    key: 'essencial',
    name: 'Essencial',
    position: 1,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 1500,
    yearly_price_cents: 16200,
    features: [
      { feature: 'orcamento', enabled: true, limit: null },
      { feature: 'usuarios.max', enabled: true, limit: 2 },
      { feature: 'agenda.publica', enabled: false, limit: null },
    ],
  },
  {
    key: 'profissional',
    name: 'Profissional',
    position: 2,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 5000,
    yearly_price_cents: 54000,
    features: [{ feature: 'orcamento', enabled: true, limit: null }],
  },
  {
    key: 'estudio',
    name: 'Estúdio',
    position: 3,
    trial_days: 7,
    currency: 'BRL',
    monthly_price_cents: 25000,
    yearly_price_cents: 270000,
    features: [{ feature: 'orcamento', enabled: true, limit: null }],
  },
];
