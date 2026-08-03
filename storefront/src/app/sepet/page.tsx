'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import { ui } from '@/lib/ui';
import type { CartLine } from '@/lib/types';

/**
 * The basket (§2.2).
 *
 * IT SHOWS THE SPLIT BEFORE CHECKOUT, not after. A basket from three sellers
 * becomes three orders (ADR-052), and a shopper should learn that while they can
 * still change their mind — not on a confirmation screen listing three order
 * numbers they did not expect.
 *
 * EVERY NUMBER COMES FROM THE API. Nothing here multiplies a price by a quantity:
 * the server prices the basket live from the offers (Order §2.1), and a total
 * computed in the browser is how a cart and a checkout end up disagreeing about
 * what something costs.
 *
 * AN UNAVAILABLE LINE STAYS VISIBLE, greyed and priceless. Dropping it silently
 * would leave a customer wondering where the thing they chose went; the checkout
 * is where it becomes a refusal they can act on.
 *
 * CHECKOUT IS ONE LINK AWAY, and it is disabled when the basket holds something
 * that can no longer be sold: the API would refuse the whole checkout anyway
 * (all-or-nothing, Order §3.1), and letting a shopper press it only to be told no
 * is a worse way to learn the same thing.
 */
export default function CartPage() {
  const { status, cart, updateItem, removeItem } = useSession();

  if (status === 'loading') {
    return <p className="py-12 text-center text-ink-500">Sepetiniz yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    // No guest basket on this platform (ADR-056) — said plainly rather than
    // discovered at checkout.
    return (
      <SignInPrompt
        next="/sepet"
        title="Sepetinizi görmek için giriş yapın"
        description="Alışverişe devam edebilmek için bir hesabınızın olması gerekiyor."
      />
    );
  }

  if (cart.items.length === 0) {
    return (
      <div className={`mx-auto flex max-w-md flex-col items-center gap-4 py-14 text-center ${ui.card} px-6 py-12`}>
        <span className="grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/15">
          <CartIcon />
        </span>
        <h1 className="text-2xl font-extrabold tracking-tight">Sepetiniz boş</h1>
        <p className="text-ink-500">Beğendiğiniz ürünleri sepete ekleyerek başlayın.</p>
        <Link href="/urunler" className={`${ui.btnPrimary} mt-1`}>
          Ürünlere göz at
        </Link>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <h1 className={ui.h1}>
        Sepetim <span className="text-base font-bold text-ink-400">({cart.items.length} ürün)</span>
      </h1>

      {cart.has_unavailable_items && (
        <p className="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
          Sepetinizdeki bazı ürünler artık satışta değil. Devam etmeden önce çıkarmanız gerekiyor.
        </p>
      )}

      <div className="grid gap-6 lg:grid-cols-[1fr_21rem]">
        <ul className={`divide-y divide-ink-100 dark:divide-ink-800 ${ui.card}`}>
          {cart.items.map((line) => (
            <CartRow
              key={line.id}
              line={line}
              onQuantity={(quantity) => void updateItem(line.id, quantity)}
              onRemove={() => void removeItem(line.id)}
            />
          ))}
        </ul>

        <aside className={`${ui.rail} lg:sticky lg:top-[130px]`}>
          <h2 className={ui.h2}>Özet</h2>

          <div className="flex justify-between text-sm">
            <span className="text-ink-500">Ürünler ({cart.items.length})</span>
            <span className="font-bold">
              {cart.currency === null ? '—' : formatMoney(cart.items_total, cart.currency)}
            </span>
          </div>

          <div className="flex items-baseline justify-between border-t border-ink-100 pt-3 dark:border-ink-800">
            <span className="font-extrabold">Toplam</span>
            <span className="text-xl font-extrabold tracking-tight text-brand-600">
              {cart.currency === null ? '—' : formatMoney(cart.items_total, cart.currency)}
            </span>
          </div>

          {/* THE SPLIT, SHOWN EARLY (ADR-052). "This will arrive as 2 separate
              deliveries" is something to learn now, not at confirmation. */}
          {cart.order_count > 1 && (
            <p className="rounded-xl bg-brand-50 px-3 py-2.5 text-xs font-medium text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
              Siparişiniz {cart.order_count} satıcıya bölünecek ve {cart.order_count} ayrı sipariş
              olarak oluşturulacak.
            </p>
          )}

          <p className="text-xs text-ink-500">
            KDV dahildir. Kargo ücreti bu aşamada hesaplanmaz.
          </p>

          {cart.has_unavailable_items ? (
            <button type="button" disabled className={`${ui.btnPrimary} w-full opacity-60`}>
              Siparişi tamamla
            </button>
          ) : (
            <Link href="/odeme" className={`${ui.btnPrimary} w-full`}>
              Siparişi tamamla
            </Link>
          )}
          <span className="text-center text-xs text-ink-500">
            Ödeme adımı yakında; siparişiniz “ödeme bekliyor” olarak oluşturulur.
          </span>
        </aside>
      </div>
    </div>
  );
}

function CartIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={1.7} strokeLinecap="round" strokeLinejoin="round">
      <circle cx="9" cy="20" r="1.4" />
      <circle cx="18" cy="20" r="1.4" />
      <path d="M2 3h2.5l2 12.5A1.5 1.5 0 0 0 8 17h9a1.5 1.5 0 0 0 1.5-1.2L20.5 7H6" />
    </svg>
  );
}

