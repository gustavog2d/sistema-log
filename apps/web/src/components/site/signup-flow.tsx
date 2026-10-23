'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { t, tf, type MessageKey } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatBRL, yearlySavings } from '@/lib/format';
import { priceOf, type Cycle, type PublicPlan } from '@/lib/plans';
import { slugProblem, slugify } from '@/lib/slug';
import { useLocation } from '@/lib/use-location';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CheckboxField, SelectField, TextField } from '@/components/ui/inputs';
import {
  finalPrice,
  MIN_PASSWORD,
  signupPayload,
  stepOfErrors,
  STEP_COUNT,
  validateStep,
  type CouponQuote,
  type SignupForm,
} from './signup-model';

const NICHES = [
  'tatuagem',
  'manicure',
  'barbearia',
  'estetica_automotiva',
] as const;
const STEPS = ['business', 'owner', 'plan', 'payment'] as const;
const TEST_CARDS = [
  { token: 'tok_ok', label: 'signup.payOk' },
  { token: 'tok_fail_on_charge', label: 'signup.payFail' },
  { token: 'tok_declined', label: 'signup.payDeclined' },
] as const;

type SlugState =
  'idle' | 'checking' | 'available' | 'taken' | 'reserved' | 'invalid';

const paymentIsFake = () => process.env.NEXT_PUBLIC_PAYMENT_MODE === 'fake';

function newKey(): string {
  return (
    globalThis.crypto?.randomUUID?.() ??
    `k-${Date.now()}-${Math.random().toString(36).slice(2)}`
  );
}

