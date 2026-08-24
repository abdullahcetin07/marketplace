'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { resetPassword, SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';
import { PasswordInput } from '@/components/PasswordInput';

/**
 * "Şifre sıfırla" — redeem the token from the reset email (§2.2, ADR-025).
 *
 * The email link is `{FRONTEND_URL}/sifre-sifirla/{token}?email=...` (the backend
 * builds it from `FRONTEND_PASSWORD_RESET_PATH`, which MUST be set to
 * `/sifre-sifirla/{token}` for this page to receive the token). The token is a path
 * segment, the address a query param — this page reads both and posts a new password.
 *
 * A CLIENT COMPONENT: the new credential is typed here and goes straight to the API,
 * never through the server. On success the user is sent back to sign in — the reset
 * returns no session (proving possession beats assuming it).
 */
export default function ResetPasswordPage() {
  const params = useParams<{ token: string }>();
  const token = typeof params.token === 'string' ? params.token : '';

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);

  // The address rides in the query string; read it once on the client.
  useEffect(() => {
    setEmail(new URLSearchParams(window.location.search).get('email') ?? '');
  }, []);

  const linkBroken = token === '' || email === '';

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setError(null);
    setFieldError(null);

    if (password !== confirm) {
      setFieldError('Şifreler eşleşmiyor.');
      return;
    }

    setBusy(true);
    try {
      await resetPassword({ email, token, password, passwordConfirmation: confirm });
      setDone(true);
    } catch (caught) {
      if (caught instanceof SessionApiError) {
        const fieldMessage = caught.first('password');
        if (fieldMessage !== undefined) setFieldError(fieldMessage);
        else setError(caught.message);
      } else {
        setError('Şifre sıfırlanamadı. Lütfen tekrar deneyin.');
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="mx-auto flex w-full max-w-md flex-col gap-6 py-6 sm:py-10">
      <div className="text-center">
        <span className="text-2xl font-extrabold tracking-tight">
          <span className="text-brand-500">raf</span>tabul
        </span>
        <h1 className="mt-4 text-2xl font-extrabold tracking-tight">Yeni şifre belirle</h1>
        {!done && !linkBroken && (
          <p className="mt-1 text-sm text-ink-500">
            <span className="font-semibold text-ink-700 dark:text-ink-200">{email}</span> için yeni bir şifre seç.
          </p>
        )}
      </div>

      <div className={`${ui.card} p-6 shadow-[0_24px_60px_-32px_rgba(20,25,35,.3)] sm:p-8`}>
        {done ? (
          <div className="flex flex-col gap-4 text-center">
            <div className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-green-50 text-green-600 dark:bg-green-950/40">
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
                <path d="m5 13 4 4L19 7" />
              </svg>
            </div>
            <p className="text-sm text-ink-600 dark:text-ink-300">
              Şifren güncellendi. Yeni şifrenle giriş yapabilirsin.
            </p>
            <Link href="/giris" className={`${ui.btnPrimary} w-full`}>
              Giriş yap
            </Link>
          </div>
        ) : linkBroken ? (
          <div className="flex flex-col gap-4 text-center">
            <p className="text-sm text-ink-600 dark:text-ink-300">
              Bu sıfırlama bağlantısı geçersiz görünüyor. Lütfen yeni bir bağlantı iste.
            </p>
            <Link href="/sifremi-unuttum" className={`${ui.btnPrimary} w-full`}>
              Yeni bağlantı iste
            </Link>
          </div>
        ) : (
          <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-4">
            <label className="flex flex-col gap-1.5 text-sm font-semibold text-ink-700 dark:text-ink-200">
              Yeni şifre
              <PasswordInput
                value={password}
                onChange={setPassword}
                autoComplete="new-password"
                required
              />
            </label>

            <label className="flex flex-col gap-1.5 text-sm font-semibold text-ink-700 dark:text-ink-200">
              Yeni şifre (tekrar)
              <PasswordInput
                value={confirm}
                onChange={setConfirm}
                autoComplete="new-password"
                required
              />
            </label>

            {fieldError !== null && <p className="text-sm text-red-600">{fieldError}</p>}
            {error !== null && (
              <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{error}</p>
            )}

            <button type="submit" disabled={busy} className={`${ui.btnPrimary} mt-1 w-full`}>
              {busy ? 'Kaydediliyor…' : 'Şifreyi güncelle'}
            </button>
          </form>
        )}
      </div>

      <p className="text-center text-sm text-ink-500">
        <Link href="/giris" className="font-bold text-brand-600 hover:underline">
          Girişe dön
        </Link>
      </p>
    </div>
  );
}
