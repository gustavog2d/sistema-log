'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { safeReturnPath } from '@/lib/safe-path';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TextField } from '@/components/ui/inputs';
import { TwoFactorSetup } from './two-factor-setup';

type Step = 'credentials' | 'code' | 'setup';

/** Entrar no painel: e-mail e senha, depois o código do 2FA (ou a configuração do 2FA, se a dona precisar). O token fica no cookie da ponte. */
export function LoginForm({ returnTo }: { returnTo?: string }) {
  const router = useRouter();
  const [step, setStep] = useState<Step>('credentials');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function enter() {
    router.replace(safeReturnPath(returnTo));
    router.refresh();
  }

  function failure(error: unknown, invalidText: string) {
    if (error instanceof ApiError && error.status === 423) {
      setNotice(t('login.locked'));
    } else if (error instanceof ApiError && error.status === 429) {
      setNotice(t('login.rateLimited'));
    } else if (
      error instanceof ApiError &&
      (error.status === 401 || error.status === 422)
    ) {
      setNotice(invalidText);
    } else {
      setNotice(
        error instanceof ApiError ? error.message : t('common.genericError'),
      );
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    setNotice(null);
    setBusy(true);

    try {
      const { data } = await call<{
        authenticated?: boolean;
        next?: 'verify' | 'setup';
      }>('POST', 'auth/login', { body: { email: email.trim(), password } });

      if (data.authenticated) {
        enter();
      } else if (data.next === 'verify') {
        setPassword('');
        setStep('code');
      } else if (data.next === 'setup') {
        setPassword('');
        setStep('setup');
      }
    } catch (error) {
      failure(error, t('login.invalid'));
    } finally {
      setBusy(false);
    }
  }

  async function verify(event: FormEvent) {
    event.preventDefault();
    setNotice(null);
    setBusy(true);

    try {
      await call('POST', 'auth/2fa/verify', { body: { code: code.trim() } });
      enter();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401 && !error.code) {
        setNotice(t('login.codeInvalid'));
      } else {
        failure(error, t('login.codeInvalid'));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card className="mx-auto w-full max-w-md">
      {step === 'credentials' ? (
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          <div>
            <h1 className="text-2xl font-bold text-ink">{t('login.title')}</h1>
            <p className="mt-1 text-muted">{t('login.subtitle')}</p>
          </div>
          {info ? <Alert tone="success">{info}</Alert> : null}
          {notice ? <Alert tone="danger">{notice}</Alert> : null}
          <TextField
            label={t('login.email')}
            type="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            autoComplete="username"
            required
          />
          <TextField
            label={t('login.password')}
            type="password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            autoComplete="current-password"
            required
          />
          <Button type="submit" loading={busy}>
            {busy ? t('login.submitting') : t('login.submit')}
          </Button>
          <Link
            href="/esqueci-senha"
            className="text-sm font-medium text-brand-700 underline"
          >
            {t('login.forgot')}
          </Link>
        </form>
      ) : null}

      {step === 'code' ? (
        <form
          onSubmit={(event) => void verify(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          <div>
            <h1 className="text-2xl font-bold text-ink">
              {t('login.codeTitle')}
            </h1>
            <p className="mt-1 text-muted">{t('login.codeText')}</p>
          </div>
          {notice ? <Alert tone="danger">{notice}</Alert> : null}
          <TextField
            label={t('login.code')}
            value={code}
            onChange={(event) => setCode(event.target.value)}
            inputMode="text"
            autoComplete="one-time-code"
            autoCapitalize="none"
            required
          />
          <Button type="submit" loading={busy}>
            {t('login.codeSubmit')}
          </Button>
        </form>
      ) : null}

      {step === 'setup' ? (
        <div className="flex flex-col gap-4">
          <div>
            <h1 className="text-2xl font-bold text-ink">
              {t('login.setupTitle')}
            </h1>
            <p className="mt-1 text-muted">{t('login.setupText')}</p>
          </div>
          <TwoFactorSetup
            onDone={() => {
              setStep('credentials');
              setNotice(null);
              setInfo(t('login.setupDone'));
            }}
          />
        </div>
      ) : null}
    </Card>
  );
}
