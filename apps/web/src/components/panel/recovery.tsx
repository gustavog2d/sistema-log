'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useState, type FormEvent } from 'react';
import { lockedText } from './locked-text';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { CheckboxField, TextField } from '@/components/ui/inputs';
import {
  MAX_PASSWORD_BYTES,
  MIN_PASSWORD,
  passwordBytes,
} from '@/components/site/signup-model';

function passwordProblem(password: string): string | undefined {
  if (password.length < MIN_PASSWORD) {
    return t('recovery.passwordHint');
  }

  return passwordBytes(password) > MAX_PASSWORD_BYTES
    ? t('signup.passwordTooLong')
    : undefined;
}

/** Tira da barra de endereço o que veio no link (token e e-mail): depois de lido, não precisa ficar no histórico. */
function useCleanUrl(): void {
  useEffect(() => {
    window.history.replaceState(null, '', window.location.pathname);
  }, []);
}

/** Pedir o link de recuperação: a resposta é sempre a mesma, exista o e-mail ou não. */
export function ForgotForm() {
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    setBusy(true);

    try {
      await call('POST', 'auth/password/forgot', {
        body: { email: email.trim() },
      });
      setSent(true);
    } catch (failure) {
      setError(
        failure instanceof ApiError && failure.status === 429
          ? t('login.rateLimited')
          : failure instanceof ApiError && failure.status !== 422
            ? failure.message
            : t('login.invalid'),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="mx-auto w-full max-w-md">
      <h1 className="text-2xl font-bold text-ink">
        {t('recovery.forgotTitle')}
      </h1>
      <p className="mt-1 mb-4 text-muted">{t('recovery.forgotText')}</p>
      {sent ? (
        <Alert tone="success">{t('recovery.sent')}</Alert>
      ) : (
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <TextField
            label={t('recovery.email')}
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="email"
            required
          />
          <Button type="submit" loading={busy}>
            {busy ? t('recovery.sending') : t('recovery.send')}
          </Button>
        </form>
      )}
      <p className="mt-6 text-sm">
        <Link href="/entrar" className="font-medium text-brand-700 underline">
          {t('recovery.back')}
        </Link>
      </p>
    </Card>
  );
}

/** Definir a nova senha com o link do e-mail (`?token=&email=`). O link é lido uma vez e some da barra de endereço. */
export function ResetForm({
  token,
  email,
}: {
  token?: string;
  email?: string;
}) {
  const router = useRouter();
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | undefined>();
  const [notice, setNotice] = useState<string | null>(null);
  const [invalid, setInvalid] = useState(false);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);
  useCleanUrl();

  async function submit(event: FormEvent) {
    event.preventDefault();
    const problem = passwordProblem(password);

    if (problem) {
      setError(problem);

      return;
    }

    setError(undefined);
    setNotice(null);
    setBusy(true);

    try {
      await call('POST', 'auth/password/reset', {
        body: { email, token, password },
      });
      setDone(true);
      setPassword('');
      setTimeout(() => router.replace('/entrar'), 1500);
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        failure.status === 422 &&
        failure.fieldErrors.token
      ) {
        setInvalid(true);
      } else if (failure instanceof ApiError && failure.fieldErrors.password) {
        setError(failure.fieldErrors.password);
      } else if (failure instanceof ApiError && failure.status === 429) {
        setNotice(t('login.rateLimited'));
      } else {
        setNotice(
          failure instanceof ApiError
            ? failure.message
            : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  const missing = !token || !email;

  return (
    <Card className="mx-auto w-full max-w-md">
      <h1 className="text-2xl font-bold text-ink">
        {t('recovery.resetTitle')}
      </h1>
      {done ? (
        <Alert tone="success" className="mt-4">
          {t('recovery.resetDone')}
        </Alert>
      ) : missing || invalid ? (
        <div className="mt-4 space-y-3">
          <Alert tone="danger">{t('recovery.invalidLink')}</Alert>
          <Link
            href="/esqueci-senha"
            className="font-medium text-brand-700 underline"
          >
            {t('recovery.askAnother')}
          </Link>
        </div>
      ) : (
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="mt-4 flex flex-col gap-4"
        >
          {notice ? <Alert tone="danger">{notice}</Alert> : null}
          <TextField
            label={t('recovery.password')}
            hint={t('recovery.passwordHint')}
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            error={error}
            autoComplete="new-password"
            required
          />
          <Button type="submit" loading={busy}>
            {busy ? t('recovery.resetting') : t('recovery.reset')}
          </Button>
        </form>
      )}
    </Card>
  );
}

/** Aceitar o convite da equipe (`?token=&email=`): senha e termos; entra direto (ou pede o código do 2FA). */
export function AcceptInviteForm({
  token,
  email,
}: {
  token?: string;
  email?: string;
}) {
  const router = useRouter();
  const [password, setPassword] = useState('');
  const [terms, setTerms] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [invalid, setInvalid] = useState(false);
  const [busy, setBusy] = useState(false);
  useCleanUrl();

  async function submit(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};
    const problem = passwordProblem(password);

    if (problem) found.password = problem;
    if (!terms) found.accept_terms = t('recovery.termsRequired');

    if (Object.keys(found).length > 0) {
      setErrors(found);

      return;
    }

    setErrors({});
    setNotice(null);
    setBusy(true);

    try {
      const { data } = await call<{ authenticated?: boolean; next?: string }>(
        'POST',
        'auth/invitations/accept',
        { body: { email, token, password, accept_terms: true } },
      );
      router.replace(
        data.authenticated
          ? '/'
          : data.next === 'verify'
            ? '/entrar?etapa=codigo'
            : data.next === 'setup'
              ? '/entrar?etapa=configurar'
              : '/entrar',
      );
      router.refresh();
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        failure.status === 422 &&
        failure.fieldErrors.token
      ) {
        setInvalid(true);
      } else if (
        failure instanceof ApiError &&
        failure.status === 422 &&
        failure.fieldErrors.password
      ) {
        setErrors({ password: t('recovery.inviteWrongPassword') });
      } else if (failure instanceof ApiError && failure.status === 423) {
        setNotice(lockedText(failure));
      } else if (failure instanceof ApiError && failure.status === 429) {
        setNotice(t('login.rateLimited'));
      } else {
        setNotice(
          failure instanceof ApiError && failure.isNetwork
            ? t('common.networkError')
            : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  const missing = !token || !email;

  return (
    <Card className="mx-auto w-full max-w-md">
      <h1 className="text-2xl font-bold text-ink">
        {t('recovery.inviteTitle')}
      </h1>
      {missing || invalid ? (
        <Alert tone="danger" className="mt-4">
          {t('recovery.inviteInvalid')}
        </Alert>
      ) : (
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="mt-4 flex flex-col gap-4"
        >
          <p className="text-muted">{t('recovery.inviteText')}</p>
          {notice ? <Alert tone="danger">{notice}</Alert> : null}
          <TextField
            label={t('recovery.invitePassword')}
            hint={t('recovery.inviteExistingText')}
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            error={errors.password}
            autoComplete="new-password"
            required
          />
          <CheckboxField
            label={t('recovery.inviteTerms')}
            checked={terms}
            onChange={(event) => setTerms(event.target.checked)}
            error={errors.accept_terms}
          />
          <Button type="submit" loading={busy}>
            {busy ? t('recovery.inviteAccepting') : t('recovery.inviteAccept')}
          </Button>
        </form>
      )}
    </Card>
  );
}
