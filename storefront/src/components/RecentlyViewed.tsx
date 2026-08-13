'use client';

import { useEffect, useState } from 'react';
import type { BuyBoxPrices, ProductCard as Card, ProductRatings } from '@/lib/api';
import { getBuyBoxPrices, getProductRatings } from '@/lib/api';
import { getRecentViews, RECENT_STRIP_MIN } from '@/lib/recently-viewed';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * "Son baktıkların" — the last 8 products this browser viewed, as a carousel.
 *
 * PERSONALIZED, SO CLIENT-ONLY. It reads localStorage on mount; a first-time
 * visitor has no history, so it renders `children` instead — which is the
 * server-rendered "Yeni eklenenler" grid the homepage passes in. That also means
 * the newest grid is the SSR/first-paint content (good for a crawler and for the
 * no-history case), swapped for the carousel only when a history exists.
 */
export function RecentlyViewed({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<Card[] | null>(null);
  const [prices, setPrices] = useState<BuyBoxPrices>({});
  const [ratings, setRatings] = useState<ProductRatings>({});

  useEffect(() => {
    const recent = getRecentViews().slice(0, 8);
    setItems(recent);
    if (recent.length < RECENT_STRIP_MIN) return;

    const ids = recent.map((product) => product.id);
    getBuyBoxPrices(ids).then(setPrices).catch(() => {});
    getProductRatings(ids).then(setRatings).catch(() => {});
  }, []);

  // Fewer than RECENT_STRIP_MIN views → no own strip (those views ride along in
  // "Sana özel öneriler" instead); the children (newest grid) show here.
  if (items === null || items.length < RECENT_STRIP_MIN) return <>{children}</>;

  return <ProductCarousel title="Son baktıkların" items={items} prices={prices} ratings={ratings} />;
}
