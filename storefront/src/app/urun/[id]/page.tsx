import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { AddToCartButton } from '@/components/AddToCartButton';
import { ProductGallery } from '@/components/ProductGallery';
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

function initials(name: string): string {
  return name.trim().slice(0, 2).toUpperCase();
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
 * real, and telling them plainly that nobody has it beats a dead link.
 *
 * ADD-TO-CART TAKES THE FEATURED OFFER'S ID, not the product's. A shopper buys
 * one seller's listing (ADR-042) and the buy box already chose which — so the
 * button carries that decision rather than re-making it.
 *
 * NO PRICE IS PARSED TO A NUMBER (005 §28). The discount shows as a struck-through
 * list price, not a computed "%", so no `Number(price)` sneaks in for display sugar.
 */
export default async function ProductPage({ params }: Props) {
  const { id } = await params;
  const [product, offers] = await Promise.all([getProduct(id), getProductOffers(id)]);

  if (product === null) notFound();

  const featured = offers?.featured ?? null;

  return (
    <div className="flex flex-col gap-8 pb-4">
      <nav aria-label="Kategori yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/urunler" className="hover:text-brand-600">Tüm ürünler</Link>
        {product.category.path.map((node) => (
          <span key={node.id}>
            <span className="px-1">/</span>
            <Link href={`/urunler?category=${node.id}`} className="hover:text-brand-600">{node.name}</Link>
          </span>
        ))}
      </nav>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,0.9fr)_1fr] xl:grid-cols-[minmax(0,0.85fr)_1fr_340px]">
        {/* gallery */}
        <ProductGallery images={product.images} alt={product.title} />

        {/* info */}
        <section className="flex flex-col">
          {product.brand !== null && (
            <span className="text-[.82rem] font-extrabold text-brand-600">{product.brand.name}</span>
          )}
          <h1 className="mt-1.5 text-2xl font-extrabold leading-tight tracking-tight text-balance">{product.title}</h1>

          <div className="mt-3 flex flex-wrap items-center gap-3 text-sm text-ink-500">
            {offers !== null && offers.offer_count > 0 && <span>{offers.offer_count} satıcı satışta</span>}
            <span className="h-3.5 w-px bg-ink-200 dark:bg-ink-700" />
            <span className="font-bold text-green-600">✓ Orijinal ürün</span>
          </div>

          {product.attributes.length > 0 && (
            <dl className="my-6 grid grid-cols-1 gap-x-6 gap-y-3 rounded-2xl bg-ink-50 p-5 text-sm dark:bg-ink-900 sm:grid-cols-2">
              {product.attributes.map((a) => (
                <div key={a.name} className="flex justify-between gap-3">
                  <dt className="text-ink-500">{a.name}</dt>
                  <dd className="text-right font-bold">{a.value}</dd>
                </div>
              ))}
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

        {/* buy box */}
        <aside className="flex flex-col gap-3.5 xl:sticky xl:top-[130px]">
          {/* THE BUY BOX (ADR-045) — computed by the API, never recomputed on the
              client: two surfaces disagreeing about who is cheapest is the one
              failure that makes every price on the site untrustworthy. */}
          <div className="relative rounded-2xl border-2 border-brand-500 bg-white p-[18px] shadow-sm dark:bg-ink-900">
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
                {featured.store?.name != null && (
                  <div className="mb-3 flex items-center gap-2.5">
                    <div className="grid h-10 w-10 place-items-center rounded-xl bg-brand-50 text-[.9rem] font-extrabold text-brand-700 dark:bg-brand-500/15">
                      {initials(featured.store.name)}
                    </div>
                    <div className="text-[.92rem] font-extrabold">{featured.store.name}</div>
                  </div>
                )}
                <div className="flex items-baseline gap-2.5">
                  <span className="text-[2rem] font-extrabold tracking-tight text-brand-600">
                    {formatMoney(featured.price, featured.currency)}
                  </span>
                  {featured.list_price !== null && (
                    <span className="text-base text-ink-400 line-through">
                      {formatMoney(featured.list_price, featured.currency)}
                    </span>
                  )}
                </div>
                <div className="mb-4 mt-1.5 flex items-center gap-1.5 text-[.82rem] font-extrabold text-green-600">
                  <span className="h-2 w-2 rounded-full bg-green-500" /> Stokta var · KDV dahil
                </div>
                <AddToCartButton offerId={featured.id} />
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
                    <span className="min-w-0 flex-1 truncate text-[.85rem] font-bold">{offer.store?.name ?? 'Mağaza'}</span>
                    <span className="whitespace-nowrap text-[1.02rem] font-extrabold text-brand-600">
                      {formatMoney(offer.price, offer.currency)}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </aside>
      </div>
    </div>
  );
}
