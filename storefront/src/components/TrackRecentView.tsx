'use client';

import { useEffect } from 'react';
import type { ProductCard } from '@/lib/api';
import { recordRecentView } from '@/lib/recently-viewed';

/**
 * Records a product view into the local "son baktıkların" history. Renders nothing;
 * dropped into the (server) product page, it runs once on mount per product.
 */
export function TrackRecentView({ product }: { product: ProductCard }) {
  useEffect(() => {
    recordRecentView(product);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [product.id]);

  return null;
}
