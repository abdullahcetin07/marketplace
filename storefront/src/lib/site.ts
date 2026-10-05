/**
 * The site's own origin, for the absolute URLs SEO needs.
 *
 * Canonical tags, `sitemap.xml` and JSON-LD all have to name the site in full — a
 * relative `/bioderma` is meaningless to a crawler indexing it. Everything else in
 * this app is same-origin and relative; this is the one place that isn't, so it is
 * the one constant that carries the origin.
 */
export const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? 'https://raftabul.com').replace(
  /\/$/,
  '',
);

/** An absolute URL for a site path (`/bioderma` → `https://…/bioderma`). */
export function absoluteUrl(path: string): string {
  return `${SITE_URL}${path.startsWith('/') ? path : `/${path}`}`;
}

/**
 * The support WhatsApp number, in FULL INTERNATIONAL form with no `+` or spaces —
 * that is what wa.me expects (Turkey → `90` + the 10-digit line, e.g. `905321234567`).
 * Env wins so the owner can change it without a deploy; the constant is the fallback.
 * Any stray punctuation is stripped so `+90 532 …` or `0532 …` still normalise sanely.
 */
export const WHATSAPP_NUMBER = (process.env.NEXT_PUBLIC_WHATSAPP_NUMBER ?? '905347666045').replace(
  /\D/g,
  '',
);

/** Prewritten opener so the chat starts with context, not a blank thread. */
export const WHATSAPP_DEFAULT_MESSAGE = 'Merhaba, Raftabul hakkında bir sorum var.';

/**
 * A wa.me deep link with the opener prefilled — or `null` when no real number is
 * configured (a bare `90` after stripping, or the placeholder), so the floating
 * button renders nothing rather than dialing a broken number.
 */
export function whatsappLink(message: string = WHATSAPP_DEFAULT_MESSAGE): string | null {
  if (WHATSAPP_NUMBER.length < 11 || WHATSAPP_NUMBER.includes('X')) return null;
  return `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`;
}

/**
 * The company's registered address, as it must appear on the legal pages.
 *
 * **IT LIVES HERE BECAUSE IT USED TO LIVE IN SEVEN PLACES.** The site footer carried
 * its own copy and the legal pages carried five more, hand-written in ALL CAPS, so
 * moving office meant finding every one of them — and a copy that gets missed leaves
 * a stale address on a distance-selling contract, which is the one place it has to be
 * right. `lib/site` rather than `lib/pages` so the footer can read it without pulling
 * every legal page's copy into the bundle of every route.
 *
 * **VERBATIM AS THE OWNER SUPPLIED IT** (2026-10-05, moved from Kepez to Muratpaşa).
 * Lower-casing it would read better in the footer and is not a safe tidy-up: in Turkish
 * "SARGINLAR" could be Sargınlar or Sarginlar, and guessing misspells a proper name on
 * a legal page. A readable form needs the owner to spell it, not us to infer it.
 */
export const COMPANY_ADDRESS = 'ETİLER MAH. ADNAN MENDERES BUL. SARGINLAR İŞMERKEZİ NO:55C MURATPAŞA / ANTALYA';
