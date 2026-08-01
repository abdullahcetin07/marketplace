'use client';

import { useState } from 'react';

/**
 * The product gallery — a main stage plus thumbnails the shopper can switch.
 *
 * A CLIENT COMPONENT ONLY FOR THE SWITCHING. The images themselves are the
 * server-rendered URLs from Catalog; this adds the one interactive bit (which
 * one is large) without pulling the whole page off the server.
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

  if (images.length === 0) {
    return (
      <div className="grid aspect-square place-items-center rounded-2xl border border-ink-100 bg-white text-ink-400 dark:border-ink-800 dark:bg-ink-50">
        görsel yok
      </div>
    );
  }

  return (
    <div className="flex gap-3">
      {images.length > 1 && (
        <div className="flex flex-col gap-2.5">
          {images.map((src, i) => (
            <button
              key={src + i}
              onClick={() => setActive(i)}
              aria-label={`Görsel ${i + 1}`}
              className={`grid h-[60px] w-[60px] place-items-center overflow-hidden rounded-xl border-2 bg-white p-1.5 dark:bg-ink-50 ${
                i === active ? 'border-brand-500' : 'border-ink-100 dark:border-ink-800'
              }`}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img src={src} alt="" className="max-h-full w-auto object-contain mix-blend-multiply" loading="lazy" />
            </button>
          ))}
        </div>
      )}

      <div className="relative grid aspect-square flex-1 place-items-center rounded-2xl border border-ink-100 bg-white p-8 dark:border-ink-800 dark:bg-ink-50">
        {discount ? (
          <span className="absolute left-4 top-4 rounded-lg bg-red-500 px-2.5 py-1 text-[.8rem] font-extrabold text-white">
            %{discount} indirim
          </span>
        ) : null}
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img src={images[active]} alt={alt} className="max-h-full w-auto object-contain mix-blend-multiply" />
      </div>
    </div>
  );
}