/** Cadastro em 4 passos: negócio, você, plano e pagamento. Mantém o que foi digitado ao voltar ou errar. */
export function SignupFlow({
  plans,
  initialPlan,
}: {
  plans: PublicPlan[] | null;
  initialPlan?: string;
}) {
  const router = useRouter();
  const location = useLocation();
  const first = plans?.find((plan) => plan.key === initialPlan) ?? plans?.[0];
  const [form, setForm] = useState<SignupForm>({
    businessName: '',
    slug: '',
    slugEdited: false,
    niche: '',
    ownerName: '',
    ownerEmail: '',
    password: '',
    plan: first?.key ?? '',
    cycle: 'monthly',
    coupon: '',
    paymentToken: '',
    acceptTerms: false,
  });
  const [step, setStep] = useState(0);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [quote, setQuote] = useState<CouponQuote | null>(null);
  const [couponError, setCouponError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const heading = useRef<HTMLHeadingElement>(null);
  const moved = useRef(false);
  const attempt = useRef<{ body: string; key: string } | null>(null);

  const plan = plans?.find((candidate) => candidate.key === form.plan);
  const requiresPayment = quote ? quote.requires_payment_method : true;
  const set = <K extends keyof SignupForm>(key: K, value: SignupForm[K]) =>
    setForm((current) => ({ ...current, [key]: value }));
  const message = (key: string) =>
    key === 'signup.passwordMissing'
      ? tf(key, { n: Math.max(0, MIN_PASSWORD - form.password.length) })
      : t(key as MessageKey);

  // O foco vai para o título do passo quando a pessoa avança ou volta (não na primeira exibição).
  useEffect(() => {
    if (moved.current) {
      heading.current?.focus();
    }

    moved.current = true;
  }, [step]);

  // Disponibilidade do endereço: o formato se confere na hora; "livre ou ocupado" vem da API, com atraso,
  // sem travar a digitação e descartando respostas de endereços que já mudaram.
  const [remoteSlug, setRemoteSlug] = useState<{
    slug: string;
    state: SlugState;
  } | null>(null);
  const localProblem = form.slug ? slugProblem(form.slug) : null;
  const slugState: SlugState = !form.slug
    ? 'idle'
    : localProblem
      ? localProblem === 'reserved'
        ? 'reserved'
        : 'invalid'
      : remoteSlug?.slug === form.slug
        ? remoteSlug.state
        : 'checking';

  useEffect(() => {
    if (!form.slug || slugProblem(form.slug) !== null) {
      return;
    }

    const slug = form.slug;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      call<{ available: boolean; reason?: string }>(
        'GET',
        `signup/slug-availability?slug=${encodeURIComponent(slug)}`,
        { signal: controller.signal },
      )
        .then(({ data }) =>
          setRemoteSlug({
            slug,
            state: data.available
              ? 'available'
              : data.reason === 'reserved'
                ? 'reserved'
                : data.reason === 'invalid'
                  ? 'invalid'
                  : 'taken',
          }),
        )
        .catch((error: unknown) => {
          if (!controller.signal.aborted) {
            setRemoteSlug({ slug, state: 'idle' });
            if (error instanceof ApiError && error.status === 429)
              setNotice(t('signup.rateLimited'));
          }
        });
    }, 400);

    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [form.slug]);

  async function fetchQuote(plan: string, cycle: Cycle): Promise<void> {
    try {
      const { data } = await call<CouponQuote>('POST', 'signup/coupon-check', {
        body: { code: form.coupon.trim(), plan, cycle },
      });
      setQuote(data);
      setCouponError(null);
    } catch (error) {
      setQuote(null);
      setCouponError(
        error instanceof ApiError && error.status === 429
          ? t('signup.rateLimited')
          : t('signup.couponInvalid'),
      );
    }
  }

  function applyCoupon() {
    setCouponError(null);
    void fetchQuote(form.plan, form.cycle);
  }

  // Cupom aplicado: refaz a conta quando o plano ou o ciclo mudam.
  function choose(change: Partial<Pick<SignupForm, 'plan' | 'cycle'>>) {
    setForm((current) => ({ ...current, ...change }));

    if (quote && form.coupon.trim()) {
      void fetchQuote(change.plan ?? form.plan, change.cycle ?? form.cycle);
    }
  }

  function changeName(value: string) {
    setForm((current) => ({
      ...current,
      businessName: value,
      slug: current.slugEdited ? current.slug : slugify(value),
    }));
  }

  function next(event: FormEvent) {
    event.preventDefault();
    const found = validateStep(step, form, { slugState, requiresPayment });

    if (Object.keys(found).length > 0) {
      setErrors(
        Object.fromEntries(
          Object.entries(found).map(([key, value]) => [key, message(value)]),
        ),
      );

      return;
    }

    setErrors({});
    setNotice(null);

    if (step < STEP_COUNT - 1) {
      setStep(step + 1);

      return;
    }

    void submit();
  }

  async function submit() {
    const body = JSON.stringify(signupPayload(form, requiresPayment));

    // O mesmo envio repetido (clique duplo, rede lenta) usa a mesma chave; mudar os dados gera uma nova.
    if (attempt.current?.body !== body) {
      attempt.current = { body, key: newKey() };
    }

    setSubmitting(true);
    setNotice(null);

    try {
      const { data } = await call<{ subscription: { trial_ends_at: string } }>(
        'POST',
        'signup',
        {
          body: JSON.parse(body) as unknown,
          idempotencyKey: attempt.current.key,
        },
      );
      router.push(
        `/cadastro/pronto?negocio=${encodeURIComponent(form.slug)}&fim=${encodeURIComponent(data.subscription.trial_ends_at)}`,
      );
    } catch (error) {
      if (
        error instanceof ApiError &&
        error.fieldErrors &&
        Object.keys(error.fieldErrors).length > 0
      ) {
        setErrors(error.fieldErrors);
        setStep(stepOfErrors(error.fieldErrors) ?? step);
      } else if (error instanceof ApiError && error.status === 409) {
        setNotice(t('signup.inProgress'));
      } else if (error instanceof ApiError && error.status === 429) {
        setNotice(t('signup.rateLimited'));
      } else {
        setNotice(
          error instanceof ApiError ? error.message : t('common.genericError'),
        );
      }
    } finally {
      setSubmitting(false);
    }
  }

  if (!plans || plans.length === 0) {
    return <Alert tone="warning">{t('landing.plansError')}</Alert>;
  }

  const slugHint =
    slugState === 'checking'
      ? t('signup.slugChecking')
      : slugState === 'available'
        ? t('signup.slugFree')
        : form.slug
          ? tf('signup.slugHint', {
              slug: form.slug,
              domain: location?.host ?? '',
            })
          : undefined;
  const slugError =
    errors.slug ??
    (slugState === 'taken'
      ? t('signup.slugTaken')
      : slugState === 'reserved'
        ? t('signup.slugReserved')
        : slugState === 'invalid' && form.slug
          ? t('signup.slugInvalid')
          : undefined);
  const price = finalPrice(plan, form.cycle, quote);

  return (
    <div className="mx-auto max-w-2xl">
      <nav aria-label={t('signup.stepsNav')} className="mb-6">
        <ol className="flex flex-wrap gap-2">
          {STEPS.map((name, index) => (
            <li
              key={name}
              aria-current={index === step ? 'step' : undefined}
              className={cn(
                'rounded-full px-3 py-1 text-sm font-medium',
                index === step
                  ? 'bg-brand-600 text-surface'
                  : index < step
                    ? 'bg-brand-100 text-brand-900'
                    : 'bg-slate-100 text-muted',
              )}
            >
              {index + 1}. {t(`signup.steps.${name}`)}
            </li>
          ))}
        </ol>
      </nav>

      <Card>
        <form onSubmit={next} noValidate className="flex flex-col gap-5">
          <div>
            <p className="text-sm text-muted">
              {tf('signup.stepLabel', { n: step + 1, total: STEP_COUNT })}
            </p>
            <h1
              ref={heading}
              tabIndex={-1}
              className="text-2xl font-bold text-ink outline-none"
            >
              {step === 2
                ? t('signup.planTitle')
                : step === 3
                  ? t('signup.payment')
                  : t(`signup.steps.${STEPS[step]!}`)}
            </h1>
          </div>

          {notice ? <Alert tone="danger">{notice}</Alert> : null}

          {step === 0 ? (
            <>
              <TextField
                label={t('signup.businessName')}
                hint={t('signup.businessNameHint')}
                value={form.businessName}
                onChange={(e) => changeName(e.target.value)}
                error={errors.businessName ?? errors.business_name}
                autoComplete="organization"
                required
              />
              <TextField
                label={t('signup.slug')}
                hint={slugHint}
                value={form.slug}
                onChange={(e) =>
                  setForm((c) => ({
                    ...c,
                    slug: e.target.value.toLowerCase(),
                    slugEdited: true,
                  }))
                }
                error={slugError}
                autoComplete="off"
                autoCapitalize="none"
                spellCheck={false}
                required
              />
              <SelectField
                label={t('signup.niche')}
                value={form.niche}
                onChange={(e) => set('niche', e.target.value)}
                error={errors.niche}
                required
              >
                <option value="">{t('signup.nichePlaceholder')}</option>
                {NICHES.map((niche) => (
                  <option key={niche} value={niche}>
                    {t(`landing.niches.${niche}`)}
                  </option>
                ))}
              </SelectField>
            </>
          ) : null}

          {step === 1 ? (
            <>
              <TextField
                label={t('signup.ownerName')}
                value={form.ownerName}
                onChange={(e) => set('ownerName', e.target.value)}
                error={errors.ownerName ?? errors.owner_name}
                autoComplete="name"
                required
              />
              <TextField
                label={t('signup.ownerEmail')}
                hint={t('signup.ownerEmailHint')}
                type="email"
                value={form.ownerEmail}
                onChange={(e) => set('ownerEmail', e.target.value)}
                error={errors.ownerEmail ?? errors.owner_email}
                autoComplete="email"
                required
              />
              <TextField
                label={t('signup.password')}
                hint={
                  form.password.length >= MIN_PASSWORD
                    ? t('signup.passwordOk')
                    : t('signup.passwordHint')
                }
                type={showPassword ? 'text' : 'password'}
                value={form.password}
                onChange={(e) => set('password', e.target.value)}
                error={errors.password}
                autoComplete="new-password"
                required
              />
              <CheckboxField
                label={t('signup.showPassword')}
                checked={showPassword}
                onChange={(e) => setShowPassword(e.target.checked)}
              />
            </>
          ) : null}

          {step === 2 ? (
            <>
              <fieldset className="flex flex-wrap gap-3">
                <legend className="mb-2 text-sm font-medium text-ink">
                  {t('signup.cycle')}
                </legend>
                {(['monthly', 'yearly'] as Cycle[]).map((cycle) => (
                  <label
                    key={cycle}
                    className={cn(
                      'flex min-h-11 cursor-pointer items-center gap-2 rounded-md border px-4 text-sm font-medium',
                      form.cycle === cycle
                        ? 'border-brand-600 bg-brand-50'
                        : 'border-control-border',
                    )}
                  >
                    <input
                      type="radio"
                      name="cycle"
                      value={cycle}
                      checked={form.cycle === cycle}
                      onChange={() => choose({ cycle })}
                      className="accent-brand-600"
                    />
                    {t(
                      cycle === 'monthly' ? 'signup.monthly' : 'signup.yearly',
                    )}
                  </label>
                ))}
              </fieldset>
              <fieldset className="flex flex-col gap-3">
                <legend className="mb-2 text-sm font-medium text-ink">
                  {t('signup.planTitle')}
                </legend>
                {plans.map((candidate) => {
                  const savings = yearlySavings(
                    candidate.monthly_price_cents,
                    candidate.yearly_price_cents,
                  );

                  return (
                    <label
                      key={candidate.key}
                      className={cn(
                        'flex min-h-11 cursor-pointer items-center justify-between gap-3 rounded-md border p-4',
                        form.plan === candidate.key
                          ? 'border-brand-600 bg-brand-50'
                          : 'border-control-border',
                      )}
                    >
                      <span className="flex items-center gap-3">
                        <input
                          type="radio"
                          name="plan"
                          value={candidate.key}
                          checked={form.plan === candidate.key}
                          onChange={() => choose({ plan: candidate.key })}
                          className="accent-brand-600"
                        />
                        <span className="font-semibold text-ink">
                          {candidate.name}
                        </span>
                      </span>
                      <span className="text-right text-sm text-ink">
                        {formatBRL(priceOf(candidate, form.cycle))}{' '}
                        <span className="text-muted">
                          /{' '}
                          {t(
                            form.cycle === 'monthly'
                              ? 'landing.perMonth'
                              : 'landing.perYear',
                          ).replace('por ', '')}
                        </span>
                        {form.cycle === 'yearly' && savings > 0 ? (
                          <span className="block text-xs text-success-fg">
                            {tf('signup.yearlySave', {
                              amount: formatBRL(savings),
                            })}
                          </span>
                        ) : null}
                      </span>
                    </label>
                  );
                })}
              </fieldset>
              <div className="flex flex-wrap items-end gap-3">
                <TextField
                  className="min-w-48 flex-1"
                  label={t('signup.coupon')}
                  value={form.coupon}
                  onChange={(e) => {
                    set('coupon', e.target.value);
                    setQuote(null);
                    setCouponError(null);
                  }}
                  error={couponError ?? errors.coupon_code}
                  autoComplete="off"
                  autoCapitalize="characters"
                />
                <Button
                  variant="secondary"
                  disabled={!form.coupon.trim()}
                  onClick={applyCoupon}
                >
                  {t('signup.couponApply')}
                </Button>
              </div>
              {quote ? (
                <Alert tone="success">
                  {tf('signup.couponApplied', { percent: quote.percent_off })}{' '}
                  {quote.requires_payment_method
                    ? ''
                    : t('signup.couponCourtesy')}
                </Alert>
              ) : null}
            </>
          ) : null}

          {step === 3 ? (
            <>
              {requiresPayment ? (
                paymentIsFake() ? (
                  <fieldset className="flex flex-col gap-3">
                    <legend className="mb-2 text-sm text-muted">
                      {t('signup.paymentTestNotice')}
                    </legend>
                    {TEST_CARDS.map((card) => (
                      <label
                        key={card.token}
                        className={cn(
                          'flex min-h-11 cursor-pointer items-center gap-3 rounded-md border p-4',
                          form.paymentToken === card.token
                            ? 'border-brand-600 bg-brand-50'
                            : 'border-control-border',
                        )}
                      >
                        <input
                          type="radio"
                          name="payment"
                          value={card.token}
                          checked={form.paymentToken === card.token}
                          onChange={() => set('paymentToken', card.token)}
                          className="accent-brand-600"
                        />
                        {t(card.label)}
                      </label>
                    ))}
                    {(errors.paymentToken ?? errors.payment_method_token) ? (
                      <p className="text-sm font-medium text-danger-fg">
                        {errors.paymentToken ?? errors.payment_method_token}
                      </p>
                    ) : null}
                  </fieldset>
                ) : (
                  <Alert tone="warning">{t('signup.paymentUnavailable')}</Alert>
                )
              ) : (
                <Alert tone="success">{t('signup.noPayment')}</Alert>
              )}
              <section
                aria-labelledby="resumo"
                className="rounded-md bg-slate-100 p-4 text-sm text-ink"
              >
                <h2 id="resumo" className="font-semibold">
                  {t('signup.summary')}
                </h2>
                {plan ? (
                  <>
                    <p className="mt-1">
                      {tf('signup.summaryPlan', {
                        plan: plan.name,
                        cycle: t(
                          form.cycle === 'monthly'
                            ? 'signup.monthly'
                            : 'signup.yearly',
                        ).toLowerCase(),
                      })}
                    </p>
                    <p className="font-semibold">
                      {price === 0
                        ? t('signup.summaryFree')
                        : tf('signup.summaryPrice', {
                            price: formatBRL(price),
                            period: t(
                              form.cycle === 'monthly'
                                ? 'signup.periodMonth'
                                : 'signup.periodYear',
                            ),
                          })}
                    </p>
                    <p className="mt-1 text-muted">
                      {tf('signup.summaryTrial', { days: plan.trial_days })}
                    </p>
                  </>
                ) : null}
              </section>
              <CheckboxField
                label={t('signup.terms')}
                hint={undefined}
                checked={form.acceptTerms}
                onChange={(e) => set('acceptTerms', e.target.checked)}
                error={errors.acceptTerms ?? errors.accept_terms}
              />
              <Link
                href="/termos"
                target="_blank"
                className="text-sm font-medium text-brand-700 underline"
              >
                {t('signup.termsLink')}
              </Link>
            </>
          ) : null}

          <div className="flex flex-wrap justify-between gap-3">
            {step > 0 ? (
              <Button
                variant="secondary"
                onClick={() => {
                  setErrors({});
                  setStep(step - 1);
                }}
              >
                {t('signup.back')}
              </Button>
            ) : (
              <span />
            )}
            <Button
              type="submit"
              loading={submitting}
              disabled={step === 3 && requiresPayment && !paymentIsFake()}
            >
              {step < STEP_COUNT - 1
                ? t('signup.next')
                : submitting
                  ? t('signup.submitting')
                  : t('signup.submit')}
            </Button>
          </div>
        </form>
      </Card>
    </div>
  );
}
