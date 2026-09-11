'use client';

import { useEffect, useRef } from 'react';
import { loadGapi } from '@/lib/googlePlatform';

/**
 * Google Customer Reviews rating badge (merchant 128781549) — the floating
 * seller-rating seal, shown site-wide. It stays invisible until the store has
 * enough collected reviews to earn a rating, then renders itself.
 *
 * BOTTOM_LEFT on purpose: the Raftabul assistant hub floats bottom-right, so the
 * badge takes the opposite corner and the two never overlap.
 */

const MERCHANT_ID = 128781549;

export function GoogleReviewsBadge() {
  const rendered = useRef(false);

  useEffect(() => {
    if (rendered.current) return;
    rendered.current = true;

    const container = document.createElement('div');
    document.body.appendChild(container);

    loadGapi(() => {
      window.gapi?.load('ratingbadge', () => {
        window.gapi?.ratingbadge?.render(container, {
          merchant_id: MERCHANT_ID,
          position: 'BOTTOM_LEFT',
        });
      });
    });
  }, []);

  return null;
}
