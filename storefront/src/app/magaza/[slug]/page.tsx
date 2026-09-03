import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getStore, type StoreProduct } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { jsonLd } from '@/lib/jsonld';
import { absoluteUrl } from '@/lib/site';

/**
 * A seller's public storefront (ADR-034/035) — `/magaza/{slug}`.
 *
 * IT IS A COMPOSED READ, like the product page. Store owns the core — name,
 * branding, SEO, contact — and Offer contributes what the shop SELLS under the
 * `products` key of `extensions` (ADR-046). This page renders both without
 * knowing either module: it reads one endpoint that the server already composes.
 *
 * A MISSING OR SUSPENDED STORE IS A 404, not an empty page: the API returns the
 * same 404 for "no such store" and "not live" so existence never leaks, and
 * `getStore` turns that into `notFound()` here.
 *
 * PRICES RIDE THROUGH AS THE DECIMAL STRINGS THEY ARE — `formatMoney`, never
 * `Number()` — the same discipline as every other listing (005 §28).
 */
export const dynamic = 'force-dynamic';

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const store = await getStore(slug);

  if (store === null) return { title: 'Mağaza bulunamadı' };

  // The canonical is ALWAYS built from our own production origin — the backend's
  // `seo.canonical_url` echoes whatever host served it (the test host), so trusting
  // it would canonicalise live pages to staging. `absoluteUrl` uses SITE_URL.
  const ogImage = store.branding.logo ?? store.branding.banner ?? absoluteUrl('/og-default.jpg');

  return {
    title: store.seo.meta_title ?? store.name,
    description: store.seo.meta_description ?? `${store.name} — onaylı satıcının tüm ürünleri.`,
    alternates: { canonical: absoluteUrl(`/magaza/${store.slug}`) },
    robots: store.seo.robots ?? 'index,follow',
    openGraph: {
      title: store.name,
      type: 'website',
      images: [ogImage],
    },
  };
}

export default async function StorePage({ params }: Props) {
  const { slug } = await params;
  const store = await getStore(slug);

  if (store === null) notFound();

  const products = store.extensions.products?.items ?? [];
  const total = store.extensions.products?.total ?? 0;
  const contactEmail = store.contact.email;
  const contactPhone = store.contact.phone;

  const storeUrl = absoluteUrl(`/magaza/${store.slug}`);
  const storeDescription = `${store.name} — onaylı satıcının tüm ürünleri.`;

  // Seller rating rollup (ADR-069): only real when there ARE published reviews —
  // value is null otherwise, and a fabricated "0 stars" is worse than no rating.
  const rating = store.extensions.rating;
  const hasRating = rating != null && rating.count > 0 && rating.value !== null;

  // Organization, NOT Store/LocalBusiness — a marketplace seller has no storefront
  // address, so the physical-place types would be wrong. `@id` anchors the entity
  // so other nodes can reference it.
  const storeLd = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    '@id': `${storeUrl}#organization`,
    name: store.name,
    url: storeUrl,
    description: storeDescription,
    logo: store.branding.logo ?? undefined,
    image: store.branding.logo ?? undefined,
    email: contactEmail ?? undefined,
    telephone: contactPhone ?? undefined,
    ...(hasRating
      ? {
          aggregateRating: {
            '@type': 'AggregateRating',
            ratingValue: rating!.value,
            reviewCount: rating!.count,
          },
        }
      : {}),
  };

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Ana Sayfa', url: absoluteUrl('/') },
      { name: store.name, url: storeUrl },
    ].map((item, index) => ({ '@type': 'ListItem', position: index + 1, name: item.name, item: item.url })),
  };

  // The store's products as an ItemList — parity with the category/brand listing
  // pages (which already emit one), so the crawler reads this seller's catalogue as a
  // structured collection rather than a bare grid. Bare `url`/`name` items, like the
  // listing pages: a crawl hint, not a rich-result claim.
  const itemListLd =
    products.length > 0
      ? {
          '@context': 'https://schema.org',
          '@type': 'ItemList',
          numberOfItems: total,
          itemListElement: products.map((item, index) => ({
            '@type': 'ListItem',
            position: index + 1,
            url: absoluteUrl(item.slug ? `/${item.slug}` : `/urun/${item.product_id}`),
            name: item.title,
          })),
        }
      : null;

  return (
    <div className="flex flex-col gap-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(storeLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(breadcrumbLd) }} />
      {itemListLd && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(itemListLd) }} />
      )}

      <nav aria-label="Sayfa yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        <span className="px-1">/</span>
        <span className="font-semibold text-ink-700 dark:text-ink-200">{store.name}</span>
      </nav>

      {/* Hero — banner with the logo and identity laid over it. */}
      <header className="overflow-hidden rounded-2xl border border-ink-100 bg-white dark:border-ink-800 dark:bg-ink-900">
        <div className="relative h-36 w-full bg-gradient-to-br from-brand-500/15 to-brand-500/5 sm:h-48">
          {store.branding.banner !== null && (
            <img
              src={store.branding.banner}
              alt={`${store.name} kapak görseli`}
              className="absolute inset-0 h-full w-full object-cover"
            />
          )}
        </div>

        <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-end sm:gap-5 sm:p-6">
          <div className="-mt-16 grid h-24 w-24 shrink-0 place-items-center overflow-hidden rounded-2xl border-4 border-white bg-brand-50 text-2xl font-extrabold text-brand-700 shadow-sm dark:border-ink-900 dark:bg-brand-500/15 sm:-mt-20">
            {store.branding.logo !== null ? (
              <img src={store.branding.logo} alt={store.name} className="h-full w-full object-cover" />
            ) : (
              initials(store.name)
            )}
          </div>

          <div className="min-w-0 flex-1">
            <h1 className="text-[1.7rem] font-extrabold leading-tight tracking-tight sm:text-[1.9rem]">
              {store.name}
            </h1>
            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-ink-500">
              <span className="flex items-center gap-1 font-bold text-green-600">✓ Onaylı satıcı</span>
              {hasRating && (
                <>
                  <span className="h-3.5 w-px bg-ink-200 dark:bg-ink-700" />
                  <span className="flex items-center gap-1 font-bold text-ink-700 dark:text-ink-200">
                    <svg viewBox="0 0 20 20" className="h-4 w-4 text-amber-400" fill="currentColor" aria-hidden="true">
                      <path d="M10 1.6l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.2l-4.95 2.6.94-5.5-4-3.9 5.53-.8z" />
                    </svg>
                    {rating!.value}
                    <span className="font-semibold text-ink-400">({rating!.count})</span>
                  </span>
                </>
              )}
              <span className="h-3.5 w-px bg-ink-200 dark:bg-ink-700" />
              <span>
                <span className="font-bold text-ink-700 dark:text-ink-200">{total}</span> ürün
              </span>
            </div>
          </div>

          {(contactEmail !== null || contactPhone !== null) && (
            <dl className="flex flex-col gap-1 text-[.82rem] text-ink-500 sm:text-right">
              {contactPhone !== null && (
                <div className="flex items-center gap-1.5 sm:justify-end">
                  <span>📞</span>
                  <a href={`tel:${contactPhone}`} className="font-bold text-ink-700 hover:text-brand-600 dark:text-ink-200">
                    {contactPhone}
                  </a>
                </div>
              )}
              {contactEmail !== null && (
                <div className="flex items-center gap-1.5 sm:justify-end">
                  <span>✉️</span>
                  <a href={`mailto:${contactEmail}`} className="font-bold text-ink-700 hover:text-brand-600 dark:text-ink-200">
                    {contactEmail}
                  </a>
                </div>
              )}
            </dl>
          )}
        </div>
      </header>

      {/* What the store sells (Offer's contributor, ADR-046). */}
      <section className="flex flex-col gap-4">
        <h2 className="text-lg font-extrabold tracking-tight">Ürünler</h2>

        {products.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-ink-200 bg-white px-6 py-12 text-center dark:border-ink-700 dark:bg-ink-900">
            <p className="text-sm font-semibold text-ink-500">Bu mağaza henüz ürün listelemedi.</p>
          </div>
        ) : (
          <>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
              {products.map((item) => (
                <StoreProductCard key={item.id} product={item} />
              ))}
            </div>
            {total > products.length && (
              <p className="text-center text-[.82rem] text-ink-500">
                En çok satan {products.length} ürün gösteriliyor.
              </p>
            )}
          </>
        )}
      </section>
    </div>
  );
}

