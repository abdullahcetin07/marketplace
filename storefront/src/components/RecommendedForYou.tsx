'use client';

import { useEffect, useState } from 'react';
import type { BuyBoxPrices, ProductCard as Card, ProductRatings } from '@/lib/api';
import { browseProducts, getBuyBoxPrices, getProductRatings } from '@/lib/api';
import { getRecentViews } from '@/lib/recently-viewed';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * "Sana özel öneriler" — products like the ones this browser has been looking at.
 *
 * v1 recommendation, CLIENT-ONLY and honest about it: take the categories of the
 * recently-viewed products, pull a page of each (the API matches the whole
 * subtree), drop what they have already seen, and pick 8 at random. No ML, no
 * backend — a "same aisle, haven't seen it yet" shelf. Hidden entirely when there
 * is no view history to reason from.
 */
export function RecommendedForYou() {
  const [items, setItems] = useState<Card[]>([]);
  const [prices, setPrices] = useState<BuyBoxPrices>({});
  const [ratings, setRatings] = useState<ProductRatings>({});

  useEffect(() => {
    const recent = getRecentViews();
    if (recent.length === 0) return;

    const seen = new Set(recent.map((product) => product.id));
    const categorySlugs = [
      ...new Set(recent.map((product) => product.category.slug).filter((slug): slug is string => Boolean(slug))),
    ].slice(0, 3);
    if (categorySlugs.length === 0) return;

    let cancelled = false;

    void (async () => {
      const pages = await Promise.all(
        categorySlugs.map((slug) =>
          browseProducts({ category: slug, perPage: 16 })
            .then((result) => result.items)
            .catch(() => [] as Card[]),
        ),
      );

      // Same-category products, minus what they've already viewed, deduped.
      const pool: Card[] = [];
      const added = new Set<string>();
      for (const product of pages.flat()) {
        if (seen.has(product.id) || added.has(product.id)) continue;
        added.add(product.id);
        pool.push(product);
      }

      // Fisher–Yates shuffle, then take 8.
      for (let i = pool.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        const tmp = pool[i]!;
        pool[i] = pool[j]!;
        pool[j] = tmp;
      }
      const picked = pool.slice(0, 8);
      if (cancelled || picked.length === 0) return;

      setItems(picked);
      const ids = picked.map((product) => product.id);
      getBuyBoxPrices(ids).then((value) => !cancelled && setPrices(value)).catch(() => {});
      getProductRatings(ids).then((value) => !cancelled && setRatings(value)).catch(() => {});
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  if (items.length === 0) return null;

  return <ProductCarousel title="Sana özel öneriler" items={items} prices={prices} ratings={ratings} />;
}
