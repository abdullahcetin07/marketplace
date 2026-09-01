'use client';

import { usePathname } from 'next/navigation';
import { useEffect, useRef } from 'react';

/**
 * Meta (Facebook/Instagram) Pixel — consent-gated, wired to the SAME GA4 ecommerce
 * dataLayer the site already pushes (ADR-085), so there is one event source and no
 * duplicate instrumentation.
 *
 * KVKK FIRST. The pixel never loads until the shopper presses "Kabul Et": on mount it
 * checks the stored consent, and it listens for the `raftabul:consent` event the banner
 * dispatches, loading only on `granted`. No env var → it renders nothing, so staging (or
 * a prod without the id) carries no pixel at all.
 *
 * ONE SOURCE OF EVENTS. Once loaded it wraps `dataLayer.push` and forwards the four
 * commerce events to their Meta equivalents (view_item→ViewContent, add_to_cart→AddToCart,
 * begin_checkout→InitiateCheckout, purchase→Purchase) with content_ids/contents/value/
 * currency; Purchase carries the order id as `eventID` so a future Conversions API can
 * de-duplicate browser + server without change here.
 */
const PIXEL_ID = process.env.NEXT_PUBLIC_META_PIXEL_ID;
const CONSENT_KEY = 'raftabul.consent';

const EVENT_MAP: Record<string, string> = {
  view_item: 'ViewContent',
  add_to_cart: 'AddToCart',
  begin_checkout: 'InitiateCheckout',
  purchase: 'Purchase',
};

declare global {
  interface Window {
    fbq?: (...args: unknown[]) => void;
    _fbq?: unknown;
    __raftabulMetaForwarded?: boolean;
  }
}

type EcommerceEvent = {
  event?: unknown;
  ecommerce?: {
    value?: unknown;
    currency?: unknown;
    transaction_id?: unknown;
    items?: Array<{ item_id?: unknown; quantity?: unknown }>;
  };
};

export function MetaPixel() {
  const pathname = usePathname();
  const loaded = useRef(false);
  const firstPath = useRef(true);

  useEffect(() => {
    if (!PIXEL_ID) return;

    const grantedNow = (): boolean => {
      try {
        return window.localStorage.getItem(CONSENT_KEY) === 'granted';
      } catch {
        return false;
      }
    };

    const load = () => {
      if (loaded.current || !PIXEL_ID || typeof window === 'undefined') return;
      loaded.current = true;

      /* eslint-disable */
      // Standard Meta base loader (installs window.fbq).
      (function (f: any, b: any, e: any, v: any, n?: any, t?: any, s?: any) {
        if (f.fbq) return;
        n = f.fbq = function () {
          n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments);
        };
        if (!f._fbq) f._fbq = n;
        n.push = n;
        n.loaded = true;
        n.version = '2.0';
        n.queue = [];
        t = b.createElement(e);
        t.async = true;
        t.src = v;
        s = b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t, s);
      })(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
      /* eslint-enable */

      window.fbq?.('init', PIXEL_ID);
      window.fbq?.('track', 'PageView');
      installForwarder();
    };

    if (grantedNow()) load();

    const onConsent = (event: Event) => {
      const detail = (event as CustomEvent).detail;
      if (detail === 'granted' || grantedNow()) load();
    };
    window.addEventListener('raftabul:consent', onConsent);
    return () => window.removeEventListener('raftabul:consent', onConsent);
  }, []);

  // SPA route changes are their own PageView (the loader fired the first one).
  useEffect(() => {
    if (firstPath.current) {
      firstPath.current = false;
      return;
    }
    if (loaded.current) window.fbq?.('track', 'PageView');
  }, [pathname]);

  return null;
}

/** Wrap dataLayer.push once so commerce events also reach Meta. */
function installForwarder() {
  if (typeof window === 'undefined' || window.__raftabulMetaForwarded) return;
  window.__raftabulMetaForwarded = true;

  const dl = (window.dataLayer = window.dataLayer ?? []);
  const original = dl.push.bind(dl) as (...items: unknown[]) => number;

  dl.push = ((...args: unknown[]): number => {
    const result = original(...args);
    for (const arg of args) forward(arg);
    return result;
  }) as typeof dl.push;
}

function forward(arg: unknown) {
  if (typeof window === 'undefined' || typeof window.fbq !== 'function') return;
  if (arg === null || typeof arg !== 'object') return;

  const obj = arg as EcommerceEvent;
  const name = typeof obj.event === 'string' ? obj.event : '';
  const metaEvent = EVENT_MAP[name];
  if (!metaEvent) return;

  const ec = obj.ecommerce ?? {};
  const items = Array.isArray(ec.items) ? ec.items : [];
  const contentIds = items.map((i) => String(i.item_id));

  const params: Record<string, unknown> = {
    content_type: 'product',
    content_ids: contentIds,
    contents: items.map((i) => ({ id: String(i.item_id), quantity: Number(i.quantity) || 1 })),
    value: typeof ec.value === 'number' ? ec.value : undefined,
    currency: typeof ec.currency === 'string' ? ec.currency : undefined,
  };

  if (name === 'purchase' && ec.transaction_id) {
    window.fbq('track', metaEvent, params, { eventID: String(ec.transaction_id) });
  } else {
    window.fbq('track', metaEvent, params);
  }
}
