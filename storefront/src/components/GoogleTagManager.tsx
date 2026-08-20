import Script from 'next/script';

/**
 * Google Tag Manager — the single container the site loads; GA4 and every other tag
 * are configured inside GTM, so adding a tag never needs a code change.
 *
 * ENV-GATED ON PURPOSE. It renders nothing unless `NEXT_PUBLIC_GTM_ID` is set, so the
 * staging build (which leaves it unset) never pollutes analytics with test traffic —
 * only production, where the env carries the real container id, is measured.
 */
const GTM_ID = process.env.NEXT_PUBLIC_GTM_ID;

export function GoogleTagManager() {
  if (!GTM_ID) return null;

  return (
    <>
      <Script id="gtm-init" strategy="afterInteractive">
        {`(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${GTM_ID}');`}
      </Script>
      <noscript>
        <iframe
          src={`https://www.googletagmanager.com/ns.html?id=${GTM_ID}`}
          height="0"
          width="0"
          style={{ display: 'none', visibility: 'hidden' }}
          title="gtm"
        />
      </noscript>
    </>
  );
}
