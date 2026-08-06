import { permanentRedirect } from 'next/navigation';

/**
 * The English store path — a 301 to the Turkish canonical `/magaza/{slug}`.
 *
 * The backend registers two public segments for a storefront, `store` and
 * `magaza` (config `STORE_PUBLIC_PATH_SEGMENTS`), so both may reach a shopper —
 * a canonical URL the API built, an old link, a hand-typed `/store/…`. The
 * customer-facing storefront has ONE canonical page, `/magaza/{slug}`; this
 * redirects the English alias to it so the two never split link equity, the same
 * discipline the flat-slug catch-all uses for a retired alias (ADR-059).
 *
 * NOTE FOR THE SERVER: nginx must route BOTH `/store/*` and `/magaza/*` to this
 * Next.js app, not to PHP-FPM. See FIX_STOREFRONT_NGINX.md.
 */
export const dynamic = 'force-dynamic';

export default async function StoreAliasPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;

  permanentRedirect(`/magaza/${slug}`);
}
