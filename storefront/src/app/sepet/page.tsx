'use client';

import Link from 'next/link';
import { useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { formatMoney } from '@/lib/money';
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
 * CHECKOUT IS NOT WIRED IN THIS SLICE. The button says so plainly rather than
 * being hidden — a shopper should be able to see where the flow goes next.
 */
export default function CartPage() {
  const { status, cart, updateItem, removeItem } = useSession();

  if (status === 'loading') {
    return <p className="py-12 text-center text-ink-500">Sepetiniz yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    return (
      <div className="flex flex-col items-center gap-4 py-16 text-center">
        <h1 className="text-2xl font-bold">Sepetinizi görmek için giriş yapın</h1>
        {/* No guest basket on this platform (ADR-056) — said plainly rather than
            discovered at checkout. */}
        <p className="text-ink-500">
          Alışverişe devam edebilmek için bir hesabınızın olması gerekiyor.
        </p>
        <Link
          href="/giris?next=%2Fsepet"
          className="rounded-lg bg-brand-500 px-5 py-2.5 font-semibold text-white transition hover:bg-brand-600"
        >
          Giriş yap
        </Link>
      </div>
    );
  }

  if (cart.items.length === 0) {
    return (
      <div className="flex flex-col items-center gap-4 py-16 text-center">
        <h1 className="text-2xl font-bold">Sepetiniz boş</h1>
        <Link
          href="/urunler"
          className="rounded-lg bg-brand-500 px-5 py-2.5 font-semibold text-white transition hover:bg-brand-600"
        >
          Ürünlere göz at
        </Link>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <h1 className="text-2xl font-bold">Sepetim</h1>

      {cart.has_unavailable_items && (
        <p className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
          Sepetinizdeki bazı ürünler artık satışta değil. Devam etmeden önce çıkarmanız gerekiyor.
        </p>
      )}

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <ul className="divide-y divide-ink-200 rounded-xl border border-ink-200 dark:divide-ink-800 dark:border-ink-800">
          {cart.items.map((line) => (
            <CartRow
              key={line.id}
              line={line}
              onQuantity={(quantity) => void updateItem(line.id, quantity)}
              onRemove={() => void removeItem(line.id)}
            />
          ))}
        </ul>

        <aside className="flex h-fit flex-col gap-4 rounded-xl border border-ink-200 p-5 dark:border-ink-800">
          <h2 className="font-semibold">Özet</h2>

          <div className="flex justify-between text-sm">
            <span className="text-ink-500">Ürünler</span>
            <span className="font-semibold">
              {cart.currency === null ? '—' : formatMoney(cart.items_total, cart.currency)}
            </span>
          </div>

          {/* THE SPLIT, SHOWN EARLY (ADR-052). "This will arrive as 2 separate
              deliveries" is something to learn now, not at confirmation. */}
          {cart.order_count > 1 && (
            <p className="rounded-lg bg-ink-50 px-3 py-2 text-xs text-ink-600 dark:bg-ink-900 dark:text-ink-300">
              Siparişiniz {cart.order_count} satıcıya bölünecek ve {cart.order_count} ayrı sipariş
              olarak oluşturulacak.
            </p>
          )}

          <p className="text-xs text-ink-500">
            KDV dahildir. Kargo ücreti bu aşamada hesaplanmaz.
          </p>

          <button
            type="button"
            disabled
            title="Ödeme adımı yakında"
            className="rounded-lg bg-brand-500 px-4 py-2.5 font-semibold text-white opacity-60"
          >
            Siparişi tamamla
          </button>
          <span className="text-center text-xs text-ink-500">Ödeme adımı yakında</span>
        </aside>
      </div>
    </div>
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
    <li className={`flex items-center gap-4 p-4 ${line.available ? '' : 'opacity-60'}`}>
      <div className="flex flex-1 flex-col gap-1">
        <Link href={`/urun/${line.product_id}`} className="font-medium hover:text-brand-600">
          {line.title ?? 'Ürün'}
        </Link>

        {line.available ? (
          <span className="text-sm text-ink-500">
            {line.unit_price !== null && line.currency !== null
              ? `${formatMoney(line.unit_price, line.currency)} / adet`
              : null}
          </span>
        ) : (
          <span className="text-sm text-amber-700 dark:text-amber-400">Artık satışta değil</span>
        )}
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => change(line.quantity - 1)}
          disabled={busy || line.quantity <= 1}
          aria-label="Adedi azalt"
          className="h-8 w-8 rounded-lg border border-ink-300 disabled:opacity-40 dark:border-ink-700"
        >
          −
        </button>
        <span className="w-8 text-center text-sm">{line.quantity}</span>
        <button
          type="button"
          onClick={() => change(line.quantity + 1)}
          disabled={busy}
          aria-label="Adedi artır"
          className="h-8 w-8 rounded-lg border border-ink-300 disabled:opacity-40 dark:border-ink-700"
        >
          +
        </button>
      </div>

      <div className="w-24 text-right font-semibold">
        {line.line_total !== null && line.currency !== null
          ? formatMoney(line.line_total, line.currency)
          : '—'}
      </div>

      <button
        type="button"
        onClick={onRemove}
        aria-label="Sepetten çıkar"
        className="text-sm text-ink-400 hover:text-red-600"
      >
        Kaldır
      </button>
    </li>
  );
}
