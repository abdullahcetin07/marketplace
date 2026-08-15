'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { AddressForm } from '@/components/AddressForm';
import { PayTrCheckout } from '@/components/PayTrCheckout';
import { useSession } from '@/components/SessionProvider';
import { SignInPrompt } from '@/components/SignInPrompt';
import { formatMoney } from '@/lib/money';
import { ui } from '@/lib/ui';
import * as api from '@/lib/session-api';
import { SessionApiError } from '@/lib/session-api';
import type { Address, Country, LoyaltyBalance, LoyaltyQuote } from '@/lib/types';

const PAYMENT_KEY = 'raftabul:payment';

/**
 * Checkout (§2.2, ADR-054/057/060) — pick two addresses, place, pay.
 *
 * THREE CALLS, EACH RESUMABLE. `checkout` splits the basket by seller and RESERVES
 * the stock; `place` holds it as awaiting-payment orders; `pay` opens PayTR for the
 * whole group. A failure at any step keeps the checkout group so a retry continues
 * from where it stopped rather than re-reserving stock or stranding a placed order.
 *
 * THE CARD NEVER TOUCHES THIS PAGE. `pay` returns a PayTR iframe token; the card and
 * its 3-D Secure step happen inside PayTR's frame, which then redirects the browser
 * to `/odeme/sonuc` or `/odeme/hata`. The payment id is stashed in localStorage so
 * the result page can read the real status back — the redirect is a hint, the
 * server-to-server callback is the truth (ADR-060).
 *
 * SEPARATE SHIPPING AND BILLING, both chosen explicitly (ADR-056). "Same as
 * shipping" is a checkbox that sends the same uuid twice — inferring it silently
 * is how a home address ends up on a company's invoice.
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

  /** Points redemption (ADR-084). Hidden until the customer has a balance AND the
   *  Phase-2 quote endpoint answers — so it degrades to nothing before P2 ships. */
  const [balance, setBalance] = useState<LoyaltyBalance | null>(null);
  const [quote, setQuote] = useState<LoyaltyQuote | null>(null);
  const [usePoints, setUsePoints] = useState(false);
  const [pointsBusy, setPointsBusy] = useState(false);
  const [pointsUnavailable, setPointsUnavailable] = useState(false);
  /** Set when `checkout` succeeded — the handle `place`/`pay` need, kept across a retry. */
  const [pendingGroup, setPendingGroup] = useState<string | null>(null);
  /** Set once PayTR hands us a token — the page swaps to the payment iframe. */
  const [iframeToken, setIframeToken] = useState<string | null>(null);

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
    void api.fetchLoyaltyBalance().then(setBalance);
  }, [status]);

  async function togglePoints(next: boolean) {
    setUsePoints(next);
    if (!next) {
      setQuote(null);

      return;
    }

    // Ask the server what applying the balance would do (pure preview, no hold). A
    // null answer means Phase 2 isn't live yet — hide the control rather than promise
    // a discount we can't deliver.
    setPointsBusy(true);
    const preview = await api.quoteLoyaltyRedemption(true);
    setPointsBusy(false);

    if (preview === null || preview.points_applied <= 0) {
      setUsePoints(false);
      setPointsUnavailable(preview === null);

      return;
    }

    setQuote(preview);
  }

  if (status === 'loading' || loading) {
    return <p className="py-12 text-center text-ink-500">Yükleniyor…</p>;
  }

  if (status === 'anonymous') {
    return <SignInPrompt next="/odeme" />;
  }

  if (iframeToken !== null) {
    return (
      <div className="mx-auto flex w-full max-w-2xl flex-col gap-4">
        <div>
          <h1 className={ui.h1}>Ödeme</h1>
          <p className="mt-1 flex items-center gap-2 text-sm text-ink-500">
            <LockIcon />
            Kart bilgileriniz PayTR'nin güvenli ekranında girilir, bize iletilmez.
          </p>
        </div>
        <div className={`overflow-hidden p-1.5 ${ui.card}`}>
          <PayTrCheckout token={iframeToken} />
        </div>
      </div>
    );
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

      // Place the orders (awaiting_payment, reservation held) then open PayTR for
      // the whole group. `place` is safe to repeat, and `pay` is idempotent — one
      // live payment per group — so a retry after either resumes cleanly.
      await api.placeCheckoutGroup(groupId);

      const payment = await api.initiatePayment(groupId, usePoints ? quote?.points_applied : undefined);
      if (payment === null) {
        throw new SessionApiError('Ödeme başlatılamadı. Lütfen tekrar deneyin.', 502);
      }

      // Stash the id so /odeme/sonuc can read the real status back after PayTR
      // redirects the browser there.
      try {
        window.localStorage.setItem(PAYMENT_KEY, payment.paymentId);
      } catch {
        // a storage that refuses us only costs the result page its confirmation
      }

      setIframeToken(payment.iframeToken);
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

          {/* Points redemption — only when the customer has a balance. The discount and
              the reduced total both come from the server's quote (never computed here). */}
          {balance !== null && balance.points > 0 && (
            <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-3 dark:border-brand-500/30 dark:bg-brand-500/10">
              <label className="flex cursor-pointer items-start gap-2.5 text-sm">
                <input
                  type="checkbox"
                  checked={usePoints}
                  disabled={pointsBusy}
                  onChange={(event) => void togglePoints(event.target.checked)}
                  className="mt-0.5 accent-brand-500"
                />
                <span>
                  <span className="font-bold text-ink-700 dark:text-ink-200">Puanlarımı kullan</span>
                  <span className="block text-xs text-ink-500">
                    {balance.points.toLocaleString('tr-TR')} puan · ≈{' '}
                    {formatMoney(balance.value, balance.currency)}
                  </span>
                </span>
              </label>
              {pointsBusy && <span className="mt-1 block text-xs text-ink-400">Hesaplanıyor…</span>}
              {pointsUnavailable && (
                <span className="mt-1 block text-xs text-ink-400">Puan kullanımı yakında.</span>
              )}
            </div>
          )}

          {usePoints && quote !== null && (
            <div className="flex justify-between text-sm text-green-600">
              <span className="font-bold">Puan indirimi</span>
              <span className="font-bold">− {formatMoney(quote.discount, quote.currency)}</span>
            </div>
          )}

          <div className="flex items-baseline justify-between border-t border-ink-100 pt-3 dark:border-ink-800">
            <span className="font-extrabold">Ödenecek</span>
            <span className="text-xl font-extrabold tracking-tight text-brand-600">
              {usePoints && quote !== null
                ? formatMoney(quote.payable, quote.currency)
                : cart.currency === null
                  ? '—'
                  : formatMoney(cart.items_total, cart.currency)}
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
            {busy ? 'Hazırlanıyor…' : pendingGroup !== null ? 'Ödemeyi tekrar dene' : 'Ödemeye geç'}
          </button>

          <span className="flex items-center justify-center gap-1.5 text-center text-xs text-ink-500">
            <LockIcon />
            Güvenli PayTR ekranına yönlendirilirsiniz. Kart bilgileriniz bize iletilmez.
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

function LockIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
      <rect x="4" y="10" width="16" height="11" rx="2" />
      <path d="M8 10V7a4 4 0 0 1 8 0v3" />
    </svg>
  );
}
