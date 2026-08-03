'use client';

import Link from 'next/link';
import { useState } from 'react';
import { register, SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';

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
      <div className="mx-auto flex w-full max-w-md flex-col items-center gap-4 py-14 text-center">
        <span className="grid h-16 w-16 place-items-center rounded-full bg-green-50 text-green-600 dark:bg-green-950/40">
          <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 4h16v16H4z" opacity="0" />
            <path d="M22 6 12 16l-4-4M2 12l4 4" opacity="0" />
            <path d="m5 13 4 4L19 7" />
          </svg>
        </span>
        <h1 className="text-2xl font-extrabold tracking-tight">Hesabınız oluşturuldu</h1>
        <p className="max-w-sm text-ink-600 dark:text-ink-300">
          E-posta adresinize bir doğrulama bağlantısı gönderdik. Bağlantıya tıkladıktan sonra giriş
          yapabilirsiniz.
        </p>
        <Link href="/giris" className={`${ui.btnPrimary} mt-2`}>
          Giriş sayfasına git
        </Link>
      </div>
    );
  }

  return (
    <div className="mx-auto flex w-full max-w-md flex-col gap-6 py-6 sm:py-10">
      <div className="text-center">
        <span className="text-2xl font-extrabold tracking-tight">
          <span className="text-brand-500">raf</span>tabul
        </span>
        <h1 className="mt-4 text-2xl font-extrabold tracking-tight">Kayıt ol</h1>
        <p className="mt-1 text-sm text-ink-500">Dakikalar içinde hesabınızı oluşturun.</p>
      </div>

      <div className={`${ui.card} p-6 shadow-[0_24px_60px_-32px_rgba(20,25,35,.3)] sm:p-8`}>
        <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Ad" error={errors.first_name?.[0]}>
              <input type="text" required autoComplete="given-name" {...field('firstName')} className={ui.field} />
            </Field>

            {/* Optional by decision (ADR-012): sole traders and single-name cultures
                must be representable, so this is not marked required here either. */}
            <Field label="Soyad (isteğe bağlı)" error={errors.last_name?.[0]}>
              <input type="text" autoComplete="family-name" {...field('lastName')} className={ui.field} />
            </Field>
          </div>

          <Field label="E-posta" error={errors.email?.[0]}>
            <input type="email" required autoComplete="email" {...field('email')} className={ui.field} />
          </Field>

          <Field label="Parola" error={errors.password?.[0]}>
            <input type="password" required autoComplete="new-password" {...field('password')} className={ui.field} />
          </Field>

          <Field label="Parola (tekrar)">
            <input type="password" required autoComplete="new-password" {...field('passwordConfirmation')} className={ui.field} />
          </Field>

          {message !== null && (
            <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{message}</p>
          )}

          <p className="text-xs text-ink-500">
            Kayıt olarak kullanım koşullarını ve gizlilik politikasını kabul etmiş olursunuz.
          </p>

          <button type="submit" disabled={busy} className={`${ui.btnPrimary} mt-1 w-full`}>
            {busy ? 'Oluşturuluyor…' : 'Hesap oluştur'}
          </button>
        </form>
      </div>

      <p className="text-center text-sm text-ink-500">
        Zaten hesabınız var mı?{' '}
        <Link href="/giris" className="font-bold text-brand-600 hover:underline">
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
    <label className="flex flex-col gap-1.5 text-sm font-semibold text-ink-700 dark:text-ink-200">
      {label}
      {children}
      {error !== undefined && <span className="font-normal text-xs text-red-600">{error}</span>}
    </label>
  );
}
