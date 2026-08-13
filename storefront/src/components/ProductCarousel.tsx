'use client';

import Link from 'next/link';
import { useRef } from 'react';
import type { BuyBoxPrices, ProductCard as Card, ProductRatings } from '@/lib/api';
import { ProductCard } from '@/components/ProductCard';

/**
 * A horizontally-scrolling row of product cards — the shared body of the
 * personalized homepage strips ("Son baktıkların", "Sana özel öneriler").
 *
 * Reuses the same `ProductCard` the grids use, so a card looks identical wherever
 * it appears; only the layout (a snap-scrolling track vs a grid) differs. Renders
 * nothing for an empty list, so a caller can mount it unconditionally.
 */
export function ProductCarousel({
  title,
  items,
  prices,
  ratings,
  href,
}: {
  title: string;
  items: Card[];
  prices: BuyBoxPrices;
  ratings: ProductRatings;
  /** Optional "tümünü gör" target — e.g. the category page for a category strip. */
  href?: string;
}) {
  const track = useRef<HTMLDivElement>(null);

  if (items.length === 0) return null;

  const scroll = (dir: 1 | -1) => track.current?.scrollBy({ left: dir * 340, behavior: 'smooth' });

  return (
    <section className="flex flex-col gap-5">
      <div className="flex items-baseline justify-between gap-3">
        <h2 className="text-xl font-extrabold tracking-tight">{title}</h2>
        <div className="flex items-center gap-4">
          {href && (
            <Link href={href} className="whitespace-nowrap text-sm font-bold text-brand-600 hover:underline">
              tümünü gör →
            </Link>
          )}
          <div className="hidden gap-2 sm:flex">
            <Arrow side="left" onClick={() => scroll(-1)} />
            <Arrow side="right" onClick={() => scroll(1)} />
          </div>
        </div>
      </div>
      <div ref={track} className="hide-scroll flex snap-x gap-3.5 overflow-x-auto pb-1">
        {items.map((product) => (
          <div key={product.id} className="w-[160px] shrink-0 snap-start sm:w-[190px]">
            <ProductCard product={product} prices={prices} ratings={ratings} />
          </div>
        ))}
      </div>
    </section>
  );
}

function Arrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={side === 'left' ? 'Geri' : 'İleri'}
      className="grid h-9 w-9 place-items-center rounded-full border border-ink-200 bg-white text-ink-600 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700 dark:bg-ink-900"
    >
      <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        {side === 'left' ? <path d="m15 6-6 6 6 6" /> : <path d="m9 6 6 6-6 6" />}
      </svg>
    </button>
  );
}
