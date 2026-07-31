'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { SessionApiError } from '@/lib/session-api';
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
    <div className="mx-auto flex w-full max-w-sm flex-col gap-6 py-8">
      <h1 className="text-2xl font-bold">Giriş yap</h1>

      <form onSubmit={(event) => void onSubmit(event)} className="flex flex-col gap-4">
        <label className="flex flex-col gap-1 text-sm">
          E-posta
          <input
            type="email"
            required
            autoComplete="email"
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </label>

        <label className="flex flex-col gap-1 text-sm">
          Parola
          <input
            type="password"
            required
            autoComplete="current-password"
            value={password}
            onChange={(event) => setPassword(event.target.value)}
            className="rounded-lg border border-ink-300 px-3 py-2 dark:border-ink-700 dark:bg-ink-900"
          />
        </label>

        <label className="flex items-center gap-2 text-sm text-ink-600 dark:text-ink-300">
          <input
            type="checkbox"
            checked={remember}
            onChange={(event) => setRemember(event.target.checked)}
          />
          Beni hatırla
        </label>

        {error !== null && <p className="text-sm text-red-600">{error}</p>}

        <button
          type="submit"
          disabled={busy}
          className="rounded-lg bg-brand-500 px-4 py-2.5 font-semibold text-white transition hover:bg-brand-600 disabled:opacity-60"
        >
          {busy ? 'Giriş yapılıyor…' : 'Giriş yap'}
        </button>
      </form>

      <p className="text-sm text-ink-500">
        Hesabınız yok mu?{' '}
        <Link href="/kayit" className="text-brand-600 hover:underline">
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
