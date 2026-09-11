'use client';

import { useEffect } from 'react';

/**
 * Google Customer Reviews — post-purchase opt-in survey (merchant 128781549).
 *
 * Rendered ONLY on the confirmed-paid result page, with the real order id, the
 * buyer's e-mail, delivery country and an estimated delivery date. The customer
 * chooses whether to opt in from the card Google renders; opting in later powers
 * the seller-rating badge, a trust signal Google Merchant Center looks for.
 *
 * platform.js calls the global `window.renderOptIn` once it loads; we define that
 * global first, then inject the script. On a client-side re-navigation where gapi
 * is already present, we call render directly instead of re-injecting.
 */

const MERCHANT_ID = 128781549;

type SurveyOptIn = { render: (opts: Record<string, unknown>) => void };
type Gapi = { load: (module: string, cb: () => void) => void; surveyoptin?: SurveyOptIn };

declare global {
  interface Window {
    renderOptIn?: () => void;
    gapi?: Gapi;
  }
}

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

const SCRIPT_ID = 'google-customer-reviews-platform';

export function GoogleCustomerReviews({
  orderId,
  email,
  deliveryCountry = 'TR',
  estimatedDeliveryDate,
}: Props) {
  useEffect(() => {
    if (!orderId || !email) return;

    window.renderOptIn = function renderOptIn() {
      window.gapi?.load('surveyoptin', function surveyLoaded() {
        window.gapi?.surveyoptin?.render({
          merchant_id: MERCHANT_ID,
          order_id: orderId,
          email,
          delivery_country: deliveryCountry,
          estimated_delivery_date: estimatedDeliveryDate,
        });
      });
    };

    // gapi already on the page (SPA navigation) → render now; otherwise load platform.js,
    // which calls window.renderOptIn via its ?onload= param.
    if (window.gapi?.load) {
      window.renderOptIn();

      return;
    }

    if (document.getElementById(SCRIPT_ID) === null) {
      const script = document.createElement('script');
      script.id = SCRIPT_ID;
      script.src = 'https://apis.google.com/js/platform.js?onload=renderOptIn';
      script.async = true;
      script.defer = true;
      document.body.appendChild(script);
    }
  }, [orderId, email, deliveryCountry, estimatedDeliveryDate]);

  return null;
}
