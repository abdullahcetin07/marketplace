'use client';

import { useEffect } from 'react';
import { loadGapi } from '@/lib/googlePlatform';

/**
 * Google Customer Reviews — post-purchase opt-in survey (merchant 128781549).
 *
 * Rendered ONLY on the confirmed-paid result page, with the real order id, the
 * buyer's e-mail, delivery country and an estimated delivery date. The customer
 * chooses whether to opt in from the card Google renders; opting in later powers
 * the seller-rating badge, a trust signal Google Merchant Center looks for.
 */

const MERCHANT_ID = 128781549;

type Props = {
  /** Unique order identifier — the payment uuid (one per checkout group). */
  orderId: string;
  /** Buyer e-mail; Google sends the survey here if they opt in. */
  email: string;
  /** ISO 3166-1 alpha-2, e.g. "TR". */
  deliveryCountry?: string;
  /** YYYY-MM-DD estimated delivery date. */
  estimatedDeliveryDate: string;
};

export function GoogleCustomerReviews({
  orderId,
  email,
  deliveryCountry = 'TR',
  estimatedDeliveryDate,
}: Props) {
  useEffect(() => {
    if (!orderId || !email) return;

    loadGapi(() => {
      window.gapi?.load('surveyoptin', () => {
        window.gapi?.surveyoptin?.render({
          merchant_id: MERCHANT_ID,
          order_id: orderId,
          email,
          delivery_country: deliveryCountry,
          estimated_delivery_date: estimatedDeliveryDate,
        });
      });
    });
  }, [orderId, email, deliveryCountry, estimatedDeliveryDate]);

  return null;
}
