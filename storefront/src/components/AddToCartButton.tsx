'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { SessionApiError } from '@/lib/session-api';
import { useSession } from './SessionProvider';

/**
 * "Sepete ekle" — the first write a shopper makes.
 *
 * IT SENDS AN ANONYMOUS VISITOR TO SIGN IN, rather than hiding itself. There is
 * no guest basket on this platform (ADR-056), so the honest flow is: show the
 * button, and when they press it explain why they need an account — carrying
 * `next` so they come back to the product they were looking at rather than to the
 * front page.
 *
 * IT TAKES AN OFFER, NOT A PRODUCT. A shopper does not buy a catalogue entry;
 * they buy one seller's listing of it (ADR-042), and the buy box already chose
 * which. Passing the product would make this component re-decide the winner —
 * and two places deciding is how a listing price and a basket price come apart.
 *
 * THE ERROR IS SHOWN, NOT SWALLOWED. "This is no longer available" is an ordinary
 * outcome of a shared catalogue — somebody else took the last one — and a button
 * that silently did nothing would leave a customer pressing it again.
 */
export function AddToCartButton({ offerId }: { offerId: string }) {
  const { status, addItem } = useSession();
  const router = useRouter();

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [added, setAdded] = useState(false);

  async function onClick() {
    if (status === 'anonymous') {
      router.push(`/giris?next=${encodeURIComponent(window.location.pathname)}`);

      return;
    }

    setBusy(true);
    setError(null);

    try {
      await addItem(offerId);
      setAdded(true);
    } catch (caught) {
      setError(
        caught instanceof SessionApiError
          ? caught.message
          : 'Ürün sepete eklenemedi. Lütfen tekrar deneyin.',
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        onClick={() => void onClick()}
        disabled={busy || status === 'loading'}
        className="rounded-lg bg-brand-500 px-6 py-3 font-semibold text-white transition hover:bg-brand-600 disabled:opacity-60"
      >
        {busy ? 'Ekleniyor…' : 'Sepete ekle'}
      </button>

      {added && error === null && (
        <span className="text-sm text-green-700 dark:text-green-400">
          Sepete eklendi.{' '}
          <Link href="/sepet" className="underline">
            Sepete git
          </Link>
        </span>
      )}

      {error !== null && <span className="text-sm text-red-600">{error}</span>}
    </div>
  );
}
