'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useSession } from '@/components/SessionProvider';
import { analyticsAmount, pushDataLayer } from '@/lib/analytics';
import { fetchPayment } from '@/lib/session-api';
import { formatMoney } from '@/lib/money';
import { ui } from '@/lib/ui';
import type { PaymentView } from '@/lib/types';

const STORAGE_KEY = 'raftabul:payment';

/**
 * The payment result (ADR-060) — shown at `/odeme/sonuc` and `/odeme/hata`.
 *
 * THE URL IS A HINT, THE STATUS IS THE TRUTH. PayTR redirects the browser to one of
 * two pages, but the payment is only really settled by the server-to-server callback,
 * which can land a beat after the redirect. So both pages render THIS, and it reads
 * the status back from the API — polling briefly while it is still `pending` — rather
 * than believing the page it was sent to.
 *
 * THE PAYMENT ID CAME FROM localStorage, written when the iframe opened. PayTR does
 * not carry our uuid back in the redirect, and the query string could be forged
 * anyway; the id the shopper's own browser stashed is both private and trustworthy.
 */
export function PaymentResult() {
  const { refreshCart } = useSession();
  const [payment, setPayment] = useState<PaymentView | null>(null);
  const [phase, setPhase] = useState<'checking' | 'done' | 'missing'>('checking');

  // GA4 purchase — fire ONCE, and only on a confirmed-paid payment (never on
  // pending/failed). `transaction_id` is the payment uuid; `value` is the paid amount,
  // a decimal string parsed to a number for analytics only (analytics.ts). Items are
  // not on hand here (the basket already became orders), so a transaction-level event
  // is what we send — acceptable per GA4.
  const purchaseFired = useRef(false);
  useEffect(() => {
    if (purchaseFired.current || payment === null || payment.status !== 'paid') return;
    purchaseFired.current = true;

    pushDataLayer({
      event: 'purchase',
      ecommerce: {
        transaction_id: payment.id,
        currency: payment.currency,
        value: analyticsAmount(payment.amount),
      },
    });
  }, [payment]);

  const clear = useCallback(() => {
    try {
      window.localStorage.removeItem(STORAGE_KEY);
    } catch {
      // ignore a storage that refuses us — nothing depends on the removal
    }
  }, []);

  useEffect(() => {
    const uuid = (() => {
      try {
        return window.localStorage.getItem(STORAGE_KEY);
      } catch {
        return null;
      }
    })();

    if (uuid === null || uuid === '') {
      setPhase('missing');

      return;
    }

    let live = true;
    let tries = 0;

    async function poll() {
      tries += 1;

      const result = await fetchPayment(uuid as string).catch(() => null);
      if (!live) return;

      if (result !== null) {
        setPayment(result);

        // Resolved — stop, tidy up, and let the cart re-read (it is empty now that
        // the basket became orders).
        if (result.status !== 'pending') {
          setPhase('done');
          clear();
          void refreshCart();

          return;
        }
      }

      // Still pending (or a transient miss) — the callback may not have landed yet.
      if (tries < 8) {
        window.setTimeout(poll, 1500);
      } else {
        setPhase('done'); // give up waiting; show the "işleniyor" state below
      }
    }

    void poll();

    return () => {
      live = false;
    };
  }, [clear, refreshCart]);

  if (phase === 'checking') {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center gap-4 py-16 text-center">
        <span className="h-10 w-10 animate-spin rounded-full border-4 border-ink-200 border-t-brand-500" />
        <p className="text-ink-500">Ödemeniz kontrol ediliyor…</p>
      </div>
    );
  }

  const paid = payment?.status === 'paid';
  const failed = payment?.status === 'failed' || payment?.status === 'expired';

  return (
    <div className={`mx-auto flex max-w-md flex-col items-center gap-5 py-12 text-center ${ui.card} px-6 py-12`}>
      {paid ? (
        <>
          <ResultIcon tone="ok" />
          <div>
            <h1 className="text-2xl font-extrabold tracking-tight">Ödemeniz alındı</h1>
            <p className="mt-1 text-ink-500">
              {payment && `${formatMoney(payment.amount, payment.currency)} tahsil edildi. `}
              Siparişiniz hazırlanıyor.
            </p>
          </div>
          <Link href="/hesap/siparislerim" className={`${ui.btnPrimary} mt-1`}>
            Siparişlerime git
          </Link>
        </>
      ) : failed ? (
        <>
          <ResultIcon tone="fail" />
          <div>
            <h1 className="text-2xl font-extrabold tracking-tight">Ödeme tamamlanamadı</h1>
            <p className="mt-1 text-ink-500">
              Ödemeniz alınamadı ya da iptal edildi. Sepetiniz duruyor — tekrar deneyebilirsiniz.
            </p>
          </div>
          <Link href="/sepet" className={`${ui.btnPrimary} mt-1`}>
            Sepete dön
          </Link>
        </>
      ) : (
        <>
          <ResultIcon tone="pending" />
          <div>
            <h1 className="text-2xl font-extrabold tracking-tight">Ödemeniz işleniyor</h1>
            <p className="mt-1 text-ink-500">
              Ödemeniz onaylanıyor. Sonucu birazdan siparişlerinizden takip edebilirsiniz.
            </p>
          </div>
          <Link href="/hesap/siparislerim" className={`${ui.btnPrimary} mt-1`}>
            Siparişlerime git
          </Link>
        </>
      )}
    </div>
  );
}

function ResultIcon({ tone }: { tone: 'ok' | 'fail' | 'pending' }) {
  const skin =
    tone === 'ok'
      ? 'bg-green-50 text-green-600 dark:bg-green-950/40'
      : tone === 'fail'
        ? 'bg-red-50 text-red-600 dark:bg-red-950/40'
        : 'bg-brand-50 text-brand-600 dark:bg-brand-500/15';

  const path =
    tone === 'ok' ? 'm5 13 4 4L19 7' : tone === 'fail' ? 'M6 6l12 12M18 6 6 18' : 'M12 7v5l3 2';

  return (
    <span className={`grid h-16 w-16 place-items-center rounded-full ${skin}`}>
      <svg viewBox="0 0 24 24" className="h-8 w-8" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        {tone === 'pending' && <circle cx="12" cy="12" r="9" />}
        <path d={path} />
      </svg>
    </span>
  );
}
