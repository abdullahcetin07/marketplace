'use client';

import { useEffect } from 'react';

/**
 * The PayTR payment step (ADR-060).
 *
 * THE CARD LIVES IN PAYTR'S IFRAME, NEVER HERE. Everything a shopper types — card
 * number, CVV, the 3-D Secure step — happens inside `paytr.com`'s own frame; this
 * app only ever holds the token that opens it. That is the entire reason the iFrame
 * integration was chosen: the storefront cannot leak a card it never touches.
 *
 * PAYTR REDIRECTS THE TOP WINDOW when the payment resolves — to the success or fail
 * URL the backend handed it (`/odeme/sonuc`, `/odeme/hata`). So this component only
 * has to render the frame; the navigation away from it is PayTR's to make.
 *
 * `iframeResizer` is PayTR's own script; it keeps the frame tall enough for the 3DS
 * page inside it, which would otherwise be clipped.
 */
declare global {
  interface Window {
    iFrameResize?: (options: Record<string, unknown>, target: string) => void;
  }
}

export function PayTrCheckout({ token }: { token: string }) {
  useEffect(() => {
    const script = document.createElement('script');
    script.src = 'https://www.paytr.com/js/iframeResizer.min.js';
    script.async = true;
    script.onload = () => {
      window.iFrameResize?.({}, '#paytriframe');
    };
    document.body.appendChild(script);

    return () => {
      script.remove();
    };
  }, []);

  return (
    <iframe
      src={`https://www.paytr.com/odeme/guest/${token}`}
      id="paytriframe"
      title="PayTR ödeme"
      frameBorder={0}
      scrolling="no"
      style={{ width: '100%', minHeight: '600px' }}
    />
  );
}
