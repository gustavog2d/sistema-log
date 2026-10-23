'use client';

import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { CaptchaUnavailable, getCaptchaToken } from '@/lib/captcha';
import { readOrigin } from '@/lib/utm';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
  CheckboxField,
  SelectField,
  TextareaField,
  TextField,
} from '@/components/ui/inputs';

const NICHES = [
  'tatuagem',
  'manicure',
  'barbearia',
  'estetica_automotiva',
] as const;

type Values = {
  name: string;
  email: string;
  phone: string;
  niche: string;
  message: string;
  privacy: boolean;
  decoy: string;
};

const EMPTY: Values = {
  name: '',
  email: '',
  phone: '',
  niche: '',
  message: '',
  privacy: false,
  decoy: '',
};

/** Contato da landing: vira um lead na API (com a origem da visita e o anti-robô). */
export function ContactForm() {
  const [values, setValues] = useState<Values>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [done, setDone] = useState(false);

  const set = <K extends keyof Values>(key: K, value: Values[K]) =>
    setValues((current) => ({ ...current, [key]: value }));

  async function submit(event: FormEvent) {
    event.preventDefault();
    setNotice(null);
    setErrors({});
    setSending(true);

    try {
      const captcha_token = await getCaptchaToken('lead');
      await call('POST', 'leads', {
        body: {
          name: values.name,
          email: values.email,
          phone: values.phone || null,
          niche: values.niche || null,
          message: values.message || null,
          accept_privacy: values.privacy,
          captcha_token,
          company_site: values.decoy,
          ...readOrigin(),
        },
      });
      setDone(true);
    } catch (error) {
      if (error instanceof CaptchaUnavailable) {
        setNotice(t('contact.captchaUnavailable'));
      } else if (error instanceof ApiError) {
        setErrors(error.fieldErrors);
        const captcha = error.fieldErrors.captcha_token;
        const hasFieldErrors = Object.keys(error.fieldErrors).length > 0;

        if (error.status === 429) {
          setNotice(t('contact.rateLimited'));
        } else if (captcha) {
          setNotice(t('contact.captchaUnavailable'));
        } else if (!hasFieldErrors) {
          setNotice(error.message);
        }
      }
    } finally {
      setSending(false);
    }
  }

  if (done) {
    return (
      <Alert tone="success" title={t('contact.successTitle')}>
        {t('contact.success')}
      </Alert>
    );
  }

  return (
    <form onSubmit={submit} noValidate className="grid gap-4 md:grid-cols-2">
      {notice ? (
        <Alert tone="danger" className="md:col-span-2">
          {notice}
        </Alert>
      ) : null}
      <TextField
        label={t('contact.name')}
        value={values.name}
        onChange={(e) => set('name', e.target.value)}
        error={errors.name}
        required
        autoComplete="name"
      />
      <TextField
        label={t('contact.email')}
        type="email"
        value={values.email}
        onChange={(e) => set('email', e.target.value)}
        error={errors.email}
        required
        autoComplete="email"
      />
      <TextField
        label={t('contact.phone')}
        type="tel"
        hint={t('contact.phoneHint')}
        value={values.phone}
        onChange={(e) => set('phone', e.target.value)}
        error={errors.phone}
        autoComplete="tel"
        inputMode="tel"
      />
      <SelectField
        label={t('contact.niche')}
        value={values.niche}
        onChange={(e) => set('niche', e.target.value)}
        error={errors.niche}
      >
        <option value="">{t('contact.nicheNone')}</option>
        {NICHES.map((niche) => (
          <option key={niche} value={niche}>
            {t(`landing.niches.${niche}`)}
          </option>
        ))}
      </SelectField>
      <TextareaField
        className="md:col-span-2"
        label={t('contact.message')}
        value={values.message}
        onChange={(e) => set('message', e.target.value)}
        error={errors.message}
        maxLength={500}
      />
      {/* Campo isca: invisível para pessoas, fora do teclado e do leitor de tela; robôs costumam preenchê-lo. */}
      <div
        aria-hidden="true"
        className="absolute -left-[9999px] h-0 w-0 overflow-hidden"
      >
        <label>
          {t('contact.decoy')}
          <input
            type="text"
            name="company_site"
            tabIndex={-1}
            autoComplete="off"
            value={values.decoy}
            onChange={(e) => set('decoy', e.target.value)}
          />
        </label>
      </div>
      <CheckboxField
        className="md:col-span-2"
        label={t('contact.privacy')}
        checked={values.privacy}
        onChange={(e) => set('privacy', e.target.checked)}
        error={errors.accept_privacy}
      />
      <div className="md:col-span-2">
        <Button type="submit" loading={sending}>
          {sending ? t('contact.sending') : t('contact.submit')}
        </Button>
      </div>
    </form>
  );
}