function CartRow({
  line,
  onQuantity,
  onRemove,
}: {
  line: CartLine;
  onQuantity: (quantity: number) => void;
  onRemove: () => void;
}) {
  const [busy, setBusy] = useState(false);

  function change(quantity: number) {
    if (quantity < 1) return;

    setBusy(true);
    onQuantity(quantity);
    // The provider replaces the whole basket when the call returns, so this row
    // re-renders from the server's answer rather than from a guess.
    setTimeout(() => setBusy(false), 300);
  }

  return (
    <li className={`flex flex-wrap items-center gap-4 p-4 sm:flex-nowrap ${line.available ? '' : 'opacity-60'}`}>
      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <Link href={`/urun/${line.product_id}`} className="font-bold leading-snug hover:text-brand-600">
          {line.title ?? 'Ürün'}
        </Link>

        {line.available ? (
          <span className="text-sm text-ink-500">
            {line.unit_price !== null && line.currency !== null
              ? `${formatMoney(line.unit_price, line.currency)} / adet`
              : null}
          </span>
        ) : (
          <span className="text-sm font-semibold text-amber-700 dark:text-amber-400">Artık satışta değil</span>
        )}
      </div>

      <div className="flex items-center gap-1 rounded-xl border-2 border-ink-200 p-0.5 dark:border-ink-700">
        <button
          type="button"
          onClick={() => change(line.quantity - 1)}
          disabled={busy || line.quantity <= 1}
          aria-label="Adedi azalt"
          className="grid h-8 w-8 place-items-center rounded-lg text-lg leading-none text-ink-600 transition hover:bg-ink-50 disabled:opacity-40 dark:text-ink-300 dark:hover:bg-ink-800"
        >
          −
        </button>
        <span className="w-8 text-center text-sm font-bold tabular-nums">{line.quantity}</span>
        <button
          type="button"
          onClick={() => change(line.quantity + 1)}
          disabled={busy}
          aria-label="Adedi artır"
          className="grid h-8 w-8 place-items-center rounded-lg text-lg leading-none text-ink-600 transition hover:bg-ink-50 disabled:opacity-40 dark:text-ink-300 dark:hover:bg-ink-800"
        >
          +
        </button>
      </div>

      <div className="w-24 text-right font-extrabold tracking-tight">
        {line.line_total !== null && line.currency !== null
          ? formatMoney(line.line_total, line.currency)
          : '—'}
      </div>

      <button
        type="button"
        onClick={onRemove}
        aria-label="Sepetten çıkar"
        className="grid h-8 w-8 place-items-center rounded-lg text-ink-400 transition hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40"
        title="Sepetten çıkar"
      >
        <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinecap="round" strokeLinejoin="round">
          <path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14" />
        </svg>
      </button>
    </li>
  );
}
