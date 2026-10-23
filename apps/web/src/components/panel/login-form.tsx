'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { safeAdminReturnPath, safeReturnPath } from '@/lib/safe-path';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TextField } from '@/components/ui/inputs';
import { lockedText } from './locked-text';
import { TwoFactorSetup } from './two-factor-setup';

type Step = 'credentials' | 'code' | 'setup';

/** Entrar no painel: e-mail e senha, depois o código do 2FA (ou a configuração do 2FA, se a dona precisar). O token fica no cookie da ponte. */
const VARIANTS = {
  tenant: {
    login: 'auth/login',
    verify: 'auth/2fa/verify',
    setup: 'auth/2fa',
    home: '/',
    forgot: true,
    safe: safeReturnPath,
  },
  admin: {
    login: 'platform/auth/login',
    verify: 'platform/auth/2fa/verify',
    setup: 'platform/2fa',
    home: '/admin',
    forgot: false,
    safe: safeAdminReturnPath,
  },
} as const;

export function LoginForm({
  returnTo,
  startAt = 'credentials',
  variant = 'tenant',
}: {
  returnTo?: string;
  startAt?: Step;
  /** `admin` é o painel da equipe da plataforma (outras rotas da API, sem recuperação de senha por e-mail). */
  variant?: keyof typeof VARIANTS;
}) {
  const config = VARIANTS[variant];
  const router = useRouter();
  const [step, setStep] = useState<Step>(startAt);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [notice, setNotice] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  function enter() {
    router.replace(config.safe(returnTo));
    router.refresh();
  }

  function failure(error: unknown, invalidText: string) {
    if (error instanceof ApiError && error.status === 423) {
      setNotice(lockedText(error));
    } else if (error instanceof ApiError && error.status === 429) {
      setNotice(t('login.rateLimited'));
    } else if (
      error instanceof ApiError &&
      (error.status === 401 || error.status === 422)
    ) {
      setNotice(invalidText);
    } else {
      setNotice(
        error instanceof ApiError && error.isNetwork
          ? t('common.networkError')
          : t('common.genericError'),
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
      }>('POST', config.login, { body: { email: email.trim(), password } });

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
      await call('POST', config.verify, { body: { code: code.trim() } });
      enter();
    } catch (error) {
      failure(error, t('login.codeInvalid'));
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
            <h1 className="text-2xl font-bold text-ink">
              {t(variant === 'admin' ? 'admin.login.title' : 'login.title')}
            </h1>
            <p className="mt-1 text-muted">
              {t(
                variant === 'admin' ? 'admin.login.subtitle' : 'login.subtitle',
              )}
            </p>
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
          {config.forgot ? (
            <Link
              href="/esqueci-senha"
              className="text-sm font-medium text-brand-700 underline"
            >
              {t('login.forgot')}
            </Link>
          ) : null}
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
            <p className="mt-1 text-muted">
              {t(
                variant === 'admin'
                  ? 'admin.login.setupText'
                  : 'login.setupText',
              )}
            </p>
          </div>
          <TwoFactorSetup
            base={config.setup}
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
