import { Suspense } from 'react';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { AddToCartButton } from '@/components/AddToCartButton';
import { ProductGallery } from '@/components/ProductGallery';
import { ProductQuestions } from '@/components/ProductQuestions';
import { ProductRailSkeleton } from '@/components/ProductRailSkeleton';
import { ProductReviews } from '@/components/ProductReviews';
import { AlsoBought } from '@/components/AlsoBought';
import { RelatedProducts } from '@/components/RelatedProducts';
import { Stars } from '@/components/Stars';
import { TrackRecentView } from '@/components/TrackRecentView';
import { getProduct, getProductOffers, getProductQuestions, getProductReviews } from '@/lib/api';
import { formatMoney } from '@/lib/money';
import { absoluteUrl } from '@/lib/site';

/**
 * The product page body (§2.2) — the composed read at its clearest, now addressed
 * by a flat slug (ADR-059).
 *
 * TWO CALLS, AND THEY ANSWER DIFFERENT QUESTIONS. Catalog says what the thing is;
 * Offer says who sells it and for how much. The page exists because a shopper needs
 * both; the platform keeps them apart because one catalogue entry is sold by many
 * merchants (ADR-037).
 *
 * "SATIŞTA YOK" IS A RENDERED STATE, NOT A 404. A buyer arrives here long after the
 * last seller ran out; the page is real, and telling them plainly beats a dead link.
 *
 * JSON-LD IS EMITTED FOR THE CRAWLER (§SEO). Product + BreadcrumbList, built from the
 * same data the page renders — the price rides through as the decimal string it
 * already is, so no `Number(price)` sneaks in through the structured data either.
 */
