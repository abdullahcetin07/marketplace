'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { AddressForm } from '@/components/AddressForm';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import { ui } from '@/lib/ui';
import * as api from '@/lib/session-api';
import { SessionApiError } from '@/lib/session-api';
import type { Address, Country, Order } from '@/lib/types';

/**
 * Checkout (§2.2) — pick two addresses, review, place.
 *
 * IT IS TWO API CALLS AND THE SCREEN SAYS SO IN ITS BEHAVIOUR (ADR-054/057).
 * `checkout` splits the basket by seller and HOLDS the stock; `place` confirms
 * it. Payment will one day sit between them without either call changing — which
 * is the entire reason the two-step exists before there is a payment step to put
 * in it.
 *
 * THE FAILURE BETWEEN THE TWO IS HANDLED, not hoped away. If `checkout` succeeds
 * and `place` fails, the customer has real orders holding real stock — so the page
 * keeps the group and offers to retry the confirmation rather than sending them
 * back to a basket that is now empty. Losing that id would strand a seller's stock
 * until the expiry sweep.
 *
 * SEPARATE SHIPPING AND BILLING, both chosen explicitly (ADR-056). "Same as
 * shipping" is a checkbox that sends the same uuid twice — inferring it silently
 * is how a home address ends up on a company's invoice.
 *
 * NO PAYMENT UI. The order ends at "ödeme bekliyor" and says so; a fake card form
 * would be a lie about what the platform can do today.
 */
