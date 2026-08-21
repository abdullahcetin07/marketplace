'use client';

import { useEffect } from 'react';
import { analyticsAmount, pushDataLayer } from '@/lib/analytics';

/**
 * Fires the GA4 `view_item` event once on mount (ADR-085). Renders nothing — dropped
 * into the (server) product page alongside `TrackRecentView`, it carries the featured
 * offer's price so the report has a `value`.
 *
 * The price is the featured buy-box price as a decimal string; it becomes a number
 * only here, for analytics (see analytics.ts), never for the price the page shows.
 */
export function TrackViewItem({
  id,
  name,
  brand,
  price,
  currency,
}: {
  id: string;
  name: string;
  brand?: string | null;
  price: string;
  currency: string;
}) {
  useEffect(() => {
    const value = analyticsAmount(price);

    pushDataLayer({
      event: 'view_item',
      ecommerce: {
        currency,
        value,
        items: [{ item_id: id, item_name: name, item_brand: brand ?? undefined, price: value }],
      },
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [id]);

  return null;
}