export async function ProductView({ idOrSlug }: { idOrSlug: string }) {
  const product = await getProduct(idOrSlug);

  if (product === null) notFound();

  // Offers are keyed by the product's uuid — fetch them with the resolved id, not
  // the incoming slug. (The offers route only resolves the uuid; passing a slug is
  // the uuid-cast 500. Using the id is also just the correct key.) Reviews ride
  // along in the same round of fetches, and degrade to empty rather than break the
  // page if they fail.
  const [offers, reviews, questions] = await Promise.all([
    getProductOffers(product.id),
    getProductReviews(product.id),
    getProductQuestions(product.id),
  ]);

  const featured = offers?.featured ?? null;

  // Google flags a Product Offer with no priceValidUntil as an incomplete
  // merchant listing. A live buy-box price can be re-priced by the seller at any
  // time, so 30 days out is an honest ceiling rather than a promise — a date, not
  // money, so the "never parse price" rule below does not apply to it.
  const priceValidUntil = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10);

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Ana Sayfa', url: absoluteUrl('/') },
      ...product.category.path.map((node) => ({ name: node.name, url: absoluteUrl(`/${node.slug}`) })),
      { name: product.title, url: absoluteUrl(`/${product.slug}`) },
    ].map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.url,
    })),
  };

  const productLd = {
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: product.title,
    description: product.description ?? undefined,
    image: product.images.length > 0 ? product.images : undefined,
    sku: product.id,
    gtin: product.gtin ?? undefined,
    brand: product.brand ? { '@type': 'Brand', name: product.brand.name } : undefined,
    // The average is the decimal string the API sent — schema.org accepts it as-is,
    // and it never becomes a float on the way through.
    aggregateRating:
      reviews.summary.count > 0
        ? {
            '@type': 'AggregateRating',
            ratingValue: reviews.summary.average,
            reviewCount: reviews.summary.count,
          }
        : undefined,
    // Price stays the decimal string the API sent — schema.org accepts it as-is.
    offers:
      featured === null
        ? undefined
        : {
            '@type': 'Offer',
            price: featured.price,
            priceCurrency: featured.currency,
            availability: featured.in_stock
              ? 'https://schema.org/InStock'
              : 'https://schema.org/OutOfStock',
            // The catalogue is admin-built and sold new by onaylı satıcılar, so the
            // condition is not a per-offer field the seller sets — it is always new.
            itemCondition: 'https://schema.org/NewCondition',
            priceValidUntil,
            url: absoluteUrl(`/${product.slug}`),
            // The buy-box winner IS the seller of record for this price — name it so
            // the listing attributes the offer to the merchant, not the platform.
            ...(featured.store?.name
              ? { seller: { '@type': 'Organization', name: featured.store.name } }
              : {}),
            // The platform-wide 14-day return the page already promises ("14 gün
            // iade"), expressed for the merchant-listing so Google stops flagging it
            // as missing. Free return, by mail, Türkiye.
            hasMerchantReturnPolicy: {
              '@type': 'MerchantReturnPolicy',
              applicableCountry: 'TR',
              returnPolicyCategory: 'https://schema.org/MerchantReturnFiniteReturnWindow',
              merchantReturnDays: 14,
              returnMethod: 'https://schema.org/ReturnByMail',
              returnFees: 'https://schema.org/FreeReturn',
            },
          },
  };

  return (
    <div className="flex flex-col gap-8 pb-4">
      {/* record this view for the homepage "Son baktıkların" + recommendations (client, localStorage) */}
      <TrackRecentView
        product={{
          id: product.id,
          slug: product.slug,
          title: product.title,
          image: product.images[0] ?? null,
          category: { id: product.category.id, name: product.category.name, slug: product.category.slug },
          brand: product.brand
            ? { id: product.brand.id, name: product.brand.name, slug: product.brand.slug }
            : null,
        }}
      />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(productLd) }} />

      <nav aria-label="Kategori yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        {product.category.path.map((node) => (
          <span key={node.id}>
            <span className="px-1">/</span>
            <Link href={`/${node.slug}`} className="hover:text-brand-600">{node.name}</Link>
          </span>
        ))}
      </nav>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_1.2fr] xl:grid-cols-[minmax(0,1fr)_1.4fr_250px]">
        <ProductGallery
          images={product.images}
          largeImages={product.images_large}
          thumbImages={product.images_thumb}
          alt={product.title}
        />

        <section className="flex flex-col">
          {product.brand !== null && (
            <Link href={`/${product.brand.slug}`} className="text-[.82rem] font-extrabold text-brand-600 hover:underline">
              {product.brand.name}
            </Link>
          )}
          <h1 className="mt-1.5 text-2xl font-extrabold leading-tight tracking-tight text-balance">{product.title}</h1>

          {/* Rating + Q&A — the Trendyol-style trust row, linking to the sections below. */}
          <div className="mt-2.5 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            {reviews.summary.count > 0 ? (
              <a href="#degerlendirmeler" className="group flex items-center gap-1.5">
                <span className="font-extrabold">{reviews.summary.average}</span>
                <Stars value={reviews.summary.average} className="text-[.95rem]" />
                <span className="text-ink-500 group-hover:text-brand-600 group-hover:underline">
                  {reviews.summary.count} Değerlendirme
                </span>
              </a>
            ) : (
              <span className="flex items-center gap-1.5 text-ink-400">
                <Stars value={0} className="text-[.95rem]" /> Henüz değerlendirilmemiş
              </span>
            )}

            <span className="h-3.5 w-px bg-ink-200 dark:bg-ink-700" />
            <a href="#sorular" className="text-ink-500 hover:text-brand-600 hover:underline">
              <span className="font-bold text-ink-700 dark:text-ink-200">{questions.total}</span> Soru-Cevap
            </a>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-ink-500">
            {offers !== null && offers.offer_count > 0 && <span>{offers.offer_count} satıcı satışta</span>}
            <span className="h-3.5 w-px bg-ink-200 dark:bg-ink-700" />
            <span className="font-bold text-green-600">✓ Orijinal ürün</span>
          </div>

          {(product.attributes.length > 0 || product.gtin) && (
            <dl className="my-6 grid grid-cols-1 gap-x-6 gap-y-3 rounded-2xl bg-ink-50 p-5 text-sm dark:bg-ink-900 sm:grid-cols-2">
              {product.brand !== null && (
                <div className="flex justify-between gap-3">
                  <dt className="text-ink-500">Marka</dt>
                  <dd className="text-right font-bold">{product.brand.name}</dd>
                </div>
              )}
              {product.attributes.map((a) => (
                <div key={a.name} className="flex justify-between gap-3">
                  <dt className="text-ink-500">{a.name}</dt>
                  <dd className="text-right font-bold">{a.value}</dd>
                </div>
              ))}
              {product.gtin && (
                <div className="flex justify-between gap-3">
                  <dt className="text-ink-500">Barkod</dt>
                  <dd className="text-right font-bold tabular-nums">{product.gtin}</dd>
                </div>
              )}
            </dl>
          )}

          {product.variants.length > 1 && (
            <div className="mt-1">
              <div className="mb-2 text-[.8rem] font-bold text-ink-500">Seçenekler</div>
              <div className="flex flex-wrap gap-2.5">
                {product.variants.map((v) => (
                  <span key={v.id} className="rounded-xl border-2 border-ink-200 px-4 py-2.5 text-sm font-bold text-ink-600 dark:border-ink-700 dark:text-ink-300">
                    {v.label}
                  </span>
                ))}
              </div>
            </div>
          )}

          {product.description !== null && product.description !== '' && (
            <p className="mt-6 max-w-[60ch] whitespace-pre-line text-[.9rem] leading-relaxed text-ink-600 dark:text-ink-300">
              {product.description}
            </p>
          )}
        </section>

        <aside className="flex flex-col gap-3.5 xl:sticky xl:top-[130px]">
          <div className="relative rounded-2xl border-2 border-brand-500 bg-white p-4 shadow-sm dark:bg-ink-900">
            {featured === null ? (
              <div className="flex flex-col gap-1">
                <span className="text-lg font-extrabold">Şu an satışta yok</span>
                <span className="text-sm text-ink-500">Bu ürünü şu anda satan bir mağaza bulunmuyor.</span>
              </div>
            ) : (
              <>
                <span className="absolute -top-[11px] left-4 rounded-full bg-brand-500 px-2.5 py-0.5 text-[.7rem] font-extrabold uppercase tracking-wide text-white">
                  En uygun satıcı
                </span>
                <div className="mb-3 flex items-center gap-2.5">
                  <div className="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-[.9rem] font-extrabold text-brand-700 dark:bg-brand-500/15">
                    {initials(featured.store?.name ?? 'Mağaza')}
                  </div>
                  <div>
                    <StoreLabel store={featured.store} className="text-[.92rem] font-extrabold" />
                    <div className="text-[.76rem] text-ink-500">
                      {featured.store?.city ? `${featured.store.city} · ` : ''}Onaylı satıcı
                    </div>
                  </div>
                </div>
                <div className="flex items-baseline gap-2.5">
                  <span className="text-[1.7rem] font-extrabold tracking-tight text-brand-600">
                    {formatMoney(featured.price, featured.currency)}
                  </span>
                  {featured.list_price !== null && (
                    <span className="text-sm text-ink-400 line-through">
                      {formatMoney(featured.list_price, featured.currency)}
                    </span>
                  )}
                </div>
                <div className="mb-4 mt-1.5 flex items-center gap-1.5 text-[.82rem] font-extrabold text-green-600">
                  <span className="h-2 w-2 rounded-full bg-green-500" /> Stokta var · KDV dahil
                </div>
                <div className="flex flex-col gap-2.5">
                  <AddToCartButton offerId={featured.id} variant="primary" label="Sepete Ekle" />
                  <AddToCartButton offerId={featured.id} variant="secondary" label="Şimdi Al" redirectOnAdd="/sepet" />
                </div>

                {featured.store?.slug && (
                  <Link
                    href={`/magaza/${featured.store.slug}`}
                    className="mt-3 flex items-center justify-center gap-1.5 rounded-xl border-2 border-ink-200 py-2.5 text-sm font-bold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700 dark:text-ink-300"
                  >
                    Mağazayı ziyaret et →
                  </Link>
                )}

                <div className="mt-4 flex flex-col gap-2 border-t border-ink-100 pt-3.5 text-[.8rem] text-ink-500 dark:border-ink-800">
                  <span className="flex items-center gap-2">🚚 Hızlı ve güvenli kargo</span>
                  <span className="flex items-center gap-2">🛡️ Orijinal ürün &amp; 14 gün iade</span>
                </div>
              </>
            )}
          </div>

          {offers !== null && offers.other_sellers.length > 0 && (
            <div className="overflow-hidden rounded-2xl border border-ink-100 bg-white dark:border-ink-800 dark:bg-ink-900">
              <h2 className="flex items-center justify-between border-b border-ink-100 px-4 py-3.5 text-[.92rem] font-extrabold dark:border-ink-800">
                Diğer satıcılar
                <span className="text-[.76rem] font-semibold text-ink-500">{offers.offer_count} satıcı</span>
              </h2>
              <ul>
                {offers.other_sellers.map((offer) => (
                  <li key={offer.id} className="flex items-center gap-2.5 border-b border-ink-100 px-4 py-3 last:border-none dark:border-ink-800">
                    <div className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-[9px] bg-ink-50 text-[.74rem] font-extrabold text-ink-500 dark:bg-ink-800">
                      {initials(offer.store?.name ?? 'Mağaza')}
                    </div>
                    <div className="min-w-0 flex-1">
                      <StoreLabel store={offer.store} className="block truncate text-[.85rem] font-bold" />
                      <span className="text-[.98rem] font-extrabold text-brand-600">
                        {formatMoney(offer.price, offer.currency)}
                      </span>
                    </div>
                    <AddToCartButton offerId={offer.id} variant="icon" />
                  </li>
                ))}
              </ul>
            </div>
          )}
        </aside>
      </div>

      <ProductReviews productId={product.id} initial={reviews} />

      <ProductQuestions productId={product.id} initial={questions} />

      {/* Related products — same category, then same brand. Each hides if empty.
          "Bu ürünü alanlar bunları da aldı" needs order co-purchase data the API
          doesn't expose yet; it slots between these two once a backend endpoint exists. */}
      {/* The related-product rails each make their own backend calls; streaming them
          behind Suspense means the product itself (gallery, buy box, info) renders
          immediately instead of waiting on ~9 secondary requests — the first-open
          latency fix. Each rail fills in below as it resolves. */}
      <Suspense fallback={<ProductRailSkeleton />}>
        <RelatedProducts
          title="Benzer Ürünler"
          query={{ category: product.category.slug }}
          excludeId={product.id}
          href={`/${product.category.slug}`}
        />
      </Suspense>
      {/* Hidden until the backend also-bought endpoint returns co-purchase data;
          appears on its own once sales accumulate (ADR-077). */}
      <Suspense fallback={null}>
        <AlsoBought productId={product.id} />
      </Suspense>
      {product.brand !== null && (
        <Suspense fallback={<ProductRailSkeleton />}>
          <RelatedProducts
            title="Önerilen Ürünler"
            query={{ brand: product.brand.slug }}
            excludeId={product.id}
            href={`/${product.brand.slug}`}
          />
        </Suspense>
      )}
    </div>
  );
}

function initials(name: string): string {
  return name.trim().slice(0, 2).toUpperCase();
}

/**
 * A seller's name — a link to its storefront when the offer carries the store
 * slug, plain text otherwise.
 *
 * The slug is optional on the offer (api.ts): a merchant page is slug-addressed
 * and the buy box may not have it yet, so this degrades to a label rather than a
 * dead link. "Mağaza" is the fallback when even the name is missing.
 */
function StoreLabel({
  store,
  className,
}: {
  store: { name: string | null; slug?: string | null } | null;
  className?: string;
}) {
  const name = store?.name ?? 'Mağaza';

  if (store?.slug) {
    return (
      <Link href={`/magaza/${store.slug}`} className={`${className ?? ''} hover:text-brand-600 hover:underline`}>
        {name}
      </Link>
    );
  }

  return <span className={className}>{name}</span>;
}
