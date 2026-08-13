'use client';

import { useEffect, useState } from 'react';
import type { BuyBoxPrices, ProductCard as Card, ProductRatings } from '@/lib/api';
import { getBuyBoxPrices, getProductRatings } from '@/lib/api';
import { getRecentViews, RECENT_STRIP_MIN } from '@/lib/recently-viewed';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * "Son baktıkların" — the last 8 products this browser viewed, as a carousel.
 *
 * PERSONALIZED, SO CLIENT-ONLY. It reads localStorage on mount and renders nothing
 * until there are at least RECENT_STRIP_MIN views — below that a 1–2 card strip
 * looks empty, and those views ride along in "Sana özel öneriler" instead.
 */
export function RecentlyViewed() {
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

  if (items === null || items.length < RECENT_STRIP_MIN) return null;

  return <ProductCarousel title="Son baktıkların" items={items} prices={prices} ratings={ratings} />;
}