export default function CheckoutPage() {
  const { status, cart, refreshCart } = useSession();

  const [addresses, setAddresses] = useState<Address[]>([]);
  const [countries, setCountries] = useState<Country[]>([]);
  const [loading, setLoading] = useState(true);
  const [adding, setAdding] = useState(false);

  const [shippingId, setShippingId] = useState('');
  const [billingId, setBillingId] = useState('');
  const [sameAsShipping, setSameAsShipping] = useState(true);

  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** Set when `checkout` succeeded — the handle `place` needs, kept across a retry. */
  const [pendingGroup, setPendingGroup] = useState<string | null>(null);
  const [placed, setPlaced] = useState<Order[] | null>(null);

  useEffect(() => {
    if (status !== 'authenticated') {
      setLoading(false);

      return;
    }

    void (async () => {
      const book = await api.fetchAddresses();
      setAddresses(book);

      // Preselect the customer's own defaults: they told us which address they
      // use, and asking again is a question with no information in it.
      setShippingId(book.find((a) => a.is_default_shipping)?.id ?? book[0]?.id ?? '');
      setBillingId(book.find((a) => a.is_default_billing)?.id ?? book[0]?.id ?? '');
      setLoading(false);
    })();

    void api.fetchCountries().then(setCountries);
  }, [status]);

  if (status === 'loading' || loading) {
    return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    return <SignInPrompt next="/odeme" />;
  }

  if (placed !== null) {
    return <Confirmation orders={placed} />;
  }

  if (cart.items.length === 0 && pendingGroup === null) {
    return (
      <div className={`mx-auto flex max-w-md flex-col items-center gap-4 py-14 text-center ${ui.card} px-6 py-12`}>
        <h1 className="text-2xl font-extrabold tracking-tight">Sepetiniz boş</h1>
        <p className="text-ink-500">Ödeme yapabilmek için önce sepetinize ürün ekleyin.</p>
        <Link href="/urunler" className={`${ui.btnPrimary} mt-1`}>
          Ürünlere göz at
        </Link>
      </div>
    );
  }

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      // Resume rather than re-checkout: a second `checkout` would reserve the
      // stock a second time and leave the first group stranded.
      const groupId =
        pendingGroup ?? (await api.checkout(shippingId, sameAsShipping ? shippingId : billingId)).checkoutGroupId;

      setPendingGroup(groupId);

      const orders = await api.placeCheckoutGroup(groupId);

      setPlaced(orders);
      setPendingGroup(null);
      await refreshCart();
    } catch (caught) {
      setError(
        caught instanceof SessionApiError
          ? caught.message
          : 'Siparişiniz tamamlanamadı. Lütfen tekrar deneyin.',
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <h1 className={ui.h1}>Ödeme</h1>

      {cart.has_unavailable_items && (
        <p className="rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
          Sepetinizde artık satışta olmayan ürünler var. Devam etmeden önce{' '}
          <Link href="/sepet" className="underline">
            sepetinizden
          </Link>{' '}
          çıkarın.
        </p>
      )}

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div className="flex flex-col gap-6">
          <section className={`flex flex-col gap-3 p-5 ${ui.card}`}>
            <h2 className={ui.h2}>Teslimat adresi</h2>

            {addresses.length === 0 && !adding && (
              <p className="text-sm text-ink-500">
                Devam etmek için bir adres eklemeniz gerekiyor.
              </p>
            )}

            <AddressChoices
              addresses={addresses}
              selected={shippingId}
              onSelect={setShippingId}
              name="shipping"
            />

            {adding ? (
              <AddressForm
                countries={countries}
                submitLabel="Adresi kaydet"
                onCancel={() => setAdding(false)}
                onSubmit={async (input) => {
                  const created = await api.createAddress(input);
                  const book = await api.fetchAddresses();
                  setAddresses(book);
                  if (created !== null) {
                    setShippingId(created.id);
                    if (billingId === '') setBillingId(created.id);
                  }
                  setAdding(false);
                }}
              />
            ) : (
              <button
                type="button"
                onClick={() => setAdding(true)}
                className="self-start text-sm font-bold text-brand-600 hover:underline"
              >
                + Yeni adres ekle
              </button>
            )}
          </section>

          <section className={`flex flex-col gap-3 p-5 ${ui.card}`}>
            <h2 className={ui.h2}>Fatura adresi</h2>

            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={sameAsShipping}
                onChange={(event) => setSameAsShipping(event.target.checked)}
              />
              Teslimat adresiyle aynı
            </label>

            {!sameAsShipping && (
              <AddressChoices
                addresses={addresses}
                selected={billingId}
                onSelect={setBillingId}
                name="billing"
              />
            )}
          </section>

          <section className={`flex flex-col gap-2 p-5 ${ui.card}`}>
            <h2 className={ui.h2}>Sipariş özeti</h2>
            <ul className="divide-y divide-ink-100 text-sm dark:divide-ink-800">
              {cart.items.map((line) => (
                <li key={line.id} className="flex justify-between gap-4 py-2.5">
                  <span>
                    {line.title ?? 'Ürün'} <span className="text-ink-500">× {line.quantity}</span>
                  </span>
                  <span className="font-bold">
                    {line.line_total !== null && line.currency !== null
                      ? formatMoney(line.line_total, line.currency)
                      : '—'}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        </div>

        <aside className={`${ui.rail} lg:sticky lg:top-[130px]`}>
          <h2 className={ui.h2}>Toplam</h2>

          <div className="flex justify-between text-sm">
            <span className="text-ink-500">Ürünler</span>
            <span className="font-bold">
              {cart.currency === null ? '—' : formatMoney(cart.items_total, cart.currency)}
            </span>
          </div>

          <div className="flex items-baseline justify-between border-t border-ink-100 pt-3 dark:border-ink-800">
            <span className="font-extrabold">Ödenecek</span>
            <span className="text-xl font-extrabold tracking-tight text-brand-600">
              {cart.currency === null ? '—' : formatMoney(cart.items_total, cart.currency)}
            </span>
          </div>

          {cart.order_count > 1 && (
            <p className="rounded-xl bg-brand-50 px-3 py-2.5 text-xs font-medium text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
              Siparişiniz {cart.order_count} satıcıya bölünecek. Her satıcı kendi siparişini ayrı
              hazırlar ve ayrı kargolar.
            </p>
          )}

          <p className="text-xs text-ink-500">KDV dahildir. Kargo ücreti bu aşamada alınmaz.</p>

          {error !== null && (
            <p className="rounded-xl bg-red-50 px-3.5 py-2.5 text-sm text-red-600 dark:bg-red-950/40">{error}</p>
          )}

          {pendingGroup !== null && error !== null && (
            <p className="text-xs text-ink-500">
              Siparişiniz oluşturuldu ancak onaylanamadı. Tekrar denediğinizde kaldığı yerden devam
              eder.
            </p>
          )}

          <button
            type="button"
            onClick={() => void submit()}
            disabled={busy || shippingId === '' || (!sameAsShipping && billingId === '') || cart.has_unavailable_items}
            className={`${ui.btnPrimary} w-full`}
          >
            {busy ? 'Gönderiliyor…' : pendingGroup !== null ? 'Onayı tekrar dene' : 'Siparişi ver'}
          </button>

          {/* No payment UI (ADR-055): the order stops at awaiting-payment, and
              saying so is more honest than a card form that does nothing. */}
          <span className="text-center text-xs text-ink-500">
            Ödeme adımı yakında. Siparişiniz “ödeme bekliyor” olarak oluşturulur.
          </span>
        </aside>
      </div>
    </div>
  );
}

function AddressChoices({
  addresses,
  selected,
  onSelect,
  name,
}: {
  addresses: Address[];
  selected: string;
  onSelect: (id: string) => void;
  name: string;
}) {
  if (addresses.length === 0) return null;

  return (
    <ul className="flex flex-col gap-2">
      {addresses.map((address) => (
        <li key={address.id}>
          <label className="flex cursor-pointer gap-3 rounded-xl border-2 border-ink-200 p-3.5 text-sm transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50/50 dark:border-ink-700 dark:has-[:checked]:bg-brand-500/10">
            <input
              type="radio"
              name={name}
              value={address.id}
              checked={selected === address.id}
              onChange={() => onSelect(address.id)}
              className="mt-1 accent-brand-500"
            />
            <span>
              <span className="font-bold">{address.label}</span>
              <span className="block text-ink-500">
                {address.recipient_name} — {address.line1}, {address.district ?? ''} {address.city}
              </span>
            </span>
          </label>
        </li>
      ))}
    </ul>
  );
}

/**
 * What a customer sees after placing.
 *
 * IT LISTS EVERY ORDER, because a purchase from three sellers IS three orders
 * (ADR-052) with three numbers they may be asked to quote. Hiding that behind one
 * "thank you" would leave them confused by the first email.
 */
function Confirmation({ orders }: { orders: Order[] }) {
  return (
    <div className="mx-auto flex max-w-xl flex-col items-center gap-5 py-10 text-center">
      <span className="grid h-16 w-16 place-items-center rounded-full bg-green-50 text-green-600 dark:bg-green-950/40">
        <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
          <path d="m5 13 4 4L19 7" />
        </svg>
      </span>

      <h1 className="text-2xl font-extrabold tracking-tight">Siparişiniz alındı</h1>

      <p className="max-w-md text-ink-600 dark:text-ink-300">
        {orders.length > 1
          ? `Siparişiniz ${orders.length} satıcıya bölündü ve ${orders.length} ayrı sipariş oluşturuldu.`
          : 'Siparişiniz oluşturuldu.'}{' '}
        Ödeme adımı yakında devreye girecek.
      </p>

      <ul className="flex w-full flex-col gap-2 text-left">
        {orders.map((order) => (
          <li key={order.id} className={`flex items-center justify-between p-4 ${ui.card}`}>
            <span className="font-mono text-sm">{order.number}</span>
            <span className="font-extrabold tracking-tight">{formatMoney(order.grand_total, order.currency)}</span>
          </li>
        ))}
      </ul>

      <Link href="/hesap/siparislerim" className={`${ui.btnPrimary} mt-1`}>
        Siparişlerime git
      </Link>
    </div>
  );
}
