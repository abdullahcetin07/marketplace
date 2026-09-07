'use client';

import { useEffect } from 'react';
import { ui } from '@/lib/ui';

/** PayTR's hosted payment page for an iframe token. */
export function payTrPaymentUrl(token: string): string {
  return `https://www.paytr.com/odeme/guvenli/${token}`;
}

/**
 * The PayTR payment step (ADR-060) — a REDIRECT to PayTR's own page, not an iframe.
 *
 * THE CARD STILL NEVER TOUCHES THIS APP. Card number, CVV and the 3-D Secure step
 * happen on `paytr.com`; this app only ever holds the token that opens it, and PayTR
 * sends the browser back to `/odeme/sonuc` or `/odeme/hata` when the payment
 * resolves. That guarantee is what ADR-060 bought, and it is unchanged.
 *
 * **WHY THE IFRAME HAD TO GO (2026-09-07).** It shipped embedded, and on the live
 * site the payment page opened but the 3-D Secure step never did: PayTR's own
 * callbacks recorded ten "customer left the payment page" and one "customer did not
 * complete 3-D Secure", and the owner reproduced it in Chrome 152 — while the SAME
 * token, opened in a normal tab, went through to the bank's 3DS screen. Inside our
 * page PayTR is a third-party context, and a browser that restricts third-party
 * storage breaks the card submission with nothing to show for it.
 *
 * **AND THE SESSION IS SINGLE-USE**, which an embedded frame makes easy to spend by
 * accident: `GET /odeme/api/oos/payment/get/token/{token}` answers `200` once and
 * `410 Gone` on every later call, so any remount, refresh or back-button lands the
 * shopper on a dead page. A top-level navigation loads it exactly once.
 *
 * The panel below is not decoration: if a browser or an extension blocks the
 * scripted navigation, the shopper still has a link they can press themselves.
 */
export function PayTrCheckout({ token }: { token: string }) {
  const url = payTrPaymentUrl(token);

  useEffect(() => {
    window.location.assign(url);
  }, [url]);

  return (
    <div className="flex flex-col items-center gap-4 px-6 py-10 text-center">
      <p className="text-sm text-ink-500">
        PayTR&apos;nin güvenli ödeme sayfasına yönlendiriliyorsunuz…
      </p>
      <a href={url} className={ui.btnPrimary}>
        Ödeme sayfasını aç
      </a>
      <p className="text-xs text-ink-400">
        Sayfa birkaç saniyede açılmazsa yukarıdaki düğmeye dokunun.
      </p>
    </div>
  );
}
