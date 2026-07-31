'use client';

import Link from 'next/link';
import { useState } from 'react';
import { register, SessionApiError } from '@/lib/session-api';

/**
 * Create a customer account (§2.2).
 *
 * IT DOES NOT SIGN THEM IN, and the screen says so rather than pretending. The
 * API returns 201 with no session because the address is unverified until they
 * click the emailed link — so this ends on "check your inbox", which is the true
 * state, instead of dropping them into a storefront that will 401 on the first
 * write.
 *
 * VALIDATION MESSAGES COME FROM THE SERVER, per field. Mirroring Laravel's
 * password rules in TypeScript would give two sources of truth for one policy,
 * and the copy that drifted would be this one — telling a customer their password
 * is fine and then refusing it.
 */
export default function RegisterPage() {
  const [form, setForm] = useState({
    firstName: '',
    lastName: '',
    email: '',
    password: '',
    passwordConfirmation: '',
  });

  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  function field(name: keyof typeof form) {
    return {
      value: form[name],
      onChange: (event: React.ChangeEvent<HTMLInputElement>) =>
        setForm((current) => ({ ...current, [name]: event.target.value })),
    };
  }

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);

    try {
      await register(form);
      setDone(true);
    } catch (caught) {
      if (caught instanceof SessionApiError) {
        setErrors(caught.errors);
        setMessage(Object.keys(caught.errors).length === 0 ? caught.message : null);
      } else {
        setMessage('Kayıt tamamlanamadı. Lütfen tekrar deneyin.');
      }
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className="mx-auto flex w-full max-w-sm flex-col gap-4 py-12 text-center">
        <h1 className="text-2xl font-bold">Hesabınız oluşturuldu</h1>
        <p className="text-ink-600 dark:text-ink-300">
          E-posta adresinize bir doğrulama bağlantısı gönderdik. Bağlantıya tıkladıktan sonra giriş
          yapabilirsiniz.
        </p>
        <Link
          href="/giris"
          className="mx-auto rounded-lg bg-brand-500 px-5 py-2.5 font-semibold text-white transition hover:bg-brand-600"
        >
          Giriş sayfasına git
        </Link>
      </div>
    );
  }

  return (
    <div className="mx-auto flex w-full max-w-sm flex-col gap-6 py-8">
      <h1 className="text-2xl font-bold">Kayıt ol</h1>

      <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-4">
        <Field label="Ad" error={errors.first_name?.[0]}>
          <input
            type="text"
            required
            autoComplete="given-name"
            {...field('firstName')}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </Field>

        {/* Optional by decision (ADR-012): sole traders and single-name cultures
            must be representable, so this is not marked required here either. */}
        <Field label="Soyad (isteğe bağlı)" error={errors.last_name?.[0]}>
          <input
            type="text"
            autoComplete="family-name"
            {...field('lastName')}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </Field>

        <Field label="E-posta" error={errors.email?.[0]}>
          <input
            type="email"
            required
            autoComplete="email"
            {...field('email')}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </Field>

        <Field label="Parola" error={errors.password?.[0]}>
          <input
            type="password"
            required
            autoComplete="new-password"
            {...field('password')}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </Field>

        <Field label="Parola (tekrar)">
          <input
            type="password"
            required
            autoComplete="new-password"
            {...field('passwordConfirmation')}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </Field>

        {message !== null && <p className="text-sm text-red-600">{message}</p>}

        <p className="text-xs text-ink-500">
          Kayıt olarak kullanım koşullarını ve gizlilik politikasını kabul etmiş olursunuz.
        </p>

        <button
          type="submit"
          disabled={busy}
          className="rounded-lg bg-brand-500 px-4 py-2.5 font-semibold text-white transition hover:bg-brand-600 disabled:opacity-60"
        >
          {busy ? 'Oluşturuluyor…' : 'Hesap oluştur'}
        </button>
      </form>

      <p className="text-sm text-ink-500">
        Zaten hesabınız var mı?{' '}
        <Link href="/giris" className="text-brand-600 hover:underline">
          Giriş yapın
        </Link>
      </p>
    </div>
  );
}

function Field({
  label,
  error,
  children,
}: {
  label: string;
  error?: string;
  children: React.ReactNode;
}) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      {label}
      {children}
      {error !== undefined && <span className="text-xs text-red-600">{error}</span>}
    </label>
  );
}
