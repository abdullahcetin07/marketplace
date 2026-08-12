'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';

export type HeroSlide = {
  /** Banner image under /public — e.g. "/kampanyalar/1.jpg". */
  image: string;
  /** Where the banner links (a category/brand/listing/product URL). Omit for a non-clickable banner. */
  href?: string;
  /** Alt text — also the accessible name of the slide. */
  alt: string;
};

/**
 * The homepage hero as a campaign-banner carousel.
 *
 * A CLIENT COMPONENT because the sliding, auto-advance and dots are interactive;
 * the slides themselves are static campaign images (there is no campaigns API), so
 * the list is config, not a fetch. One slide → a plain banner, no controls. Auto-
 * advance pauses on hover and is disabled entirely under `prefers-reduced-motion`.
 */
export function HeroSlider({ slides, intervalMs = 5000 }: { slides: HeroSlide[]; intervalMs?: number }) {
  const count = slides.length;
  const [active, setActive] = useState(0);
  const [paused, setPaused] = useState(false);

  const go = useCallback((i: number) => setActive(((i % count) + count) % count), [count]);

  useEffect(() => {
    if (count < 2 || paused) return;
    if (typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const timer = setInterval(() => setActive((a) => (a + 1) % count), intervalMs);
    return () => clearInterval(timer);
  }, [count, paused, intervalMs]);

  // Touch swipe for mobile.
  const [touchX, setTouchX] = useState<number | null>(null);
  function handleTouchEnd(endX: number | null) {
    if (touchX === null || endX === null) return;
    const dx = endX - touchX;
    if (Math.abs(dx) > 40) go(active + (dx < 0 ? 1 : -1));
    setTouchX(null);
  }

  if (count === 0) return null;

  return (
    <section
      className="relative overflow-hidden rounded-3xl bg-ink-100 dark:bg-ink-900"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onTouchStart={(e) => setTouchX(e.touches[0]?.clientX ?? null)}
      onTouchEnd={(e) => handleTouchEnd(e.changedTouches[0]?.clientX ?? null)}
      aria-roledescription="carousel"
      aria-label="Kampanyalar"
    >
      <div
        className="flex transition-transform duration-500 ease-out"
        style={{ transform: `translateX(-${active * 100}%)` }}
      >
        {slides.map((slide, i) => {
          // eslint-disable-next-line @next/next/no-img-element
          const img = (
            <img
              src={slide.image}
              alt={slide.alt}
              className="aspect-[16/5] w-full object-cover"
              loading={i === 0 ? 'eager' : 'lazy'}
            />
          );
          return (
            <div key={slide.image + i} className="w-full shrink-0" aria-hidden={i !== active}>
              {slide.href ? <Link href={slide.href}>{img}</Link> : img}
            </div>
          );
        })}
      </div>

      {count > 1 && (
        <>
          <SliderArrow side="left" onClick={() => go(active - 1)} />
          <SliderArrow side="right" onClick={() => go(active + 1)} />

          <div className="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2">
            {slides.map((slide, i) => (
              <button
                key={slide.image + i}
                type="button"
                onClick={() => go(i)}
                aria-label={`${i + 1}. kampanya`}
                aria-current={i === active}
                className={`h-2.5 rounded-full transition-all ${
                  i === active ? 'w-7 bg-white' : 'w-2.5 bg-white/55 hover:bg-white/80'
                }`}
              />
            ))}
          </div>
        </>
      )}
    </section>
  );
}

function SliderArrow({ side, onClick }: { side: 'left' | 'right'; onClick: () => void }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={side === 'left' ? 'Önceki kampanya' : 'Sonraki kampanya'}
      className={`absolute top-1/2 grid h-11 w-11 -translate-y-1/2 place-items-center rounded-full bg-white/80 text-ink-700 shadow-sm ring-1 ring-black/5 backdrop-blur transition hover:bg-white ${
        side === 'left' ? 'left-3 sm:left-4' : 'right-3 sm:right-4'
      }`}
    >
      <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round">
        {side === 'left' ? <path d="m15 6-6 6 6 6" /> : <path d="m9 6 6 6-6 6" />}
      </svg>
    </button>
  );
}
