import type { ProductCard } from '@/lib/api';

/**
 * "Son baktıkların" — a per-browser, client-only history of viewed products.
 *
 * NO BACKEND. It is personal, low-value and needs no account, so it lives in
 * localStorage rather than behind an endpoint. We store the ProductCard shape so a
 * card can render without a fetch; prices/ratings are read live on the homepage.
 */
const KEY = 'raftabul.recentlyViewed';
const MAX = 12; // keep a few more than the 8 shown, so a re-view still leaves 8

/**
 * How many views it takes for "Son baktıkların" to earn its OWN strip. Below this,
 * a 1–2 card carousel looks empty, so those views are folded into "Sana özel
 * öneriler" instead (RecommendedForYou).
 */
export const RECENT_STRIP_MIN = 3;

export function recordRecentView(product: ProductCard): void {
  if (typeof window === 'undefined') return;
  try {
    const next = [product, ...getRecentViews().filter((p) => p.id !== product.id)].slice(0, MAX);
    window.localStorage.setItem(KEY, JSON.stringify(next));
  } catch {
    // storage disabled or full — a convenience, never a hard failure
  }
}

export function getRecentViews(): ProductCard[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.localStorage.getItem(KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? (parsed as ProductCard[]) : [];
  } catch {
    return [];
  }
}
