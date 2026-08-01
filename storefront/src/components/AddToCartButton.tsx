'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { SessionApiError } from '@/lib/session-api';
import { useSession } from './SessionProvider';

type Variant = 'primary' | 'secondary' | 'icon';

/**
 * "Sepete ekle" — the first write a shopper makes.
 *
 * IT SENDS AN ANONYMOUS VISITOR TO SIGN IN, rather than hiding itself. There is
 * no guest basket on this platform (ADR-056), so the honest flow is: show the
 * button, and when they press it explain why they need an account — carrying
 * `next` so they come back to the product they were looking at.
 *
 * IT TAKES AN OFFER, NOT A PRODUCT. A shopper buys one seller's listing (ADR-042),
 * and the buy box (or the "other sellers" row) already chose which offer. Passing
 * the offer id keeps the price they pressed on and the price in the basket the same.
 *
 * VARIANTS are cosmetic only, so the same guarded write backs the big "Hemen al",
 * the secondary "Sepete ekle", and the compact "+" next to a rival seller.
 * `redirectOnAdd` turns a plain add into "add and take me there" (the basket for
 * "Hemen al").
 */
export function AddToCartButton({
  offerId,
  variant = 'primary',
  label = 'Sepete ekle',
  redirectOnAdd,
}: {
  offerId: string;
  variant?: Variant;
  label?: string;
  redirectOnAdd?: string;
}) {
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
      if (redirectOnAdd) router.push(redirectOnAdd);
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

  const plus = (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
      <path d="M12 5v14M5 12h14" />
    </svg>
  );
  const check = (
    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
      <path d="m5 13 4 4L19 7" />
    </svg>
  );

  if (variant === 'icon') {
    return (
      <button
        type="button"
        onClick={() => void onClick()}
        disabled={busy || status === 'loading'}
        aria-label={`${label} — bu satıcıdan`}
        title={error ?? (added ? 'Sepete eklendi' : label)}
        className={`grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[10px] transition disabled:opacity-60 ${
          added ? 'bg-green-500 text-white' : 'bg-brand-50 text-brand-700 hover:bg-brand-500 hover:text-white dark:bg-brand-500/15'
        }`}
      >
        {busy ? '…' : added ? check : plus}
      </button>
    );
  }

  const base = 'flex w-full items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-base font-extrabold transition disabled:opacity-60';
  const skin =
    variant === 'secondary'
      ? 'bg-brand-50 text-brand-700 hover:bg-ink-900 hover:text-white dark:bg-brand-500/15'
      : 'bg-brand-500 text-white hover:bg-brand-600';

  return (
    <div className="flex flex-col gap-2">
      <button
        type="button"
        onClick={() => void onClick()}
        disabled={busy || status === 'loading'}
        className={`${base} ${skin}`}
      >
        {busy ? 'Ekleniyor…' : (<>{variant === 'primary' ? null : plus}{label}</>)}
      </button>

      {added && error === null && !redirectOnAdd && (
        <span className="text-sm text-green-700 dark:text-green-400">
          Sepete eklendi.{' '}
          <Link href="/sepet" className="underline">Sepete git</Link>
        </span>
      )}

      {error !== null && <span className="text-sm text-red-600">{error}</span>}
    </div>
  );
}
