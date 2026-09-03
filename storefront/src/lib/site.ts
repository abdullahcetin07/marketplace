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