/**
 * One product on the store page. Links straight to the canonical `/{slug}` when the
 * contributor supplies it, and otherwise to `/urun/{uuid}`, which 301s to the same
 * place. The image degrades to a placeholder when the payload carries none.
 */
function StoreProductCard({ product }: { product: StoreProduct }) {
  const href = product.slug ? `/${product.slug}` : `/urun/${product.product_id}`;

  return (
    <Link
      href={href}
      className="group flex flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white transition hover:border-brand-300 hover:shadow-sm dark:border-ink-800 dark:bg-ink-900"
    >
      {/* Only render the image frame once the payload carries an image — before the
          backend supplies it, an all-"görsel yok" grid looks worse than the compact
          text card, so the box appears with the data rather than ahead of it. */}
      {product.image && (
        <div className="relative aspect-square overflow-hidden bg-white dark:bg-ink-50">
          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={product.image}
            alt={product.title}
            className="h-full w-full object-contain mix-blend-multiply transition group-hover:scale-[1.04]"
            loading="lazy"
          />
        </div>
      )}

      <div className="flex flex-1 flex-col p-3.5">
        {product.brand !== null && (
          <span className="text-[.72rem] font-extrabold uppercase tracking-wide text-brand-600">{product.brand}</span>
        )}
        <span className="mt-0.5 line-clamp-2 min-h-[2.6em] text-[.9rem] font-bold leading-snug group-hover:text-brand-600">
          {product.title}
        </span>

        <div className="mt-auto pt-3">
        {product.list_price !== null && (
          <span className="mr-1.5 text-[.78rem] text-ink-400 line-through">
            {formatMoney(product.list_price, product.currency)}
          </span>
        )}
        <span className="text-[1.05rem] font-extrabold tracking-tight text-brand-600">
          {formatMoney(product.price, product.currency)}
        </span>
        <div className="mt-1 text-[.74rem] font-bold">
          {product.in_stock ? (
            <span className="text-green-600">✓ Stokta</span>
          ) : (
            <span className="text-ink-400">Tükendi</span>
          )}
        </div>
        </div>
      </div>
    </Link>
  );
}

function initials(name: string): string {
  return name.trim().slice(0, 2).toUpperCase();
}
