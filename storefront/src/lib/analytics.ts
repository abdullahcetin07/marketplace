/**
 * GA4 ecommerce dataLayer plumbing (GTM-side, ADR-085).
 *
 * THIS IS THE ONE PLACE `Number(price)` IS ALLOWED — and it is analytics, not money.
 * Every amount the API sends is a decimal string, and the "never parse a price" rule
 * (money.ts, 005 §28) protects DISPLAY and accounting from float drift. GA4's
 * ecommerce spec, however, requires `value`/`price` to be NUMERIC, so here — and only
 * here, on the way into the dataLayer — the string is parsed. It never feeds a total
 * a shopper sees or a figure the platform keeps; it feeds a report.
 */

declare global {
  interface Window {
    dataLayer?: unknown[];
    gtag?: (...args: unknown[]) => void;
  }
}

/**
 * Decimal string → number, FOR ANALYTICS ONLY. `"129.90"` → `129.9`. A malformed or
 * missing amount degrades to `0` rather than `NaN`, which GA4 would drop.
 */
export function analyticsAmount(amount: string | null | undefined): number {
  if (amount === null || amount === undefined) return 0;
  const parsed = Number(amount);

  return Number.isFinite(parsed) ? parsed : 0;
}

/**
 * Push one GA4 recommended-event object onto the dataLayer.
 *
 * CLEARS `ecommerce` FIRST, per GA4 guidance: without the reset push, the items of a
 * previous event can bleed into the next one. Guards `window`/`dataLayer` so it is a
 * no-op during SSR and never throws — a broken analytics push must not break a page.
 */
export function pushDataLayer(event: Record<string, unknown>): void {
  if (typeof window === 'undefined') return;

  try {
    window.dataLayer = window.dataLayer ?? [];
    // Reset the previous ecommerce payload so items don't accumulate across events.
    window.dataLayer.push({ ecommerce: null });
    window.dataLayer.push(event);
  } catch {
    // A dataLayer that refuses us costs a report row, never the page.
  }
}
