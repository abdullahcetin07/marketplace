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
