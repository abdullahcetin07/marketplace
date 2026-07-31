import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getProduct, getProductOffers } from '@/lib/api';
import { formatMoney } from '@/lib/money';

type Props = { params: Promise<{ id: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { id } = await params;
  const product = await getProduct(id);

  if (product === null) return { title: 'Ürün bulunamadı' };

  return {
    title: product.title,
    description: product.description ?? undefined,
  };
}

/**
 * The product page (§2.2) — the composed read at its clearest.
 *
 * TWO CALLS, AND THEY ANSWER DIFFERENT QUESTIONS. Catalog says what the thing is:
 * title, description, gallery, attributes, variants, breadcrumb. Offer says who
 * sells it and for how much. The page exists because a shopper needs both; the
 * platform keeps them apart because one catalogue entry is sold by many merchants
 * (ADR-037).
 *
 * "SATIŞTA YOK" IS A RENDERED STATE, NOT A 404. A buyer arrives here from a
 * bookmark or a search engine long after the last seller ran out; the page is
 * real, and telling them plainly that nobody has it beats a dead link (the API
 * makes the same distinction — the detail route is published-only, not
 * sellable-only).
 *
 * ADD-TO-CART IS DELIBERATELY ABSENT FROM THIS SLICE. The cart is a
 * session-carrying write and lands with its own work; a button that looked live
 * and did nothing would be worse than no button.
 */
export default async function ProductPage({ params }: Props) {
  const { id } = await params;

  const [product, offers] = await Promise.all([getProduct(id), getProductOffers(id)]);

  if (product === null) notFound();

  const featured = offers?.featured ?? null;

  return (
    <article className="flex flex-col gap-8">
      <nav aria-label="Kategori yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/urunler" className="hover:text-brand-600">
          Tüm ürünler
        </Link>
        {product.category.path.map((node) => (
          <span key={node.id}>
            <span className="px-1">/</span>
            <Link href={`/urunler?category=${node.id}`} className="hover:text-brand-600">
              {node.name}
            </Link>
          </span>
        ))}
      </nav>

      <div className="grid gap-8 lg:grid-cols-2">
        <section className="flex flex-col gap-3">
          <div className="aspect-square overflow-hidden rounded-xl bg-ink-50 dark:bg-ink-900">
            {product.images.length === 0 ? (
              <div className="flex h-full items-center justify-center text-ink-400">görsel yok</div>
            ) : (
              // eslint-disable-next-line @next/next/no-img-element
              <img
                src={product.images[0]}
                alt={product.title}
                className="h-full w-full object-cover"
              />
            )}
          </div>

          {product.images.length > 1 && (
            <div className="grid grid-cols-5 gap-2">
              {product.images.slice(1).map((url) => (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  key={url}
                  src={url}
                  alt=""
                  className="aspect-square w-full rounded-lg object-cover"
                  loading="lazy"
                />
              ))}
            </div>
          )}
        </section>

        <section className="flex flex-col gap-6">
          <div>
            {product.brand !== null && (
              <span className="text-sm font-medium uppercase tracking-wide text-ink-500">
                {product.brand.name}
              </span>
            )}
            <h1 className="text-2xl font-bold leading-tight sm:text-3xl">{product.title}</h1>
          </div>

          {/* THE BUY BOX (ADR-045) — computed by the API, rendered here. The
              winner is never recomputed on the client: two surfaces disagreeing
              about who is cheapest is the one failure that would make every price
              on the site untrustworthy. */}
          <div className="rounded-xl border border-ink-200 p-5 dark:border-ink-800">
            {featured === null ? (
              <div className="flex flex-col gap-1">
                <span className="text-lg font-semibold">Şu an satışta yok</span>
                <span className="text-sm text-ink-500">
                  Bu ürünü şu anda satan bir mağaza bulunmuyor.
                </span>
              </div>
            ) : (
              <div className="flex flex-col gap-3">
                <div className="flex items-baseline gap-3">
                  <span className="text-3xl font-bold text-brand-600">
                    {formatMoney(featured.price, featured.currency)}
                  </span>
                  {featured.list_price !== null && (
                    <span className="text-lg text-ink-400 line-through">
                      {formatMoney(featured.list_price, featured.currency)}
                    </span>
                  )}
                </div>

                {featured.store?.name != null && (
                  <span className="text-sm text-ink-500">
                    Satıcı: <span className="font-medium text-ink-700 dark:text-ink-200">{featured.store.name}</span>
                  </span>
                )}

                {offers !== null && offers.offer_count > 1 && (
                  <span className="text-sm text-ink-500">
                    Bu ürünü satan {offers.offer_count} mağaza var.
                  </span>
                )}
              </div>
            )}
          </div>

          {product.variants.length > 1 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
                Seçenekler
              </h2>
              <ul className="flex flex-wrap gap-2">
                {product.variants.map((variant) => (
                  <li
                    key={variant.id}
                    className="rounded-lg border border-ink-300 px-3 py-1.5 text-sm dark:border-ink-700"
                  >
                    {variant.label}
                  </li>
                ))}
              </ul>
            </section>
          )}

          {product.attributes.length > 0 && (
            <section className="flex flex-col gap-2">
              <h2 className="text-sm font-semibold uppercase tracking-wide text-ink-500">
                Ürün özellikleri
              </h2>
              <dl className="divide-y divide-ink-200 text-sm dark:divide-ink-800">
                {product.attributes.map((attribute) => (
                  <div key={attribute.name} className="flex justify-between gap-4 py-2">
                    <dt className="text-ink-500">{attribute.name}</dt>
                    <dd className="text-right font-medium">{attribute.value}</dd>
                  </div>
                ))}
              </dl>
            </section>
          )}
        </section>
      </div>

      {product.description !== null && product.description !== '' && (
        <section className="flex flex-col gap-2">
          <h2 className="text-lg font-bold">Açıklama</h2>
          <p className="whitespace-pre-line leading-relaxed text-ink-700 dark:text-ink-200">
            {product.description}
          </p>
        </section>
      )}

      {offers !== null && offers.other_sellers.length > 0 && (
        <section className="flex flex-col gap-3">
          <h2 className="text-lg font-bold">Diğer satıcılar</h2>
          <ul className="divide-y divide-ink-200 rounded-xl border border-ink-200 dark:divide-ink-800 dark:border-ink-800">
            {offers.other_sellers.map((offer) => (
              <li key={offer.id} className="flex items-center justify-between gap-4 p-4">
                <span className="text-sm">{offer.store?.name ?? 'Mağaza'}</span>
                <span className="font-semibold">{formatMoney(offer.price, offer.currency)}</span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </article>
  );
}
