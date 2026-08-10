'use client';

import { useCallback, useEffect, useState } from 'react';

/**
 * The product gallery — a large main stage plus thumbnails, and a full-screen
 * lightbox on click.
 *
 * A CLIENT COMPONENT for the switching AND the zoom. The images are the
 * server-rendered URLs from Catalog; this adds the interactive bits — which one is
 * large, and a click-to-enlarge overlay — without pulling the page off the server.
 */
export function ProductGallery({
  images,
  alt,
  discount,
}: {
  images: string[];
  alt: string;
  discount?: number | null;
}) {
  const [active, setActive] = useState(0);
  const [zoomed, setZoomed] = useState(false);

  const show = useCallback(
    (i: number) => setActive(((i % images.length) + images.length) % images.length),
    [images.length],
  );

  // Arrow keys + Esc drive the lightbox once it is open.
  useEffect(() => {
    if (!zoomed) return;

    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setZoomed(false);
      if (e.key === 'ArrowRight') show(active + 1);
      if (e.key === 'ArrowLeft') show(active - 1);
    }
    window.addEventListener('keydown', onKey);
    // Don't let the page scroll behind the overlay.
    document.body.style.overflow = 'hidden';

    return () => {
      window.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [zoomed, active, show]);

  if (images.length === 0) {
    return (
      <div className="grid aspect-square place-items-center rounded-2xl border border-ink-100 bg-white text-ink-400 dark:border-ink-800 dark:bg-ink-50">
        görsel yok
      </div>
    );
  }

  return (
    <>
      <div className="flex gap-3">
        {images.length > 1 && (
          <div className="flex flex-col gap-2.5">
            {images.map((src, i) => (
              <button
                key={src + i}
                type="button"
                onClick={() => setActive(i)}
                aria-label={`Görsel ${i + 1}`}
                className={`grid h-[72px] w-[72px] place-items-center overflow-hidden rounded-xl border-2 bg-white p-1.5 transition dark:bg-ink-50 ${
                  i === active ? 'border-brand-500' : 'border-ink-100 hover:border-ink-300 dark:border-ink-800'
                }`}
              >
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src={src} alt="" className="max-h-full w-auto object-contain" loading="lazy" />
              </button>
            ))}
          </div>
        )}

        <button
          type="button"
          onClick={() => setZoomed(true)}
          aria-label="Görseli büyüt"
          className="group relative grid aspect-square flex-1 cursor-zoom-in place-items-center overflow-hidden rounded-2xl border border-ink-100 bg-white p-3 dark:border-ink-800 dark:bg-ink-50"
        >
          {discount ? (
            <span className="absolute left-4 top-4 z-10 rounded-lg bg-red-500 px-2.5 py-1 text-[.8rem] font-extrabold text-white">
              %{discount} indirim
            </span>
          ) : null}

          {/* eslint-disable-next-line @next/next/no-img-element */}
          <img
            src={images[active]}
            alt={alt}
            className="h-full w-full object-contain transition duration-300 group-hover:scale-105"
          />

          {/* zoom affordance */}
          <span className="absolute bottom-3 right-3 grid h-9 w-9 place-items-center rounded-full bg-white/85 text-ink-600 shadow-sm ring-1 ring-ink-200 backdrop-blur transition group-hover:bg-white dark:bg-ink-900/85 dark:text-ink-200 dark:ring-ink-700">
            <svg viewBox="0 0 24 24" className="h-4.5 w-4.5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
              <circle cx="11" cy="11" r="7" />
              <path d="m21 21-4.3-4.3M11 8v6M8 11h6" />
            </svg>
          </span>
        </button>
      </div>

      {zoomed && (
        <Lightbox
          images={images}
          active={active}
          alt={alt}
          onClose={() => setZoomed(false)}
          onShow={show}
        />
      )}
    </>
  );
}

/** The full-screen overlay — the image at its real size, with prev/next and a close. */
function Lightbox({
  images,
  active,
  alt,
  onClose,
  onShow,
}: {
  images: string[];
  active: number;
  alt: string;
  onClose: () => void;
  onShow: (i: number) => void;
}) {
  return (
    <div
      className="fixed inset-0 z-[100] flex items-center justify-center bg-black/85 p-4 sm:p-8"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-label="Ürün görseli"
    >
      <button
        type="button"
        onClick={onClose}
        aria-label="Kapat"
        className="absolute right-4 top-4 grid h-11 w-11 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20"
      >
        <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round">
          <path d="M18 6 6 18M6 6l12 12" />
        </svg>
      </button>

      {images.length > 1 && (
        <>
          <ArrowButton side="left" onClick={(e) => { e.stopPropagation(); onShow(active - 1); }} />
          <ArrowButton side="right" onClick={(e) => { e.stopPropagation(); onShow(active + 1); }} />
          <span className="absolute bottom-5 left-1/2 -translate-x-1/2 rounded-full bg-white/10 px-3 py-1 text-sm font-semibold text-white">
            {active + 1} / {images.length}
          </span>
        </>
      )}

      {/* Stop the image click from closing; the backdrop closes. */}
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={images[active]}
        alt={alt}
        onClick={(e) => e.stopPropagation()}
        className="max-h-[90vh] max-w-[92vw] cursor-default object-contain"
      />
    </div>
  );
}

function ArrowButton({
  side,
  onClick,
}: {
  side: 'left' | 'right';
  onClick: (e: React.MouseEvent) => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={side === 'left' ? 'Önceki görsel' : 'Sonraki görsel'}
      className={`absolute top-1/2 grid h-12 w-12 -translate-y-1/2 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20 ${
        side === 'left' ? 'left-3 sm:left-6' : 'right-3 sm:right-6'
      }`}
    >
      <svg viewBox="0 0 24 24" className="h-7 w-7" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        {side === 'left' ? <path d="m15 6-6 6 6 6" /> : <path d="m9 6 6 6-6 6" />}
      </svg>
    </button>
  );
}
