'use client';

import Link from 'next/link';
import { useState } from 'react';
import { requestPasswordReset, SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';

/**
 * "Şifremi unuttum" — request a reset link (§2.2, ADR-025).
 *
 * A CLIENT COMPONENT, like login/register: its whole job is a browser POST.
 *
 * ENUMERATION-SAFE BY DESIGN. The API answers the same way for a registered and
 * an unknown address, so on success this shows ONE neutral message ("if the
 * address is registered, we sent a link") and never reveals who has an account —
 * the page must not undo the protection the backend built.
 */
export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await requestPasswordReset(email);
      setSent(true);
    } catch (caught) {
      // A 429 (throttle) or 422 (malformed email) is the only thing that lands here —
      // never "no such account", which the API refuses to say.
      setError(
        caught instanceof SessionApiError
          ? caught.message
          : 'İstek gönderilemedi. Lütfen tekrar deneyin.',
      );
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
        <h1 className="mt-4 text-2xl font-extrabold tracking-tight">Şifreni sıfırla</h1>
        <p className="mt-1 text-sm text-ink-500">
          Hesabının e-posta adresini gir; şifre sıfırlama bağlantısını gönderelim.
        </p>
      </div>

      <div className={`${ui.card} p-6 shadow-[0_24px_60px_-32px_rgba(20,25,35,.3)] sm:p-8`}>
        {sent ? (
          <div className="flex flex-col gap-4 text-center">
            <div className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-green-50 text-green-600 dark:bg-green-950/40">
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
                <path d="M22 7 12 14 2 7" /><rect x="2" y="4" width="20" height="16" rx="2" />
              </svg>
            </div>
            <p className="text-sm text-ink-600 dark:text-ink-300">
              Bu e-posta adresi kayıtlıysa, <span className="font-semibold text-ink-800 dark:text-ink-100">şifre sıfırlama bağlantısını</span> gönderdik. Gelen kutunu (ve spam klasörünü) kontrol et.
            </p>
            <Link href="/giris" className={`${ui.btnPrimary} w-full`}>
              Girişe dön
            </Link>
          </div>
        ) : (
          <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-4">
            <label className="flex flex-col gap-1.5 text-sm font-semibold text-ink-700 dark:text-ink-200">
              E-posta
              <input
                type="email"
                required
                autoComplete="email"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                className={ui.field}
              />
            </label>

            {error !== null && (
              <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{error}</p>
            )}

            <button type="submit" disabled={busy} className={`${ui.btnPrimary} mt-1 w-full`}>
              {busy ? 'Gönderiliyor…' : 'Sıfırlama bağlantısı gönder'}
            </button>
          </form>
        )}
      </div>

      <p className="text-center text-sm text-ink-500">
        Şifreni hatırladın mı?{' '}
        <Link href="/giris" className="font-bold text-brand-600 hover:underline">
          Giriş yap
        </Link>
      </p>
    </div>
  );
}
