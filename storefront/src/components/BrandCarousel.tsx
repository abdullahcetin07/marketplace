'use client';

import Link from 'next/link';
import { useEffect, useRef } from 'react';
import type { Brand } from '@/lib/api';

/**
 * The homepage brand strip: auto-slides on its own AND lets a shopper drive it.
 *
 * The track holds the brands TWICE and a rAF loop nudges `scrollLeft` a hair each
 * frame, wrapping at the half-way point so the loop is seamless. Because the motion
 * is real scroll (not a CSS transform), the prev/next arrows and a touch swipe move
 * the same axis — the auto-scroll just pauses briefly after a manual nudge or while
 * the pointer is over the strip, and honours `prefers-reduced-motion` by holding
 * still (the arrows still work).
 */
export function BrandCarousel({ brands }: { brands: Brand[] }) {
  const ref = useRef<HTMLDivElement>(null);
  const pausedUntil = useRef(0);
  const hovering = useRef(false);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    let raf = 0;
    const step = () => {
      const half = el.scrollWidth / 2;
      if (half > 0 && !hovering.current && Date.now() > pausedUntil.current) {
        el.scrollLeft += 0.5;
        if (el.scrollLeft >= half) el.scrollLeft -= half;
      }
      raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, []);

  const nudge = (dir: 1 | -1) => {
    const el = ref.current;
    if (!el) return;
    pausedUntil.current = Date.now() + 3500;
    el.scrollBy({ left: dir * Math.min(el.clientWidth * 0.7, 640), behavior: 'smooth' });
  };

  const list = [...brands, ...brands];

  return (
    <div
      className="group relative -mx-4"
      onMouseEnter={() => (hovering.current = true)}
      onMouseLeave={() => (hovering.current = false)}
    >
      <div
        ref={ref}
        className="flex gap-1 overflow-x-auto px-4 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
      >
        {list.map((brand, index) => (
          <Link
            key={`${brand.id}-${index}`}
            href={`/${brand.slug}`}
            aria-hidden={index >= brands.length}
            tabIndex={index >= brands.length ? -1 : undefined}
            className="flex w-[116px] shrink-0 flex-col items-center gap-2 rounded-2xl px-1 py-1.5 transition hover:bg-brand-50 dark:hover:bg-ink-900 sm:w-[124px]"
          >
            <span className="grid h-[72px] w-[72px] place-items-center overflow-hidden rounded-full bg-white shadow-sm ring-1 ring-ink-100 dark:ring-ink-800 sm:h-20 sm:w-20">
              {brand.logo ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={brand.logo} alt={brand.name} className="h-full w-full object-contain p-2" loading="lazy" />
              ) : (
                <span className="text-lg font-extrabold text-brand-600">{brand.name.slice(0, 2).toLocaleUpperCase('tr')}</span>
              )}
            </span>
            <span className="line-clamp-2 text-center text-[.8rem] font-bold text-ink-600 dark:text-ink-300">{brand.name}</span>
          </Link>
        ))}
      </div>

      {/* prev / next — desktop, fade in on hover; the strip is swipeable on touch */}
      <Arrow side="left" onClick={() => nudge(-1)} />
      <Arrow side="right" onClick={() => nudge(1)} />
    </div>
  );
}

function Arrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={side === 'left' ? 'Önceki markalar' : 'Sonraki markalar'}
      className={`absolute top-1/2 hidden h-10 w-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-ink-700 shadow-md ring-1 ring-ink-200 backdrop-blur transition hover:bg-white group-hover:opacity-100 md:grid md:opacity-0 dark:bg-ink-900/90 dark:text-ink-200 dark:ring-ink-700 ${
        side === 'left' ? 'left-1' : 'right-1'
      }`}
    >
      <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        {side === 'left' ? <path d="m15 6-6 6 6 6" /> : <path d="m9 6 6 6-6 6" />}
      </svg>
    </button>
  );
}
