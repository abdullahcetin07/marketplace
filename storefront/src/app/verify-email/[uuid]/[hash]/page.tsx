'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useEffect, useRef, useState } from 'react';
import { resendVerification, SessionApiError, verifyEmail } from '@/lib/session-api';
import { ui } from '@/lib/ui';

type Status = 'verifying' | 'success' | 'error';

/**
 * "E-posta doğrula" — the landing page for the verification link (§2.2, ADR-025).
 *
 * The email link is `{FRONTEND_URL}/verify-email/{uuid}/{hash}?expires=…&signature=…`
 * (the default `FRONTEND_EMAIL_VERIFY_PATH`, so no backend env change is needed — just
 * confirm it is the default). The signature is the credential; this page forwards it
 * VERBATIM to the API callback, so a tampered or expired link is rejected there (403).
 *
 * IT VERIFIES ON LOAD. The user clicked a link; there is nothing to fill in. The POST
 * is idempotent server-side, so a second click (or React's dev double-effect) is safe —
 * a ref still guards against a duplicate in-flight call.
 */
export default function VerifyEmailPage() {
  const params = useParams<{ uuid: string; hash: string }>();
  const uuid = typeof params.uuid === 'string' ? params.uuid : '';
  const hash = typeof params.hash === 'string' ? params.hash : '';

  const [status, setStatus] = useState<Status>('verifying');
  const ran = useRef(false);

  useEffect(() => {
    if (ran.current) return;
    ran.current = true;

    if (uuid === '' || hash === '') {
      setStatus('error');
      return;
    }

    // The signed query (?expires=…&signature=…) must reach the API unchanged.
    verifyEmail(uuid, hash, window.location.search)
      .then(() => setStatus('success'))
      .catch(() => setStatus('error'));
  }, [uuid, hash]);

  return (
    <div className="mx-auto flex w-full max-w-md flex-col gap-6 py-6 sm:py-10">
      <div className="text-center">
        <span className="text-2xl font-extrabold tracking-tight">
          <span className="text-brand-500">raf</span>tabul
        </span>
        <h1 className="mt-4 text-2xl font-extrabold tracking-tight">E-posta doğrulama</h1>
      </div>

      <div className={`${ui.card} p-6 shadow-[0_24px_60px_-32px_rgba(20,25,35,.3)] sm:p-8`}>
        {status === 'verifying' && (
          <div className="flex flex-col items-center gap-4 py-2 text-center">
            <div className="h-8 w-8 animate-spin rounded-full border-2 border-ink-200 border-t-brand-500" />
            <p className="text-sm text-ink-500">Doğrulanıyor…</p>
          </div>
        )}

        {status === 'success' && (
          <div className="flex flex-col gap-4 text-center">
            <div className="mx-auto grid h-12 w-12 place-items-center rounded-full bg-green-50 text-green-600 dark:bg-green-950/40">
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
                <path d="m5 13 4 4L19 7" />
              </svg>
            </div>
            <p className="text-sm text-ink-600 dark:text-ink-300">
              E-posta adresin doğrulandı. Artık giriş yapıp alışverişe başlayabilirsin.
            </p>
            <Link href="/giris" className={`${ui.btnPrimary} w-full`}>
              Giriş yap
            </Link>
          </div>
        )}

        {status === 'error' && <VerificationFailed />}
      </div>

      <p className="text-center text-sm text-ink-500">
        <Link href="/giris" className="font-bold text-brand-600 hover:underline">
          Girişe dön
        </Link>
      </p>
    </div>
  );
}

/**
 * The link was tampered with or expired. Offer a fresh one — enumeration-safe, so the
 * response is the same neutral message for any address.
 */
function VerificationFailed() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await resendVerification(email);
      setSent(true);
    } catch (caught) {
      setError(
        caught instanceof SessionApiError ? caught.message : 'Gönderilemedi. Lütfen tekrar deneyin.',
      );
    } finally {
      setBusy(false);
    }
  }

  if (sent) {
    return (
      <p className="text-center text-sm text-ink-600 dark:text-ink-300">
        Bu e-posta adresi kayıtlı ve doğrulanmamışsa, <span className="font-semibold text-ink-800 dark:text-ink-100">yeni bir doğrulama bağlantısı</span> gönderdik. Gelen kutunu kontrol et.
      </p>
    );
  }

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-ink-600 dark:text-ink-300">
        Bu doğrulama bağlantısı geçersiz ya da süresi dolmuş. E-posta adresini gir, yeni bir bağlantı gönderelim.
      </p>
      <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-3">
        <input
          type="email"
          required
          autoComplete="email"
          placeholder="E-posta adresin"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
          className={ui.field}
        />
        {error !== null && <p className="text-sm text-red-600">{error}</p>}
        <button type="submit" disabled={busy} className={`${ui.btnPrimary} w-full`}>
          {busy ? 'Gönderiliyor…' : 'Yeni bağlantı gönder'}
        </button>
      </form>
    </div>
  );
}
