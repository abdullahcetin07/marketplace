'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { SessionApiError } from '@/lib/session-api';
import { ui } from '@/lib/ui';
import { useSession } from '@/components/SessionProvider';

/**
 * Sign in (§2.2).
 *
 * A CLIENT COMPONENT, unlike the listing pages, and for a reason worth stating:
 * this page's whole job is to set a cookie in THIS browser. Rendering it on the
 * server would mean the server holding a customer's password on its way past.
 *
 * IT COMES BACK TO WHERE THEY WERE. A shopper who pressed "sepete ekle" on a
 * product should return to that product, not to the front page — the `next`
 * parameter carries it, and it is validated as a path so an open redirect cannot
 * be smuggled through it.
 *
 * THE ERROR IS ONE MESSAGE FOR BOTH CASES. "Wrong password" and "no such account"
 * are the same sentence here, because distinguishing them tells anyone with a
 * list of email addresses which ones are registered.
 *
 * `next` IS READ AT SUBMIT TIME, NOT VIA `useSearchParams`. That hook opts the
 * subtree out of prerendering, and a login page whose first paint is blank is a
 * worse experience than one that renders instantly — the destination is only
 * needed once, after a successful sign-in, by which point the browser is
 * certainly there to be asked.
 */
export default function LoginPage() {
  const { signIn } = useSession();
  const router = useRouter();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function onSubmit(event: React.FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      await signIn(email, password, remember);
      router.push(safeNext(new URLSearchParams(window.location.search).get('next')));
    } catch (caught) {
      setError(
        caught instanceof SessionApiError ? caught.message : 'Giriş yapılamadı. Tekrar deneyin.',
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
        <h1 className="mt-4 text-2xl font-extrabold tracking-tight">Giriş yap</h1>
        <p className="mt-1 text-sm text-ink-500">Hesabınıza girip alışverişe kaldığınız yerden devam edin.</p>
      </div>

      <div className={`${ui.card} p-6 shadow-[0_24px_60px_-32px_rgba(20,25,35,.3)] sm:p-8`}>
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

          <label className="flex flex-col gap-1.5 text-sm font-semibold text-ink-700 dark:text-ink-200">
            Parola
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              className={ui.field}
            />
          </label>

          <label className="flex items-center gap-2 text-sm text-ink-600 dark:text-ink-300">
            <input
              type="checkbox"
              checked={remember}
              onChange={(event) => setRemember(event.target.checked)}
              className="h-4 w-4 accent-brand-500"
            />
            Beni hatırla
          </label>

          {error !== null && (
            <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{error}</p>
          )}

          <button type="submit" disabled={busy} className={`${ui.btnPrimary} mt-1 w-full`}>
            {busy ? 'Giriş yapılıyor…' : 'Giriş yap'}
          </button>
        </form>
      </div>

      <p className="text-center text-sm text-ink-500">
        Hesabınız yok mu?{' '}
        <Link href="/kayit" className="font-bold text-brand-600 hover:underline">
          Kayıt olun
        </Link>
      </p>
    </div>
  );
}

/**
 * Only a path on this site is an acceptable destination.
 *
 * `next=https://evil.example` would otherwise turn the login page into an open
 * redirect — a phishing primitive handed out with the site's own domain on it.
 */
function safeNext(value: string | null): string {
  if (value === null) return '/';

  return value.startsWith('/') && !value.startsWith('//') ? value : '/';
}
