import { slugProblem } from '@/lib/slug';
import { priceOf, type Cycle, type PublicPlan } from '@/lib/plans';

export type SignupForm = {
  businessName: string;
  slug: string;
  slugEdited: boolean;
  niche: string;
  ownerName: string;
  ownerEmail: string;
  password: string;
  plan: string;
  cycle: Cycle;
  coupon: string;
  paymentToken: string;
  acceptTerms: boolean;
};

export type CouponQuote = {
  percent_off: number;
  price_cents: number;
  final_price_cents: number;
  requires_payment_method: boolean;
};

export const STEP_COUNT = 4;

/** Em qual passo cada campo da API aparece (para voltar ao passo certo quando a API recusa). */
export const FIELD_STEP: Record<string, number> = {
  business_name: 0,
  slug: 0,
  niche: 0,
  owner_name: 1,
  owner_email: 1,
  password: 1,
  plan: 2,
  cycle: 2,
  coupon_code: 2,
  payment_method_token: 3,
  accept_terms: 3,
};

/** Chave da API → chave do campo no formulário (para mostrar o erro no campo certo). */
export function stepOfErrors(errors: Record<string, string>): number | null {
  const steps = Object.keys(errors)
    .map((field) => FIELD_STEP[field])
    .filter((step): step is number => step !== undefined);

  return steps.length > 0 ? Math.min(...steps) : null;
}

export const MIN_PASSWORD = 12;
export const MAX_PASSWORD_BYTES = 72;

export function passwordBytes(password: string): number {
  return new TextEncoder().encode(password).length;
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export type StepErrors = Partial<
  Record<keyof SignupForm | 'paymentToken', string>
>;

/** Validação do passo na tela (a API valida de novo). Devolve chaves de tradução. */
export function validateStep(
  step: number,
  form: SignupForm,
  options: { slugState: string; requiresPayment: boolean },
): Record<string, string> {
  const errors: Record<string, string> = {};

  if (step === 0) {
    if (form.businessName.trim().length < 2)
      errors.businessName = 'signup.required';
    if (slugProblem(form.slug) !== null) errors.slug = 'signup.slugInvalid';
    else if (options.slugState === 'taken') errors.slug = 'signup.slugTaken';
    else if (options.slugState === 'reserved')
      errors.slug = 'signup.slugReserved';
    if (!form.niche) errors.niche = 'signup.required';
  }

  if (step === 1) {
    if (form.ownerName.trim().length < 2) errors.ownerName = 'signup.required';
    if (!EMAIL.test(form.ownerEmail.trim()))
      errors.ownerEmail = 'signup.emailInvalid';
    if (form.password.length < MIN_PASSWORD)
      errors.password = 'signup.passwordMissing';
    else if (passwordBytes(form.password) > MAX_PASSWORD_BYTES)
      errors.password = 'signup.passwordTooLong';
  }

  if (step === 2 && !form.plan) {
    errors.plan = 'signup.required';
  }

  if (step === 3) {
    if (options.requiresPayment && !form.paymentToken)
      errors.paymentToken = 'signup.required';
    if (!form.acceptTerms) errors.acceptTerms = 'signup.termsRequired';
  }

  return errors;
}

/** Corpo do `POST /signup` (cartão só quando há o que cobrar). */
export function signupPayload(
  form: SignupForm,
  requiresPayment: boolean,
): Record<string, unknown> {
  return {
    business_name: form.businessName.trim(),
    slug: form.slug,
    niche: form.niche,
    owner_name: form.ownerName.trim(),
    owner_email: form.ownerEmail.trim(),
    password: form.password,
    plan: form.plan,
    cycle: form.cycle,
    accept_terms: form.acceptTerms,
    ...(requiresPayment ? { payment_method_token: form.paymentToken } : {}),
    ...(form.coupon.trim() ? { coupon_code: form.coupon.trim() } : {}),
  };
}

export function finalPrice(
  plan: PublicPlan | undefined,
  cycle: Cycle,
  quote: CouponQuote | null,
): number {
  if (!plan) return 0;

  return quote ? quote.final_price_cents : priceOf(plan, cycle);
}
