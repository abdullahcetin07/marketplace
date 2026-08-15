import Link from 'next/link';
import { getEarnPreview } from '@/lib/api';

/**
 * The product page's "Kampanyalar" area (Loyalty, ADR-082). One eye-catching card:
 * how many points buying this product earns.
 *
 * THE NUMBER COMES FROM THE SERVER, computed from the featured price and the operator
 * earn rate — the storefront never multiplies a price string (005 §28). It streams in a
 * <Suspense> so the fetch never blocks the page, and it renders NOTHING until the
 * earn-preview endpoint ships or if loyalty is off, so the section is safe ahead of the
 * backend. Built to grow: more campaign rows can join this card later.
 */
export async function ProductCampaigns({ amount }: { amount: string; currency: string }) {
  const preview = await getEarnPreview(amount);

  if (preview === null || !preview.enabled || preview.points <= 0) return null;

  return (
    <section className="my-6">
      <h2 className="mb-2.5 text-[.82rem] font-extrabold uppercase tracking-wide text-ink-400">
        Kampanyalar
      </h2>

      <div className="relative overflow-hidden rounded-2xl border border-brand-200 bg-gradient-to-br from-brand-50 to-white p-4 dark:border-brand-500/30 dark:from-brand-500/10 dark:to-ink-900">
        {/* a soft decorative glow, purely visual */}
        <span
          aria-hidden="true"
          className="pointer-events-none absolute -right-6 -top-8 h-28 w-28 rounded-full bg-brand-400/20 blur-2xl"
        />

        <div className="relative flex items-center gap-3.5">
          <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand-500 text-white shadow-sm">
            <GiftIcon />
          </span>

          <div className="min-w-0">
            <p className="text-[.95rem] font-extrabold leading-tight text-ink-800 dark:text-ink-100">
              Bu ürünü alınca{' '}
              <span className="text-brand-600 dark:text-brand-300">
                {preview.points.toLocaleString('tr-TR')} puan
              </span>{' '}
              kazan
            </p>
            <p className="mt-0.5 text-[.78rem] text-ink-500">
              Puanlarını sonraki alışverişinde indirim olarak kullanabilirsin.{' '}
              <Link href="/hesap/puanlarim" className="font-bold text-brand-600 underline underline-offset-2 hover:text-brand-700">
                Nasıl çalışır?
              </Link>
            </p>
          </div>
        </div>
      </div>
    </section>
  );
}

function GiftIcon() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={1.9} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7S12 3.5 9.5 3.5 7 6 7 7h5zM12 7s0-3.5 2.5-3.5S17 6 17 7h-5z" />
    </svg>
  );
}
